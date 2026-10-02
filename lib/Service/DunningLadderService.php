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
 * Klasse bekommt nur fertige Grund-Sätze (i. d. R. aus
 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier::memberFacingReason()})
 * übergeben, nie einen rohen ISO-Code.
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
		private IL10N $l10n,
	) {
	}

	// --- Ereignisgetrieben (sofort, Spec §7 "Zahlungsaufforderung ... ereignisgetrieben") ---

	/**
	 * Stufe 0 für GENAU eine Forderung – Rücklastschrift-Trigger (Spec §3.6
	 * Tabelle). Aufrufer entscheidet bereits per
	 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier::shouldTriggerPaymentRequest()},
	 * OB überhaupt getriggert wird – diese Methode kennt keine Rückgabe-Klasse.
	 *
	 * @return array{sent:int,skipped:int,failed:int} Mitglieder, nicht Positionen (wie runDaily())
	 */
	public function triggerPaymentRequest(OpenItem $claim, string $reasonSentence): array {
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
		$reason = $this->l10n->t('Das SEPA-Mandat wurde widerrufen, ein Einzug per Lastschrift ist für diese Position nicht mehr möglich.');
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
	 * @return array<int, list<array{0:OpenItem,1:string}>>
	 */
	private function duePaymentRequestsByMember(string $today): array {
		$leadDays = $this->cycleSettings->prenotificationLeadDays();
		$reason = $this->l10n->t('Für diese Position liegt uns bislang kein Zahlungseingang vor.');

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
	 * @return array<int, list<array{0:OpenItem,1:string}>>
	 */
	private function dueEscalationsByMember(string $today, int $fromStage, int $toStage): array {
		$intervalDays = $this->settings->intervalDays();
		$reason = $toStage === DunningNotice::STAGE_REMINDER
			? $this->l10n->t('Für diese Position liegt uns weiterhin kein Zahlungseingang vor.')
			: $this->l10n->t('Trotz Erinnerung liegt für diese Position noch kein Zahlungseingang vor. Bitte gleichen Sie den Betrag zeitnah aus, andernfalls legen wir den Vorgang dem Vorstand vor.');

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
	 * @param array<int, array{0:OpenItem,1:string}> $itemsWithReasons
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
			$message = $this->buildMessage($stage, $displayName, $email, $pending);
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

	/** @param list<array{0:OpenItem,1:string}> $pending */
	private function buildMessage(int $stage, string $displayName, string $email, array $pending): IMessage {
		// "{organisationsname} statt {vereinsname}" (Spec §3.11 T32) - bewusst
		// abweichend vom sonst in dieser App üblichen Fallback "Ihr Verein"
		// (siehe z. B. ContributionPreNotificationService): die Mahntexte
		// gelten laut Ticket ausdrücklich dem neutraleren Organisationsbegriff.
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$clubName = $clubName !== '' ? $clubName : $this->l10n->t('Ihre Organisation');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.dunningStage' . $stage);
		$template->setSubject($this->stageSubject($stage, $clubName));
		$template->addHeader();
		$template->addHeading($this->stageHeading($stage));
		$template->addBodyText($this->l10n->t('Guten Tag %s,', [$displayName]));
		$template->addBodyText($this->stageIntro($stage, $clubName));
		foreach ($pending as [$item, $reason]) {
			$template->addBodyText($reason);
			$template->addBodyText('– ' . $this->positionLine($item));
		}
		if ($stage === DunningNotice::STAGE_DUNNING) {
			$template->addBodyText($this->l10n->t('Sollte der Betrag weiterhin nicht eingehen, legen wir den Vorgang dem Vorstand vor.'));
		}
		$template->addBodyText($this->l10n->t('Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag – für jede Position liegt ein GiroCode zum Scannen mit Ihrer Banking-App bei.'));
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $displayName]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);

		foreach ($pending as [$item]) {
			$this->attachGiroCode($message, $item, $clubName);
		}
		return $message;
	}

	/** GiroCode ist eine Zusatzhilfe, kein Versandhindernis - fehlt die Kontoeinstellung oder scheitert die Erzeugung, bleibt die Mail trotzdem ohne Anhang versendbar. */
	private function attachGiroCode(IMessage $message, OpenItem $item, string $clubName): void {
		$accountId = $this->debtorAccount->getAccountId();
		if ($accountId === null) {
			return;
		}
		try {
			$account = $this->accounts->find($accountId, Application::BOOK);
		} catch (DoesNotExistException) {
			return;
		}
		if ($account->getIban() === null) {
			return;
		}
		try {
			$png = $this->qrCode->generatePng($clubName, $account->getIban(), null, $item->getAmountCents(), $this->positionLine($item));
		} catch (\Throwable) {
			return;
		}
		$attachment = $this->mailer->createAttachment($png, 'girocode-' . $item->getId() . '.png', 'image/png');
		$message->attach($attachment);
	}

	private function stageSubject(int $stage, string $clubName): string {
		return match ($stage) {
			DunningNotice::STAGE_PAYMENT_REQUEST => $this->l10n->t('Zahlungsaufforderung von %s', [$clubName]),
			DunningNotice::STAGE_REMINDER => $this->l10n->t('Zahlungserinnerung von %s', [$clubName]),
			default => $this->l10n->t('Mahnung von %s', [$clubName]),
		};
	}

	private function stageHeading(int $stage): string {
		return match ($stage) {
			DunningNotice::STAGE_PAYMENT_REQUEST => $this->l10n->t('Zahlungsaufforderung'),
			DunningNotice::STAGE_REMINDER => $this->l10n->t('Zahlungserinnerung'),
			default => $this->l10n->t('Mahnung'),
		};
	}

	private function stageIntro(int $stage, string $clubName): string {
		return match ($stage) {
			DunningNotice::STAGE_PAYMENT_REQUEST => $this->l10n->t('für die folgende(n) Position(en) bitten wir Sie um Ausgleich per Überweisung:'),
			DunningNotice::STAGE_REMINDER => $this->l10n->t('wir möchten Sie an die folgende(n) noch offene(n) Position(en) erinnern:'),
			default => $this->l10n->t('für die folgende(n) Position(en) bitten wir Sie dringend um umgehenden Ausgleich:'),
		};
	}

	private function positionLine(OpenItem $item): string {
		$amount = number_format($item->getAmountCents() / 100, 2, ',', '.') . ' €';
		$label = (string)($item->getDescription() ?? $this->l10n->t('Beitrag'));
		if ($item->getPeriodStart() !== null && $item->getPeriodEnd() !== null) {
			return $this->l10n->t('%1$s (%2$s – %3$s): %4$s, fällig %5$s', [$label, (string)$item->getPeriodStart(), (string)$item->getPeriodEnd(), $amount, (string)$item->getDueDate()]);
		}
		return $this->l10n->t('%1$s: %2$s, fällig %3$s', [$label, $amount, (string)$item->getDueDate()]);
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
