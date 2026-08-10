<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItem;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaBatchMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;

/**
 * SEPA-Vorankündigung ("Pre-Notification"): mindestens 14 Tage vor dem
 * Einzug muss der Zahler über Betrag und Termin informiert werden (SEPA-
 * Regelwerk). Nur möglich für Mitglieder mit Nextcloud-Konto und hinterlegter
 * E-Mail-Adresse – ein frei benannter Zahler (member_label, z. B. eine
 * Untergliederung ohne eigenes Konto) hat keine Adresse, an die sich schicken
 * ließe, und wird stillschweigend übersprungen (siehe SepaPreNotificationJob).
 */
class SepaNotificationService {

	public function __construct(
		private SepaBatchItemMapper $itemMapper,
		private SepaBatchMapper $batchMapper,
		private SepaMandateMapper $mandateMapper,
		private IUserManager $userManager,
		private IMailer $mailer,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return int Anzahl tatsächlich verschickter Vorankündigungen
	 */
	public function sendDueNotifications(string $targetDate): int {
		$sent = 0;
		foreach ($this->itemMapper->findDueForNotification($targetDate) as $item) {
			if ($this->notify($item)) {
				$sent++;
			}
		}
		return $sent;
	}

	/** @return bool ob tatsächlich eine Mail verschickt wurde */
	private function notify(SepaBatchItem $item): bool {
		$mandate = $this->mandateMapper->find($item->getMandateId());
		if ($mandate->getMemberUid() === null) {
			// Freier Zahlername ohne Nextcloud-Konto: keine E-Mail-Adresse bekannt.
			return false;
		}
		$user = $this->userManager->get($mandate->getMemberUid());
		$email = $user?->getEMailAddress();
		if ($email === null || $email === '') {
			return false;
		}

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
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $user->getDisplayName()]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);

		$failedRecipients = $this->mailer->send($message);
		if ($failedRecipients !== []) {
			return false;
		}

		$item->setNotifiedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$this->itemMapper->update($item);
		return true;
	}
}
