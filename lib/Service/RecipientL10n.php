<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Die Sprache eines Mitglieds-Mails (Spec §1.4/§3.11 „Du/Sie-Konvention", Issue
 * #106): nicht die des Prozesses, der die Mail erzeugt, sondern die des
 * Empfängers.
 *
 * **Warum nicht einfach der injizierte `IL10N`:** er liefert die Sprache des
 * aktuellen Requests. Die Vorabinfo und die Mahntreppe entstehen aber im
 * Cron (dort gilt die Standardsprache der Instanz, ein Nutzer gibt es nicht),
 * eine Zahlungsaufforderung nach einer Rücklastschrift im Request der
 * Kassenführung (dort gilt DEREN Sprache), und der Einmal-Link geht ebenfalls
 * im Namen der Verwaltung raus. Die Mail soll aber in der Sprache lesbar sein,
 * auf die das Mitglied sein Konto gestellt hat.
 *
 * **Du oder Sie:** die Quelltexte der App sind Deutsch in Sie-Form. In
 * Nextcloud ist `de` die informelle Sprache („Du"), `de_DE` die förmliche
 * („Sie") – `l10n/de.json` trägt deshalb die Du-Fassung aller Texte an
 * Mitglieder, `l10n/de_DE.json` ist bewusst leer (also der Quelltext, Sie).
 * Daraus folgt die Regel dieser Klasse:
 *
 * - Mitglied mit verknüpftem NC-Konto → die Sprache des Kontos
 *   ({@see IFactory::getUserLanguage()}): `de` bekommt Du, `de_DE` Sie, `en`
 *   Englisch. Eine Sprache, für die die App kein Bündel hat (z. B. `fr`),
 *   fällt auf Deutsch in Sie-Form zurück – wie die Oberfläche, die dort
 *   ebenfalls die deutschen Quelltexte zeigt.
 * - Mitglied ohne Konto → immer Deutsch in Sie-Form. Es gibt keine Angabe, die
 *   ein Du rechtfertigt, und die Standardsprache der Instanz zählt dafür
 *   ausdrücklich nicht (die ist die der Verwaltung, nicht des Mitglieds).
 *
 * Bewusst über `IFactory::get($app, $lang)` mit einer Sprache, für die es eine
 * Datei gibt, und nicht über den Standard-`IL10N`: fehlt für die gewünschte
 * Sprache die Datei, fällt Nextcloud still auf die Sprache des Requests
 * zurück – genau das, was hier vermieden werden soll. Darum gibt es
 * `l10n/de_DE.json` überhaupt (leer, aber vorhanden).
 *
 * Gilt nur für Texte, deren Empfänger nicht der handelnde Nutzer ist. Die
 * Quittungsmails des Self-Service ({@see SelfServiceReceiptMailer},
 * {@see SelfServiceReceiptMailService}) gehen an das Mitglied, das die Änderung
 * gerade selbst im eigenen Konto vornimmt: dort IST die Sprache des Requests
 * die des Empfängers, und der Text entsteht ohnehin in den Aufrufern.
 */
class RecipientL10n {

	/** Förmliches Deutsch = der Quelltext der App (Sie), siehe Klassendoc. */
	public const SOURCE_FORMAL = 'de_DE';

	/** Informelles Deutsch (Du): `l10n/de.json`. */
	public const SOURCE_INFORMAL = 'de';

	public function __construct(
		private IFactory $factory,
		private IUserManager $userManager,
	) {
	}

	public function forMember(Member $member): IL10N {
		return $this->forNcUser($member->getNcUserId());
	}

	/** @param string|null $ncUserId uid des verknüpften NC-Kontos, `null` = kein Konto */
	public function forNcUser(?string $ncUserId): IL10N {
		return $this->factory->get(Application::APP_ID, $this->languageOf($ncUserId));
	}

	/**
	 * Deutsch in Sie-Form, unabhängig von jedem Request – für Empfänger, die
	 * sicher kein Konto haben.
	 */
	public function formal(): IL10N {
		return $this->factory->get(Application::APP_ID, self::SOURCE_FORMAL);
	}

	/** Der Sprachcode, in dem Mails an dieses Konto geschrieben werden. */
	public function languageOf(?string $ncUserId): string {
		if ($ncUserId === null || $ncUserId === '') {
			return self::SOURCE_FORMAL;
		}
		$user = $this->userManager->get($ncUserId);
		if ($user === null) {
			// Die Verknüpfung zeigt auf ein gelöschtes Konto.
			return self::SOURCE_FORMAL;
		}
		return $this->supportedLanguage($this->factory->getUserLanguage($user));
	}

	/**
	 * Eine Sprache, für die die App ein Bündel hat – sonst Deutsch (Sie).
	 *
	 * Regionale Varianten (`en_GB`) fallen auf die Hauptsprache zurück. Bei
	 * Deutsch bewusst NICHT: `de_AT`/`de_CH` auf `de` zu schieben hieße, ein
	 * unbekanntes Konto zu duzen.
	 */
	private function supportedLanguage(string $language): string {
		if ($this->factory->languageExists(Application::APP_ID, $language)) {
			return $language;
		}
		$primary = explode('_', $language, 2)[0];
		if ($primary !== 'de' && $primary !== $language && $this->factory->languageExists(Application::APP_ID, $primary)) {
			return $primary;
		}
		return self::SOURCE_FORMAL;
	}
}
