<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;

/**
 * Die Mahntreppe (Spec §2.2 „Mahnversand (Dunning Notice)"/§3.6/§3.11 T32/§7,
 * Issue #73): drei automatische Stufen, gebündelt je Mitglied, danach nur
 * noch eine Aufgabe (siehe {@see DunningTaskService::findBoardEscalationTasks()}).
 *
 * **Zwei Auslösewege für Stufe 0** (Spec §3.6 Tabelle „Zahlungsaufforderung"):
 * - **ereignisgetrieben, sofort**: {@see triggerPaymentRequest()} (Rücklastschrift,
 *   aufgerufen von {@see \OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService})
 *   und {@see onMandateRevoked()} (Widerruf mit offenen Forderungen) – NUR für
 *   Forderungen, die grundsätzlich per Lastschrift eingezogen werden sollten
 *   ({@see DirectDebitEligibilityResolver::isEligible()}).
 * - **täglicher Cron, mit Vorabinfo-Vorlauf**: {@see runDaily()} erkennt
 *   Forderungen, die NIE per Lastschrift eingezogen werden
 *   (`!isEligible()` – Überweiser-Zuweisungen UND mandatlose manuelle
 *   Forderungen gleichermaßen, siehe dortige Klassendoc „Überweiser bekommen
 *   nie … Vorabinfo") und für die es deshalb kein Rücklastschrift-Ereignis
 *   geben kann. Der Vorlauf ist bewusst derselbe wie
 *   {@see ContributionCycleSettings::prenotificationLeadDays()}: ein
 *   Überweiser bekommt nie eine separate Vorabinfo-Mail, die
 *   Zahlungsaufforderung übernimmt hier fachlich deren Rolle als erster
 *   Fälligkeits-Hinweis.
 *
 * **Stufe 1/2** entstehen ausschließlich im täglichen Cron
 * ({@see runDaily()}): „Ableitungsformel mit Zusatzbedingung 'nicht aktuell
 * gestundet'" – eine gestundete Forderung fällt komplett aus der
 * Positionsliste, bis die Stundung abläuft; danach läuft die Uhr von der
 * zuletzt erreichten Stufe weiter (kein Reset, siehe {@see dueEscalationsByMember()}:
 * die Frist bemisst sich immer am `sent_at` der zuletzt erreichten Stufe,
 * eine Stundung dazwischen verschiebt diesen Bezugspunkt nicht). Stufe 0 hat
 * laut Spec ausdrücklich KEIN solches Stundungs-Gate („automatik: ja,
 * gatefrei").
 *
 * Mail-Inhalt (Spec §3.11 T32): gebündelt je Mitglied, Einzelüberweisungen
 * (kein Sammelbetrag), eigener Grund-Satz je Position, GiroCode je Position
 * als Anhang ({@see EpcQrCodeGenerator}). Codes bleiben admin-only – diese
 * Klasse bekommt nur Grund-Sätze (i. d. R. aus
 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier::memberFacingReason()})
 * übergeben, nie einen rohen ISO-Code.
 *
 * **Sprache:** die Mail entsteht im Cron oder im Request der Kassenführung,
 * gelesen wird sie vom Mitglied – Du/Sie und Sprache kommen deshalb vom
 * Empfänger ({@see RecipientL10n}). Aus demselben Grund sind die Grund-Sätze
 * keine fertigen Texte, sondern Funktionen `fn (IL10N $l): string`: übersetzt
 * wird erst, wenn der Empfänger feststeht.
 */
class DunningLadderService {

	public function __construct(
		private OpenItemMapper $openItems,
		private MemberMapper $members,
		private DirectDebitEligibilityResolver $eligibility,
		private DunningNoticeMapper $notices,
		private DunningSettings $settings,
		private ContributionCycleSettings $cycleSettings,
		private SepaDebtorAccountService $debtorAccount,
		private AccountMapper $accounts,
		private EpcQrCodeGenerator $qrCode,
		private IUserManager $userManager,
		private IMailer $mailer,
		private IConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private RecipientL10n $recipientL10n,
	) {
	}

	// --- Ereignisgetrieben (sofort, Spec §7 "Zahlungsaufforderung ... ereignisgetrieben") ---

	/**
	 * Stufe 0 für GENAU eine Forderung – Rücklastschrift-Trigger (Spec §3.6
	 * Tabelle). Aufrufer entscheidet bereits per
	 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier::shouldTriggerPaymentRequest()},
	 * OB überhaupt getriggert wird – diese Methode kennt keine Rückgabe-Klasse.
	 *
	 * @param \Closure(IL10N): string $reasonSentence der Grund-Satz, in der Sprache des Empfängers
	 * @return array{sent:int,skipped:int,failed:int} Mitglieder, nicht Positionen (wie runDaily())
	 */
	public function triggerPaymentRequest(OpenItem $claim, \Closure $reasonSentence): array {
		return $this->sendStage($claim->getMemberId(), DunningNotice::STAGE_PAYMENT_REQUEST, [[$claim, $reasonSentence]]);
	}

	/**
	 * Stufe 0 für ALLE aktuell offenen Forderungen eines Mitglieds – Widerruf-
	 * Trigger (Spec §3.6: „Widerruf mit offenen Forderungen"). Bewusst nicht
	 * auf `isEligible()` eingeschränkt: ein Widerruf betrifft grundsätzlich
	 * jede offene Forderung des Mitglieds, nicht nur lastschriftfähige – ein
	 * bereits als Überweiser geführter Nebenposten braucht denselben Hinweis
	 * nicht zweimal, verpufft aber nur still über den Idempotenz-Guard in
	 * {@see sendStage()}, falls er ihn längst hat.
	 *
	 * @return array{sent:int,skipped:int,failed:int}
	 */
	public function onMandateRevoked(int $memberId): array {
		$reason = static fn (IL10N $l): string => $l->t('Das SEPA-Mandat wurde widerrufen, ein Einzug per Lastschrift ist für diese Position nicht mehr möglich.');
		$items = [];
		foreach ($this->openItems->findByMember($memberId) as $item) {
			if (!$item->isClaim() || ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			$items[] = [$item, $reason];
		}
		if ($items === []) {
			return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
		}
		return $this->sendStage($memberId, DunningNotice::STAGE_PAYMENT_REQUEST, $items);
	}

	// --- Täglicher Cron (Spec §7 "Mahnstufen (1/2) | täglich") -----------------------

	/**
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return array{sent:int,skipped:int,failed:int} Mitglieder-Versände über alle drei Stufen hinweg
	 */
	public function runDaily(?string $today = null): array {
		$today ??= $this->today();
		$result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($this->duePaymentRequestsByMember($today) as $memberId => $items) {
			$this->addResult($result, $this->sendStage($memberId, DunningNotice::STAGE_PAYMENT_REQUEST, $items));
		}
		foreach ($this->dueEscalationsByMember($today, DunningNotice::STAGE_PAYMENT_REQUEST, DunningNotice::STAGE_REMINDER) as $memberId => $items) {
			$this->addResult($result, $this->sendStage($memberId, DunningNotice::STAGE_REMINDER, $items));
		}
		foreach ($this->dueEscalationsByMember($today, DunningNotice::STAGE_REMINDER, DunningNotice::STAGE_DUNNING) as $memberId => $items) {
			$this->addResult($result, $this->sendStage($memberId, DunningNotice::STAGE_DUNNING, $items));
		}
		return $result;
	}

	/**
	 * Stufe-0-Kandidaten für Forderungen, die NIE per Lastschrift eingezogen
	 * werden (siehe Klassendoc) – gruppiert nach Mitglied.
	 *
	 * @return array<int, list<array{0:OpenItem,1:\Closure(IL10N): string}>>
	 */
	private function duePaymentRequestsByMember(string $today): array {
		$leadDays = $this->cycleSettings->prenotificationLeadDays();
		$reason = static fn (IL10N $l): string => $l->t('Für diese Position liegt uns bislang kein Zahlungseingang vor.');

		$byMember = [];
		foreach ($this->openItems->findClaims() as $item) {
			if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			if ($item->getDueDate() === null || $this->eligibility->isEligible($item)) {
				continue;
			}
			if ($this->notices->findByOpenItemAndStage((int)$item->getId(), DunningNotice::STAGE_PAYMENT_REQUEST) !== null) {
				continue;
			}
			$deadline = (new \DateTimeImmutable((string)$item->getDueDate()))->modify('-' . $leadDays . ' days')->format('Y-m-d');
			if ($today < $deadline) {
				continue;
			}
			$byMember[$item->getMemberId()][] = [$item, $reason];
		}
		return $byMember;
	}

	/**
	 * Eskalations-Kandidaten von `$fromStage` nach `$toStage` – „nicht aktuell
	 * gestundet" (Klassendoc) und die Mahnabstand-Frist seit dem `sent_at` der
	 * zuletzt erreichten Stufe verstrichen.
	 *
	 * @return array<int, list<array{0:OpenItem,1:\Closure(IL10N): string}>>
	 */
	private function dueEscalationsByMember(string $today, int $fromStage, int $toStage): array {
		$intervalDays = $this->settings->intervalDays();
		$reason = $toStage === DunningNotice::STAGE_REMINDER
			? static fn (IL10N $l): string => $l->t('Für diese Position liegt uns weiterhin kein Zahlungseingang vor.')
			: static fn (IL10N $l): string => $l->t('Trotz Erinnerung liegt für diese Position noch kein Zahlungseingang vor. Bitte gleichen Sie den Betrag zeitnah aus, andernfalls legen wir den Vorgang dem Vorstand vor.');

		$byMember = [];
		foreach ($this->openItems->findClaims() as $item) {
			if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			// "faellt komplett aus der Positionsliste, bis die Stundungablaeuft" (Klassendoc).
			if ($item->getDeferredUntil() !== null && $item->getDeferredUntil() >= $today) {
				continue;
			}
			if ($this->notices->findByOpenItemAndStage((int)$item->getId(), $toStage) !== null) {
				continue;
			}
			$fromNotice = $this->notices->findByOpenItemAndStage((int)$item->getId(), $fromStage);
			if ($fromNotice === null) {
				continue; // hat die Vorstufe (noch) nie erreicht
			}
			$deadline = (new \DateTimeImmutable($fromNotice->getSentAt()))->modify('+' . $intervalDays . ' days')->format('Y-m-d');
			if ($today < $deadline) {
				continue;
			}
			$byMember[$item->getMemberId()][] = [$item, $reason];
		}
		return $byMember;
	}

	// --- Versand ------------------------------------------------------------------

	/**
	 * @param array<int, array{0:OpenItem,1:\Closure(IL10N): string}> $itemsWithReasons
	 * @return array{sent:int,skipped:int,failed:int} "sent" zählt Mitglieder, nicht Positionen (wie ContributionPreNotificationService::sendDue())
	 */
	private function sendStage(?int $memberId, int $stage, array $itemsWithReasons): array {
		if ($memberId === null || $itemsWithReasons === []) {
			return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
		}
		// Idempotenz-Guard (Spec: "eine Zeile je (Forderung, Stufe)") - greift
		// zusätzlich zu den Filtern der Aufrufer, falls zwei Auslöser (z. B.
		// Rücklastschrift UND derselbe Cron-Tag) dieselbe Stufe treffen wollen.
		$pending = array_values(array_filter(
			$itemsWithReasons,
			fn (array $pair): bool => $this->notices->findByOpenItemAndStage((int)$pair[0]->getId(), $stage) === null,
		));
		if ($pending === []) {
			return ['sent' => 0, 'skipped' => 1, 'failed' => 0];
		}

		$member = $this->members->findOrNull($memberId);
		if ($member === null) {
			return ['sent' => 0, 'skipped' => 1, 'failed' => 0];
		}
		$recipient = $this->resolveRecipient($member);
		if ($recipient === null) {
			return ['sent' => 0, 'skipped' => 1, 'failed' => 0];
		}
		[$email, $displayName] = $recipient;

		try {
			$message = $this->buildMessage($stage, $displayName, $email, $pending, $this->recipientL10n->forMember($member));
			$failedRecipients = $this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning('Mahnwesen: Versand für Mitglied {id} fehlgeschlagen', [
				'app' => Application::APP_ID,
				'id' => $memberId,
				'exception' => $e,
			]);
			return ['sent' => 0, 'skipped' => 0, 'failed' => 1];
		}
		if ($failedRecipients !== []) {
			return ['sent' => 0, 'skipped' => 0, 'failed' => 1];
		}

		$batchReference = uniqid('mahnung-', true);
		$sentAt = $this->now();
		foreach ($pending as [$item]) {
			$notice = new DunningNotice();
			$notice->setOpenItemId((int)$item->getId());
			$notice->setStage($stage);
			$notice->setSentAt($sentAt);
			$notice->setMailBatchReference($batchReference);
			$notice->setCreatedAt($sentAt);
			$this->notices->insert($notice);
		}
		return ['sent' => 1, 'skipped' => 0, 'failed' => 0];
	}

	/** @param list<array{0:OpenItem,1:\Closure(IL10N): string}> $pending */
	private function buildMessage(int $stage, string $displayName, string $email, array $pending, IL10N $l): IMessage {
		// "{organisationsname} statt {vereinsname}" (Spec §3.11 T32) - bewusst
		// abweichend vom sonst in dieser App üblichen Fallback "Ihr Verein"
		// (siehe z. B. ContributionPreNotificationService): die Mahntexte
		// gelten laut Ticket ausdrücklich dem neutraleren Organisationsbegriff.
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$clubName = $clubName !== '' ? $clubName : $l->t('Ihre Organisation');

		// Vor dem Mailtext: ob der Text einen GiroCode verspricht, hängt davon ab,
		// ob für ALLE Positionen einer erzeugt werden konnte.
		$giroCodes = $this->renderGiroCodes($pending, $clubName, $l);

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.dunningStage' . $stage);
		$template->setSubject($this->stageSubject($stage, $clubName, $l));
		$template->addHeader();
		$template->addHeading($this->stageHeading($stage, $l));
		$template->addBodyText($l->t('Guten Tag %s,', [$displayName]));
		$template->addBodyText($this->stageIntro($stage, $l));
		foreach ($pending as [$item, $reason]) {
			$template->addBodyText($reason($l));
			$template->addBodyText('– ' . $this->positionLine($item, $l));
		}
		if ($stage === DunningNotice::STAGE_DUNNING) {
			$template->addBodyText($l->t('Sollte der Betrag weiterhin nicht eingehen, legen wir den Vorgang dem Vorstand vor.'));
		}
		$template->addBodyText(count($giroCodes) === count($pending)
			? $l->t('Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag – für jede Position liegt ein GiroCode zum Scannen mit Ihrer Banking-App bei.')
			: $l->t('Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag.'));
		// Auch die Fußzeile von Nextcloud (der Slogan) in der Sprache des Empfängers.
		$template->addFooter('', $l->getLanguageCode());

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $displayName]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);

		foreach ($giroCodes as $itemId => $png) {
			$message->attach($this->mailer->createAttachment($png, 'girocode-' . $itemId . '.png', 'image/png'));
		}
		return $message;
	}

	/**
	 * GiroCode je Position (PNG-Bilddaten, Schlüssel = ID der Forderung).
	 *
	 * Eine Zusatzhilfe, kein Versandhindernis: ist kein Zahlungskonto mit IBAN
	 * eingestellt, ist das schlicht die Einstellung der Instanz (dann gibt es
	 * keine Codes, ohne Aufhebens). Scheitert dagegen die Erzeugung einer
	 * Position – `gd` oder `chillerlan/php-qrcode` fehlt (`\Error` bzw.
	 * `QRCodeOutputException`), oder der Betrag ist unbrauchbar –, geht die
	 * Mail trotzdem ohne diesen Code raus und der Fehler steht im Log
	 * (Issue #120). Bewusst `\Throwable`: eine fehlende Klasse ist ein `\Error`.
	 *
	 * @param list<array{0:OpenItem,1:\Closure(IL10N): string}> $pending
	 * @return array<int, string>
	 */
	private function renderGiroCodes(array $pending, string $clubName, IL10N $l): array {
		$iban = $this->giroCodeIban();
		if ($iban === null) {
			return [];
		}
		$codes = [];
		foreach ($pending as [$item]) {
			try {
				$codes[(int)$item->getId()] = $this->qrCode->generatePng($clubName, $iban, null, $item->getAmountCents(), $this->positionLine($item, $l));
			} catch (\Throwable $e) {
				$this->logger->warning('Mahnwesen: GiroCode für Forderung {id} konnte nicht erzeugt werden, die Mail geht ohne diesen Anhang raus', [
					'app' => Application::APP_ID,
					'id' => $item->getId(),
					'exception' => $e,
				]);
			}
		}
		return $codes;
	}

	/** IBAN des Zahlungskontos, auf das überwiesen werden soll – `null`, solange keines eingestellt ist. */
	private function giroCodeIban(): ?string {
		$accountId = $this->debtorAccount->getAccountId();
		if ($accountId === null) {
			return null;
		}
		try {
			$iban = $this->accounts->find($accountId, Application::BOOK)->getIban();
		} catch (DoesNotExistException) {
			return null;
		}
		return $iban !== null && trim($iban) !== '' ? $iban : null;
	}

	private function stageSubject(int $stage, string $clubName, IL10N $l): string {
		return match ($stage) {
			DunningNotice::STAGE_PAYMENT_REQUEST => $l->t('Zahlungsaufforderung von %s', [$clubName]),
			DunningNotice::STAGE_REMINDER => $l->t('Zahlungserinnerung von %s', [$clubName]),
			default => $l->t('Mahnung von %s', [$clubName]),
		};
	}

	private function stageHeading(int $stage, IL10N $l): string {
		return match ($stage) {
			DunningNotice::STAGE_PAYMENT_REQUEST => $l->t('Zahlungsaufforderung'),
			DunningNotice::STAGE_REMINDER => $l->t('Zahlungserinnerung'),
			default => $l->t('Mahnung'),
		};
	}

	private function stageIntro(int $stage, IL10N $l): string {
		return match ($stage) {
			DunningNotice::STAGE_PAYMENT_REQUEST => $l->t('für die folgende(n) Position(en) bitten wir Sie um Ausgleich per Überweisung:'),
			DunningNotice::STAGE_REMINDER => $l->t('wir möchten Sie an die folgende(n) noch offene(n) Position(en) erinnern:'),
			default => $l->t('für die folgende(n) Position(en) bitten wir Sie dringend um umgehenden Ausgleich:'),
		};
	}

	private function positionLine(OpenItem $item, IL10N $l): string {
		$amount = number_format($item->getAmountCents() / 100, 2, ',', '.') . ' €';
		$label = (string)($item->getDescription() ?? $l->t('Beitrag'));
		if ($item->getPeriodStart() !== null && $item->getPeriodEnd() !== null) {
			return $l->t('%1$s (%2$s – %3$s): %4$s, fällig %5$s', [$label, GermanDate::format($item->getPeriodStart()), GermanDate::format($item->getPeriodEnd()), $amount, GermanDate::format($item->getDueDate())]);
		}
		return $l->t('%1$s: %2$s, fällig %3$s', [$label, $amount, GermanDate::format($item->getDueDate())]);
	}

	/**
	 * Empfänger analog {@see ContributionPreNotificationService::resolveRecipient()}:
	 * vorrangig die Mitglieds-Mailadresse, ersatzweise das verknüpfte NC-Konto.
	 *
	 * @return array{0:string,1:string}|null [Adresse, Anzeigename]
	 */
	private function resolveRecipient(Member $member): ?array {
		$name = $member->displayName();
		if ($member->getEmail() !== null && $member->getEmail() !== '') {
			return [$member->getEmail(), $name];
		}
		$user = $member->getNcUserId() !== null ? $this->userManager->get($member->getNcUserId()) : null;
		$accountEmail = $user?->getEMailAddress();
		if ($user !== null && $accountEmail !== null && $accountEmail !== '') {
			return [$accountEmail, $user->getDisplayName()];
		}
		return null;
	}

	/**
	 * @param array{sent:int,skipped:int,failed:int} $into
	 * @param array{sent:int,skipped:int,failed:int} $from
	 * @param-out array{sent:int,skipped:int,failed:int} $into
	 */
	private function addResult(array &$into, array $from): void {
		$into['sent'] = $into['sent'] + $from['sent'];
		$into['skipped'] = $into['skipped'] + $from['skipped'];
		$into['failed'] = $into['failed'] + $from['failed'];
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
