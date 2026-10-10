<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Dev;

/**
 * Erzeugt deutsche Test-IBANs mit korrekter Prüfsumme (ISO 13616, Modulo 97).
 *
 * Bankleitzahlen sind echt, die Kontonummern frei gewählt: so besteht die IBAN
 * jede Prüfsummenkontrolle (Online-Banking, pain.008-Validierung), gehört aber
 * zu keinem realen Konto. Die App selbst prüft IBANs nur formal
 * ({@see \OCA\Vereinsbuchhaltung\Service\IbanValidator}) – die Testdaten sind
 * trotzdem sauber, damit auch Prüftools der Hausbank sie annehmen.
 */
final class IbanFactory {

	private function __construct() {
		// Nur statische Hilfen.
	}

	/**
	 * @param string $bankCode achtstellige Bankleitzahl
	 * @param string $account Kontonummer, bis zu zehn Ziffern (wird links mit Nullen aufgefüllt)
	 */
	public static function german(string $bankCode, string $account): string {
		$bankCode = (string)preg_replace('/\s+/', '', $bankCode);
		$account = (string)preg_replace('/\s+/', '', $account);
		if (!preg_match('/^\d{8}$/', $bankCode)) {
			throw new \InvalidArgumentException('Die Bankleitzahl muss achtstellig sein: ' . $bankCode);
		}
		if (!preg_match('/^\d{1,10}$/', $account)) {
			throw new \InvalidArgumentException('Die Kontonummer muss aus 1 bis 10 Ziffern bestehen: ' . $account);
		}
		$bban = $bankCode . str_pad($account, 10, '0', STR_PAD_LEFT);
		return 'DE' . self::checkDigits('DE', $bban) . $bban;
	}

	/** Die beiden Prüfziffern zu Ländercode und BBAN. */
	public static function checkDigits(string $country, string $bban): string {
		$remainder = self::mod97(self::numeric($bban . $country . '00'));
		return str_pad((string)(98 - $remainder), 2, '0', STR_PAD_LEFT);
	}

	/** Ob die Prüfsumme der IBAN stimmt (Leerzeichen und Kleinschreibung egal). */
	public static function isValid(string $iban): bool {
		$iban = strtoupper((string)preg_replace('/\s+/', '', $iban));
		if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
			return false;
		}
		return self::mod97(self::numeric(substr($iban, 4) . substr($iban, 0, 4))) === 1;
	}

	/** Anzeigeform in Vierergruppen, wie sie auf Papier üblich ist. */
	public static function format(string $iban): string {
		return trim(chunk_split(strtoupper((string)preg_replace('/\s+/', '', $iban)), 4, ' '));
	}

	/** Buchstaben werden zu Zahlen (A=10 … Z=35), Ziffern bleiben. */
	private static function numeric(string $text): string {
		$out = '';
		foreach (str_split(strtoupper($text)) as $char) {
			$out .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
		}
		return $out;
	}

	/** Modulo 97 über eine beliebig lange Ziffernfolge, ohne Bibliothek für große Zahlen. */
	private static function mod97(string $digits): int {
		$remainder = 0;
		foreach (str_split($digits) as $digit) {
			$remainder = ($remainder * 10 + (int)$digit) % 97;
		}
		return $remainder;
	}
}
