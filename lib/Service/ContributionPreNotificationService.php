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
 * Die neue Vorabinfo (Spec §3.5/§3.11 „Vorabinfo-Mail" T31, Issue #70) –
 * arbeitet gegen `Assignment`/`vbh_open_items` (Claim-Modell aus Issue #68),
 * nicht gegen `vbh_membership_fees`/`vbh_sepa_batches` wie das ältere
 * {@see SepaNotificationService}. Beide Systeme laufen nebeneinander, bis ein
 * späteres Ticket den alten Zyklus ablöst (Spec §1.2 Umbau-Härte).
 *
 * Unterschiede zum alten Dienst, alle aus Spec §3.11/T31:
 * - **Ein Rendering-Pfad, gebündelt je Mitglied** statt einer Mail je Posten:
 *   hat ein Mitglied mehrere Forderungen mit Fälligkeit innerhalb der
 *   Vorlauffrist (z. B. Basis- + Sparten-Beitrag), bekommt es EINE Mail mit
 *   einer Positionsliste – auch bei nur einer Position derselbe Rendering-Pfad,
 *   keine Sonderform für den Ein-Posten-Fall.
 * - Positionsliste (Bezeichnung + Periode + Betrag), chronologisch sortiert;
 *   „Frühester Einzug" als eigene Fakten-Zeile; Sperrgrenzen-Hinweis als
 *   eigener Satz; Betreff ohne „SEPA"; Anrede „Guten Tag {Name},".
 * - Empfänger kommt ausschließlich vom Mitglied (Member.email, sonst
 *   NC-Konto) – das neue Mandat (Issue #66) trägt anders als das alte
 *   `SepaMandate` keine eigene Mailadresse mehr.
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
		private IL10N $l10n,
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

		usort($items, static fn (OpenItem $a, OpenItem $b) => ((string)$a->getDueDate()) <=> ((string)$b->getDueDate()));

		/** @var Mandate|null $mandate */
		$mandate = $this->mandates->findLiveByMember($memberId)[0] ?? null;
		$mandateReference = $mandate?->getMandateReference() ?? '';

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$clubName = $clubName !== '' ? $clubName : $this->l10n->t('Ihr Verein');
		$creditorId = $this->config->getAppValue(Application::APP_ID, 'sepa_creditor_id', '');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.contributionPreNotification');
		$template->setSubject($this->l10n->t('Bevorstehender Lastschrifteinzug von %s', [$clubName]));
		$template->addHeader();
		$template->addHeading($this->l10n->t('Bevorstehender Lastschrifteinzug'));
		$template->addBodyText($this->l10n->t('Guten Tag %s,', [$displayName]));
		$template->addBodyText($this->l10n->t('%s wird die folgenden Beträge per Lastschrift von Ihrem Konto einziehen (Mandatsreferenz %s):', [$clubName, $mandateReference]));
		foreach ($items as $item) {
			$template->addBodyText('– ' . $this->positionLine($item));
		}
		$template->addBodyText($this->l10n->t('Frühester Einzug: %s', [(string)$items[0]->getDueDate()]));
		if ($creditorId !== '') {
			$template->addBodyText($this->l10n->t('Gläubiger-Identifikationsnummer: %s', [$creditorId]));
		}
		// Sperrgrenzen-Hinweis als eigener Satz (Spec §3.11/T31) - ab jetzt
		// greift die Wirksamkeitsregel (EffectivityRuleService) fuer diese
		// Positionen scharf.
		$template->addBodyText($this->l10n->t('Betrag und Turnus dieser Positionen stehen ab jetzt fest und lassen sich bis zum Einzug nicht mehr ändern.'));
		$template->addBodyText($this->l10n->t('Bitte sorgen Sie für ausreichende Deckung Ihres Kontos. Bei Fragen wenden Sie sich an die Kassenführung.'));
		$template->addFooter();

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

	private function positionLine(OpenItem $item): string {
		$amount = number_format($item->getAmountCents() / 100, 2, ',', '.') . ' €';
		$label = (string)($item->getDescription() ?? $this->l10n->t('Beitrag'));
		if ($item->getPeriodStart() !== null && $item->getPeriodEnd() !== null) {
			return $this->l10n->t('%1$s (%2$s – %3$s): %4$s, fällig %5$s', [$label, (string)$item->getPeriodStart(), (string)$item->getPeriodEnd(), $amount, (string)$item->getDueDate()]);
		}
		return $this->l10n->t('%1$s: %2$s, fällig %3$s', [$label, $amount, (string)$item->getDueDate()]);
	}

	/**
	 * Empfänger der Vorabinfo (Spec §2.2 Mitglied „email"): vorrangig die
	 * Mitglieds-Mailadresse, ersatzweise das verknüpfte NC-Konto. Anders als
	 * beim alten {@see SepaNotificationService} gibt es hier keine
	 * Mandats-Mailadresse mehr – das neue Mandat (Issue #66) trägt keine.
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
