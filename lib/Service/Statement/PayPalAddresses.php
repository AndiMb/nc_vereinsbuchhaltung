<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\IL10N;

/**
 * Die PayPal-Adressen eines Geldkontos (Issue #375).
 *
 * PayPal kennt keine IBAN; der CSV-Export nennt nur die E-Mail-Adresse des
 * Kontos, und ein Verein kann mehrere haben. Am Geldkonto stehen sie
 * kommagetrennt in Normalform. Daraus wird je Adresse eine Kennung gebildet,
 * die wie eine Kontonummer in `own_account` der Umsätze landet.
 */
final class PayPalAddresses {

	/** Vorsatz der Kennung – trennt sie von IBANs und Kontonummern. */
	public const PREFIX = 'PAYPAL';

	/**
	 * Bringt die Eingabe in die gespeicherte Form.
	 *
	 * @return string|null null bei leerer Eingabe (= keine PayPal-Adresse)
	 * @throws UserFacingException wenn ein Teil keine Adresse mit genau einem @ ist
	 */
	public static function normalize(?string $input, ?IL10N $l10n = null): ?string {
		if ($input === null) {
			return null;
		}
		$addresses = [];
		foreach (explode(',', $input) as $part) {
			$address = mb_strtolower(trim($part));
			if ($address === '') {
				continue;
			}
			if (!preg_match('/^[^@\s]+@[^@\s]+$/u', $address)) {
				// Ohne IL10N (statische Nutzung, Tests) bleibt die deutsche Quellfassung.
				throw new UserFacingException($l10n !== null
					? $l10n->t('Ungültige PayPal-Adresse: %s', [trim($part)])
					: sprintf('Ungültige PayPal-Adresse: %s', trim($part)));
			}
			$addresses[$address] = true;
		}
		return $addresses === [] ? null : implode(',', array_keys($addresses));
	}

	/**
	 * Die Kennungen aller gespeicherten Adressen.
	 *
	 * @return list<string>
	 */
	public static function keys(?string $stored): array {
		if ($stored === null) {
			return [];
		}
		$keys = [];
		foreach (explode(',', $stored) as $address) {
			$key = self::key($address);
			if ($key !== null) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/** Kennung einer Einzeladresse, auf 40 Zeichen gekürzt wie jedes eigene Konto. */
	public static function key(string $address): ?string {
		$normal = (new RowNormalizer())->normalizeOwnAccount($address);
		return $normal === null ? null : mb_substr(self::PREFIX . $normal, 0, 40);
	}
}
