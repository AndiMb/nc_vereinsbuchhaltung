<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;

/**
 * Quittungsmail „an das Mitglied, immer" (Spec §3.4/§3.11 T33, Issue #75):
 * kein Antragsmodell, also auch keine Bestätigung DURCH den Verein - die Mail
 * ist stattdessen die einzige Kopie einer bereits sofort wirksamen Änderung
 * ("Quittungsmail ist die Kopie" statt einer Ereignisliste in der Oberfläche,
 * Spec §3.4 Pflicht-UI-Elemente).
 *
 * Ein Vorlagen-Skelett mit VIER aus-/einblendbaren Slots (Spec §3.11 T33)
 * statt eigener Fließtexte je Aktion: „was" (Pflicht), „ab wann"/„welcher
 * Einzug betroffen"/„Stellvertretungs-Hinweis" (je optional, `null` blendet
 * den Absatz einfach aus). Der vierte Slot bleibt für den gesamten
 * Mandats-Aktionskatalog (#75) leer: Stellvertretung läuft laut Spec §3.4
 * „Modell A" ausschließlich über die Admin-Akte, nie über den Self-Service-
 * Kanal, den dieser Mailer bedient.
 *
 * Technisches Baumuster identisch zu {@see SepaNotificationService}/
 * {@see MandateActivationService}: `IMailer::createEMailTemplate()` +
 * `IL10N::t()` (Quellsprache Deutsch, Zielbundle en.json, Spec §3.11).
 */
class SelfServiceReceiptMailer {

	public function __construct(
		private IMailer $mailer,
		private IUserManager $userManager,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string $whatText Slot 1 „was" - immer gefüllt
	 * @param string|null $effectiveText Slot 2 „ab wann"
	 * @param string|null $nextDebitText Slot 3 „welcher Einzug betroffen"
	 * @param string|null $onBehalfText Slot 4 Stellvertretungs-Hinweis
	 */
	public function send(
		Member $member,
		string $subject,
		string $whatText,
		?string $effectiveText = null,
		?string $nextDebitText = null,
		?string $onBehalfText = null,
	): void {
		$email = $this->resolveEmail($member);
		if ($email === null) {
			// Kein Empfänger ermittelbar: kein Fehler, der die eigentliche
			// (bereits vollzogene) Aktion rückgängig macht oder blockiert -
			// dieselbe Güterabwägung wie SepaNotificationService::notify().
			return;
		}

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '') ?: $this->l10n->t('Ihr Verein');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.selfServiceReceipt');
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);
		$template->addBodyText($whatText);
		if ($effectiveText !== null) {
			$template->addBodyText($effectiveText);
		}
		if ($nextDebitText !== null) {
			$template->addBodyText($nextDebitText);
		}
		if ($onBehalfText !== null) {
			$template->addBodyText($onBehalfText);
		}
		$template->addBodyText($this->l10n->t('Diese Mail ist die einzige Bestätigung dieser Änderung – "Mein Beitrag" führt dafür keine gesonderte Liste.'));
		$template->addBodyText($this->l10n->t('%s wurde von Ihnen selbst über „Mein Beitrag" veranlasst.', [$clubName]));
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $member->displayName()]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);
		$this->mailer->send($message);
	}

	/**
	 * Vorrang hat die vom Verein gepflegte Mitglieds-Mailadresse, danach das
	 * verknüpfte NC-Konto - dieselbe Priorität wie
	 * {@see MandateActivationService::resolveEmail()}.
	 */
	private function resolveEmail(Member $member): ?string {
		if ($member->getEmail() !== null && $member->getEmail() !== '') {
			return $member->getEmail();
		}
		$ncUserId = $member->getNcUserId();
		if ($ncUserId === null) {
			return null;
		}
		$user = $this->userManager->get($ncUserId);
		$accountEmail = $user?->getEMailAddress();
		return ($user !== null && $accountEmail !== null && $accountEmail !== '') ? $accountEmail : null;
	}
}
