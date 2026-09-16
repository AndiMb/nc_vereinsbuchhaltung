<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;

/**
 * Self-Service-Quittungsmail (Spec §3.11 „Self-Service-Quittungsmail" T33,
 * §3.4 „Quittungsmail an das Mitglied, immer"): EIN Vorlagen-Skelett mit vier
 * aus-/einblendbaren Slots (was/ab wann/welcher Einzug/Stellvertretungs-
 * Hinweis) statt sechs Fließtexten je Aktion – jede Self-Service-Aktion
 * (Betrag, Turnus, künftig Mandat/Kontaktdaten) blendet nur die Slots ein,
 * die für sie gelten.
 *
 * E-Mail-Wechsel (Spec §3.4/T33): eigener Warn-Text an die ALTE Adresse
 * ({@see sendOldAddressWarning()}) – neue Adresse maskiert, Verweis auf den
 * Verein, kein Rücknahme-Link (die Spec verbietet den Link ausdrücklich, weil
 * ein solcher Link selbst zum Angriffsvektor würde, wenn die alte Adresse
 * kompromittiert ist).
 */
class SelfServiceReceiptMailService {

	public function __construct(
		private IMailer $mailer,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string $what Slot „was" – Freitext, z.B. „Ihr Monatsbeitrag wurde
	 *                      von 10,00 € auf 15,00 € geändert."
	 * @param string|null $effectiveFrom Slot „ab wann" (JJJJ-MM-TT), null = ausgeblendet
	 * @param string|null $firstDueDate Slot „welcher Einzug" (JJJJ-MM-TT), null = ausgeblendet
	 * @param string|null $onBehalfNote Slot „Stellvertretung" (Spec §3.4 Modell A), null = ausgeblendet
	 */
	public function sendReceipt(
		Member $member,
		string $recipientEmail,
		string $subject,
		string $what,
		?string $effectiveFrom,
		?string $firstDueDate,
		?string $onBehalfNote = null,
	): void {
		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.selfServiceReceipt');
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);
		$template->addBodyText($this->l10n->t('Guten Tag %s,', [$member->displayName()]));
		$template->addBodyText($what);
		if ($effectiveFrom !== null) {
			$template->addBodyText($this->l10n->t('Wirkt ab: %s', [$effectiveFrom]));
		}
		if ($firstDueDate !== null) {
			$template->addBodyText($this->l10n->t('Voraussichtlich erster betroffener Einzug: %s', [$firstDueDate]));
		}
		if ($onBehalfNote !== null) {
			$template->addBodyText($this->l10n->t('Diese Änderung wurde von der Kassenführung in Ihrem Namen vorgenommen: %s', [$onBehalfNote]));
		}
		$template->addBodyText($this->l10n->t('Diese Mail ist die Bestätigung dieser Änderung – eine Handlung Ihrerseits ist nicht nötig.'));
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$recipientEmail => $member->displayName()]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);
		$this->mailer->send($message);
	}

	/**
	 * Warn-Mail an die ALTE Adresse bei einem E-Mail-Wechsel (Spec §3.4/T33):
	 * bewusst KEIN Rücknahme-Link (ein solcher Link wäre selbst ein
	 * Angriffsvektor, falls die alte Adresse kompromittiert ist) – nur der
	 * Hinweis, sich im Zweifel an den Verein zu wenden.
	 */
	public function sendOldAddressWarning(Member $member, string $oldEmail, string $newEmail): void {
		$clubName = $this->clubName();
		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.selfServiceEmailChangeWarning');
		$template->setSubject($this->l10n->t('Ihre hinterlegte E-Mail-Adresse wurde geändert'));
		$template->addHeader();
		$template->addHeading($this->l10n->t('E-Mail-Adresse geändert'));
		$template->addBodyText($this->l10n->t('Guten Tag %s,', [$member->displayName()]));
		$template->addBodyText($this->l10n->t(
			'die für Ihre Mitgliedschaft bei %1$s hinterlegte E-Mail-Adresse wurde soeben von dieser Adresse auf %2$s geändert.',
			[$clubName, self::maskEmail($newEmail)],
		));
		$template->addBodyText($this->l10n->t('Falls Ihnen diese Änderung nicht bekannt vorkommt, wenden Sie sich bitte direkt an die Kassenführung Ihres Vereins.'));
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$oldEmail => $member->displayName()]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);
		$this->mailer->send($message);
	}

	/**
	 * Maskiert eine E-Mail-Adresse für die Warn-Mail an die alte Adresse
	 * (Spec T33: „neue Adresse maskiert") – erster Buchstabe des lokalen
	 * Teils und der Domain bleiben sichtbar, genug um die neue Adresse grob
	 * wiederzuerkennen, ohne sie vollständig preiszugeben.
	 */
	public static function maskEmail(string $email): string {
		$at = strpos($email, '@');
		if ($at === false) {
			return str_repeat('•', max(strlen($email), 1));
		}
		$local = substr($email, 0, $at);
		$domain = substr($email, $at + 1);
		$maskedLocal = $local === '' ? '' : $local[0] . str_repeat('•', max(strlen($local) - 1, 1));
		$dot = strrpos($domain, '.');
		$maskedDomain = $dot === false
			? ($domain === '' ? '' : $domain[0] . str_repeat('•', max(strlen($domain) - 1, 1)))
			: $domain[0] . str_repeat('•', max($dot - 1, 1)) . substr($domain, $dot);
		return $maskedLocal . '@' . $maskedDomain;
	}

	private function clubName(): string {
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		return $clubName !== '' ? $clubName : $this->l10n->t('Ihr Verein');
	}
}
