<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Die Vorabinfo (Spec §3.5/§3.11 „Vorabinfo-Mail" T31, Issue #70) –
 * arbeitet gegen `Assignment`/`vbh_open_items` (Claim-Modell aus Issue #68).
 * Sie löste die Vorankündigung des flachen Alt-Moduls ab, die mit dem Cutover
 * (Issue #107) entfernt wurde.
 *
 * Unterschiede zur früheren Vorankündigung, alle aus Spec §3.11/T31:
 * - **Ein Rendering-Pfad, gebündelt je Mitglied** statt einer Mail je Posten:
 *   hat ein Mitglied mehrere Forderungen mit Fälligkeit innerhalb der
 *   Vorlauffrist (z. B. Basis- + Sparten-Beitrag), bekommt es EINE Mail mit
 *   einer Positionsliste – auch bei nur einer Position derselbe Rendering-Pfad,
 *   keine Sonderform für den Ein-Posten-Fall.
 * - Positionsliste (Bezeichnung + Periode + Betrag), chronologisch sortiert;
 *   „Frühester Einzug" als eigene Fakten-Zeile; Sperrgrenzen-Hinweis als
 *   eigener Satz; Betreff ohne „SEPA"; Anrede „Guten Tag {Name},".
 * - Empfänger kommt ausschließlich vom Mitglied (Member.email, sonst
 *   NC-Konto) – das Mandat (Issue #66) trägt anders als das frühere
 *   flache Mandat keine eigene Mailadresse.
 *
 * Setzt bei erfolgreichem Versand `prenotified_at` an jeder enthaltenen
 * Forderung – ab dann greift {@see EffectivityRuleService} scharf (vorher gab
 * es nie ein gesetztes `prenotified_at`, siehe dortiger Klassendoc).
 */
class ContributionPreNotificationService {

	public function __construct(
		private OpenItemMapper $openItems,
		private MandateMapper $mandates,
		private MemberMapper $members,
		private DirectDebitEligibilityResolver $eligibility,
		private IUserManager $userManager,
		private IMailer $mailer,
		private IConfig $config,
		private ContributionCycleSettings $settings,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private RecipientL10n $recipientL10n,
	) {
	}

	/**
	 * Verschickt die gebündelte Vorabinfo für alle Forderungen, die innerhalb
	 * der Vorlauffrist fällig werden und noch keine bekommen haben.
	 *
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return array{sent:int, skipped:int, failed:int} Mitglieder, nicht Forderungen
	 */
	public function sendDue(?string $today = null): array {
		$today ??= $this->today();
		$until = (new \DateTimeImmutable($today))->modify('+' . $this->settings->prenotificationLeadDays() . ' days')->format('Y-m-d');

		$byMember = [];
		foreach ($this->openItems->findClaimsAwaitingPrenotification($until) as $item) {
			if ($item->getMemberId() === null || !$this->eligibility->isEligible($item)) {
				continue;
			}
			$byMember[$item->getMemberId()][] = $item;
		}

		$result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($byMember as $memberId => $items) {
			try {
				$result[$this->notifyMember((int)$memberId, $items)]++;
			} catch (\Throwable $e) {
				$result['failed']++;
				$this->logger->warning('Vorabinfo für Mitglied {id} fehlgeschlagen', [
					'app' => Application::APP_ID,
					'id' => $memberId,
					'exception' => $e,
				]);
			}
		}
		return $result;
	}

	/**
	 * @param OpenItem[] $items
	 * @return 'sent'|'skipped'|'failed'
	 */
	private function notifyMember(int $memberId, array $items): string {
		$member = $this->members->findOrNull($memberId);
		if ($member === null) {
			return 'skipped';
		}
		$recipient = $this->resolveRecipient($member);
		if ($recipient === null) {
			// Keine Adresse: kein Vermerk, der naechste Lauf versucht es
			// erneut, solange die Frist noch offen ist. Reisst die Frist
			// trotzdem, meldet ContributionCycleTaskService::findBrokenLeadTimeTasks()
			// eine eskalierende Aufgabe - dieselbe Aufgabe faengt auch echte
			// Zustellfehler ab, eine zweite Markierung dafuer ist nicht noetig.
			return 'skipped';
		}
		[$email, $displayName] = $recipient;
		// Sprache des Empfängers, nicht die des Cron-Laufs (siehe RecipientL10n).
		$l = $this->recipientL10n->forMember($member);

		usort($items, static fn (OpenItem $a, OpenItem $b) => ((string)$a->getDueDate()) <=> ((string)$b->getDueDate()));

		/** @var Mandate|null $mandate */
		$mandate = $this->mandates->findLiveByMember($memberId)[0] ?? null;
		$mandateReference = $mandate?->getMandateReference() ?? '';

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$clubName = $clubName !== '' ? $clubName : $l->t('Ihr Verein');
		$creditorId = $this->config->getAppValue(Application::APP_ID, 'sepa_creditor_id', '');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.contributionPreNotification');
		$template->setSubject($l->t('Bevorstehender Lastschrifteinzug von %s', [$clubName]));
		$template->addHeader();
		$template->addHeading($l->t('Bevorstehender Lastschrifteinzug'));
		$template->addBodyText($l->t('Guten Tag %s,', [$displayName]));
		$template->addBodyText($l->t('%s wird die folgenden Beträge per Lastschrift von Ihrem Konto einziehen (Mandatsreferenz %s):', [$clubName, $mandateReference]));
		foreach ($items as $item) {
			$template->addBodyText('– ' . $this->positionLine($item, $l));
		}
		$template->addBodyText($l->t('Frühester Einzug: %s', [GermanDate::format($items[0]->getDueDate())]));
		if ($creditorId !== '') {
			$template->addBodyText($l->t('Gläubiger-Identifikationsnummer: %s', [$creditorId]));
		}
		// Sperrgrenzen-Hinweis als eigener Satz (Spec §3.11/T31) - ab jetzt
		// greift die Wirksamkeitsregel (EffectivityRuleService) fuer diese
		// Positionen scharf.
		$template->addBodyText($l->t('Betrag und Turnus dieser Positionen stehen ab jetzt fest und lassen sich bis zum Einzug nicht mehr ändern.'));
		$template->addBodyText($l->t('Bitte sorgen Sie für ausreichende Deckung Ihres Kontos. Bei Fragen wenden Sie sich an den Vorstand.'));
		// Auch die Fußzeile von Nextcloud (der Slogan) in der Sprache des Empfängers, nicht des Cron-Laufs.
		$template->addFooter('', $l->getLanguageCode());

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $displayName]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);

		$failedRecipients = $this->mailer->send($message);
		if ($failedRecipients !== []) {
			return 'failed';
		}

		$now = $this->now();
		foreach ($items as $item) {
			$item->setPrenotifiedAt($now);
			$this->openItems->update($item);
		}
		return 'sent';
	}

	private function positionLine(OpenItem $item, IL10N $l): string {
		$amount = number_format($item->getAmountCents() / 100, 2, ',', '.') . ' €';
		$label = (string)($item->getDescription() ?? $l->t('Beitrag'));
		$months = PeriodLabel::months($item->getPeriodStart(), $item->getPeriodEnd(), $l);
		if ($months !== null) {
			return $l->t('%1$s, %2$s: %3$s, fällig %4$s', [$label, $months, $amount, GermanDate::format($item->getDueDate())]);
		}
		if ($item->getPeriodStart() !== null && $item->getPeriodEnd() !== null) {
			return $l->t('%1$s (%2$s – %3$s): %4$s, fällig %5$s', [$label, GermanDate::format($item->getPeriodStart()), GermanDate::format($item->getPeriodEnd()), $amount, GermanDate::format($item->getDueDate())]);
		}
		return $l->t('%1$s: %2$s, fällig %3$s', [$label, $amount, GermanDate::format($item->getDueDate())]);
	}

	/**
	 * Empfänger der Vorabinfo (Spec §2.2 Mitglied „email"): vorrangig die
	 * Mitglieds-Mailadresse, ersatzweise das verknüpfte NC-Konto. Eine
	 * Mandats-Mailadresse gibt es nicht – das Mandat (Issue #66) trägt keine.
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

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
