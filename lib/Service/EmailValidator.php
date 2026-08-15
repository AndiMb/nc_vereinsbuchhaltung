<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Formale E-Mail-Prüfung, tolerant gegenüber Umlauten im lokalen Teil.
 *
 * PHPs FILTER_VALIDATE_EMAIL akzeptiert nur den historischen ASCII-
 * Zeichensatz (RFC 5321). gmx.de, web.de und t-online.de - die meistgenutzten
 * privaten Postfächer hierzulande - liefern Adressen mit Umlaut im lokalen
 * Teil aber tatsächlich zu ("m.müller@gmx.de" ist dort eine echte, zustellbare
 * Adresse), und bei Nachnamen wie „Müller" oder „Krüger" kommt das in einer
 * Vereinstabelle nicht selten, sondern regelmäßig vor. Reine Validierung ohne
 * Nextcloud-Abhängigkeiten, damit sie sich wie {@see IbanValidator} ohne
 * laufende Instanz prüfen lässt (siehe tests/unit/EmailValidatorTest.php).
 */
class EmailValidator {

	/**
	 * @param string|null $email die zu prüfende Adresse
	 * @return bool true, wenn Format und Domain plausibel sind
	 */
	public static function isValid(?string $email): bool {
		if ($email === null || $email === '') {
			return false;
		}
		$at = strrpos($email, '@');
		if ($at === false) {
			return false;
		}
		$local = substr($email, 0, $at);
		$domain = substr($email, $at + 1);
		if ($local === '' || $domain === '') {
			return false;
		}
		if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
			return false;
		}
		// Lokaler Teil: RFC-5322-übliche Sonderzeichen plus Unicode-Buchstaben/
		// -Ziffern (Umlaute, Akzente) statt nur reinem ASCII.
		if (!preg_match('/^[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]+$/u', $local)) {
			return false;
		}
		// Domain-Teil: falls international (z. B. „müller.de" als Domain statt
		// nur lokaler Teil), vor der Formatprüfung in ASCII/Punycode wandeln -
		// FILTER_VALIDATE_EMAIL kennt sonst auch hier nur ASCII.
		$asciiDomain = $domain;
		if (preg_match('/[^\x00-\x7F]/', $domain) && function_exists('idn_to_ascii')) {
			$converted = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			if ($converted !== false) {
				$asciiDomain = $converted;
			}
		}
		// Den lokalen Teil haben wir bereits geprüft - hier interessiert nur,
		// ob "irgendein-lokaler-teil@$asciiDomain" ein plausibles Adressformat
		// ergibt (Domain-Labels, Punkte, TLD).
		return filter_var('a@' . $asciiDomain, FILTER_VALIDATE_EMAIL) !== false;
	}
}
