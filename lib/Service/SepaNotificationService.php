<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItem;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaBatchMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandate;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * SEPA-Vorankündigung ("Pre-Notification"): mindestens 14 Tage vor dem
 * Einzug muss der Zahler über Betrag und Termin informiert werden (SEPA-
 * Regelwerk).
 *
 * Die Adresse kommt vorrangig vom Mandat und ersatzweise vom Nextcloud-Konto
 * (siehe {@see resolveRecipient()}). Anfangs gab es nur den zweiten Weg – und
 * damit erreichte die Pflichtankündigung ausgerechnet die Mehrheit nicht: in
 * einem Chor oder einem Verein mit 200 Mitgliedern hat kaum jemand ein Konto
 * auf diesem Server.
 *
 * Bleibt beides leer, wird die Zeile übersprungen und das vermerkt, damit der
 * Verein sie einmalig sieht und selbst ankündigen kann.
 */
class SepaNotificationService {

	/**
	 * Vorlaufzeit in Tagen. Maßgeblich für die Ankündigung *und* für das
	 * Fälligkeitsdatum, das der Sammeleinzug vorschlägt – die beiden müssen
	 * zusammenpassen, sonst geht die Ankündigung ins Leere.
	 */
	public const LEAD_DAYS = 14;

	/** Werte für notified_state, siehe Migration 000128. */
	public const STATE_SENT = 'sent';
	public const STATE_NO_EMAIL = 'no_email';

	public function __construct(
		private SepaBatchItemMapper $itemMapper,
		private SepaBatchMapper $batchMapper,
		private SepaMandateMapper $mandateMapper,
		private MemberReferenceValidator $memberRef,
		private IUserManager $userManager,
		private IMailer $mailer,
		private IConfig $config,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
	}

	/**
	 * Verschickt die Vorankündigung für alle Einzüge, die innerhalb der
	 * Vorlaufzeit fällig werden und noch keine bekommen haben.
	 *
	 * Bewusst ein Zeitfenster und kein einzelner Stichtag: bei einem exakten
	 * Vergleich auf "heute + 14 Tage" bekäme ein Einzug mit kürzerem Vorlauf
	 * nie eine Ankündigung, und selbst bei genau 14 Tagen genügte ein
	 * übersprungener Tageslauf (der TimedJob hängt am Cron-Takt), damit das
	 * Fenster endgültig zu ist. Lieber eine Ankündigung mit zu wenig Vorlauf
	 * als gar keine – der Zahler soll erfahren, was von seinem Konto abgeht.
	 *
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return array{sent:int, skipped:int, failed:int}
	 */
	public function sendDueNotifications(?string $today = null): array {
		$today ??= (new \DateTime())->format('Y-m-d');
		$until = (new \DateTime($today))->modify('+' . self::LEAD_DAYS . ' days')->format('Y-m-d');

		$zaehler = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($this->itemMapper->findDueForNotification($today, $until) as $item) {
			// Ein Fehler bei einer Zeile darf den Lauf nicht beenden: sonst
			// bleiben alle folgenden Zahler unbenachrichtigt, und weil sie kein
			// notified_at bekommen haben, scheitert der nächste Tageslauf an
			// derselben Zeile erneut.
			try {
				$zaehler[$this->notify($item)]++;
			} catch (\Throwable $e) {
				$zaehler['failed']++;
				$this->logger->warning('SEPA-Vorankündigung für Zeile {id} fehlgeschlagen', [
					'app' => Application::APP_ID,
					'id' => $item->getId(),
					'exception' => $e,
				]);
			}
		}
		return $zaehler;
	}

	/**
	 * @return 'sent'|'skipped'|'failed' verschickt / kein Empfänger bekannt /
	 *                                   Zustellung abgelehnt (wird beim nächsten Lauf erneut versucht)
	 * @throws \Throwable bei Zustell- oder Datenfehlern; der Aufrufer fängt sie je Zeile ab
	 */
	private function notify(SepaBatchItem $item): string {
		$mandate = $this->mandateMapper->find($item->getMandateId());
		$recipient = $this->resolveRecipient($mandate);
		if ($recipient === null) {
			// Weder am Mandat noch am Konto eine Adresse: einmal vermerken, statt
			// die Zeile in jedem Tageslauf erneut anzufassen. Der Vermerk ist
			// zugleich die Information, die der Verein braucht – hier muss er
			// selbst ankündigen.
			$this->mark($item, self::STATE_NO_EMAIL);
			return 'skipped';
		}
		[$email, $displayName] = $recipient;

		$batch = $this->batchMapper->find($item->getBatchId());
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '') ?: $batch->getCreditorName();
		$amount = number_format($item->getAmountCents() / 100, 2, ',', '.') . ' €';

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.sepaPreNotification');
		$template->setSubject($this->l10n->t('SEPA-Lastschriftankündigung von %s', [$clubName]));
		$template->addHeader();
		$template->addHeading($this->l10n->t('Bevorstehender Lastschrifteinzug'));
		$template->addBodyText($this->l10n->t(
			'%1$s wird am %2$s einen Betrag von %3$s per SEPA-Lastschrift von Ihrem Konto einziehen (Mandatsreferenz %4$s).',
			[$clubName, $batch->getExecutionDate(), $amount, $mandate->getMandateReference()]
		));
		// Die Gläubiger-Identifikationsnummer gehört in jede Vorabankündigung:
		// nur mit ihr und der Mandatsreferenz zusammen kann der Zahler die
		// Abbuchung später auf seinem Kontoauszug zuordnen.
		$template->addBodyText($this->l10n->t('Gläubiger-Identifikationsnummer: %s', [$batch->getCreditorId()]));
		$template->addBodyText($this->l10n->t('Bitte sorgen Sie für ausreichende Deckung Ihres Kontos. Bei Fragen wenden Sie sich an die Kassenführung.'));
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $displayName]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);

		$failedRecipients = $this->mailer->send($message);
		if ($failedRecipients !== []) {
			// Kein Vermerk: der nächste Lauf soll es erneut versuchen, solange
			// das Fenster noch offen ist.
			return 'failed';
		}

		$this->mark($item, self::STATE_SENT);
		return 'sent';
	}

	/**
	 * Wohin die Vorankündigung geht.
	 *
	 * Vorrang hat die Adresse am Mandat: sie steht dort, weil jemand sie für
	 * genau diesen Zahler eingetragen hat. Erst danach das Nextcloud-Konto –
	 * das war früher der einzige Weg und schloss damit alle aus, die auf
	 * dieser Instanz kein Konto haben, also in einem Verein mit 200
	 * Mitgliedern nahezu jeden.
	 *
	 * @return array{0:string, 1:string}|null Adresse und Anzeigename, oder null
	 */
	private function resolveRecipient(SepaMandate $mandate): ?array {
		$name = $this->memberRef->displayName($mandate->getMemberUid(), $mandate->getMemberLabel());

		$email = $mandate->getEmail();
		if ($email !== null && $email !== '') {
			return [$email, $name];
		}

		$user = $mandate->getMemberUid() !== null ? $this->userManager->get($mandate->getMemberUid()) : null;
		$accountEmail = $user?->getEMailAddress();
		if ($user !== null && $accountEmail !== null && $accountEmail !== '') {
			return [$accountEmail, $user->getDisplayName()];
		}

		return null;
	}

	private function mark(SepaBatchItem $item, string $state): void {
		$item->setNotifiedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$item->setNotifiedState($state);
		$this->itemMapper->update($item);
	}
}
