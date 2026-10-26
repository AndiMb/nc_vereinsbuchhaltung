<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

/**
 * Die strukturierte Zahlungsreferenz nach ISO 11649 („RF-Referenz", Creditor
 * Reference): `RF`, zwei Prüfziffern und bis zu 21 Zeichen (Ziffern und
 * Großbuchstaben). Die App leitet sie aus der Rechnungsnummer ab, etwa
 * `RE-2026-0042` zu `RF18RE20260042`. Banken reichen die Referenz unverändert
 * durch, der Kontoauszug zeigt sie im Verwendungszweck, und der Bankabgleich
 * erkennt die Rechnung daran sicher.
 */
final class CreditorReference {

	private const MAX_LENGTH = 21;

	private function __construct() {
	}

	/**
	 * Die Referenz zur Rechnungsnummer. Trennzeichen fallen weg, Kleinbuchstaben
	 * werden groß. Null, wenn die Nummer nach dem Bereinigen leer, zu lang oder
	 * nicht rein alphanumerisch (ASCII) ist.
	 */
	public static function fromInvoiceNumber(string $number): ?string {
		$base = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', $number));
		if ($base === '' || strlen($base) > self::MAX_LENGTH) {
			return null;
		}
		// Ein Buchstabe oder eine Ziffer außerhalb von ASCII (Umlaut) würde die Nummer
		// stillschweigend verändern; dann gibt es lieber keine Referenz.
		if (preg_match('/(?![A-Za-z0-9])[\p{L}\p{N}]/u', $number) === 1) {
			return null;
		}
		return 'RF' . self::checkDigits($base) . $base;
	}

	/** Stimmen Aufbau und Prüfziffern? Leerzeichen und Groß-/Kleinschreibung zählen nicht. */
	public static function isValid(string $reference): bool {
		$reference = strtoupper((string)preg_replace('/\s+/', '', $reference));
		if (preg_match('/^RF(\d{2})([0-9A-Z]{1,21})$/', $reference, $m) !== 1) {
			return false;
		}
		return $m[1] === self::checkDigits($m[2]);
	}

	/** In Vierergruppen zum Lesen: `RF18 RE20 2600 42`. */
	public static function format(string $reference): string {
		return trim(chunk_split($reference, 4, ' '));
	}

	/** Prüfziffern nach ISO 7064 (Mod 97-10): 98 minus Rest der umgestellten Zahl. */
	private static function checkDigits(string $base): string {
		$numeric = self::toNumeric($base . 'RF00');
		return str_pad((string)(98 - self::mod97($numeric)), 2, '0', STR_PAD_LEFT);
	}

	/** Buchstaben werden zu 10 bis 35, Ziffern bleiben. */
	private static function toNumeric(string $text): string {
		$out = '';
		foreach (str_split($text) as $char) {
			$out .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
		}
		return $out;
	}

	/** Rest durch 97 für beliebig lange Ziffernfolgen, ohne große Ganzzahlen. */
	private static function mod97(string $digits): int {
		$rest = 0;
		foreach (str_split($digits, 7) as $chunk) {
			$rest = (int)($rest . $chunk) % 97;
		}
		return $rest;
	}
}
