<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

/**
 * Zahlen- und Datumsformate der Exporte – deutsche Schreibweise.
 *
 * Bewusst eigenständig statt in den einzelnen Exporten: die CSV-Dateien und
 * die druckfertigen Berichte sollen dieselben Beträge gleich schreiben,
 * damit ein Kassenprüfer die Tabelle neben den Ausdruck legen kann.
 */
final class ReportFormat {

	/** Betrag in Euro: 1234.5 → "1.234,50" */
	public static function money(float $eur): string {
		return number_format($eur, 2, ',', '.');
	}

	/** Betrag in Cent mit Vorzeichen, ohne Währung: 10000 → "+100,00", 0 → "0,00" */
	public static function signedMoney(int $cents): string {
		return ($cents > 0 ? '+' : '') . self::money($cents / 100);
	}

	/**
	 * Prozentwert mit einer Nachkommastelle, ohne Zeichen „%“: 9.1 → "9,1";
	 * null → "". Für CSV: ein führendes „+“ hielte Excel für eine Formel, der
	 * CSV-Schutz setzte dann ein Hochkomma davor.
	 */
	public static function percent(?float $percent): string {
		return $percent === null ? '' : number_format($percent, 1, ',', '.');
	}

	/** Prozentwert mit Vorzeichen, ohne Zeichen „%“: 9.1 → "+9,1"; null → "" */
	public static function signedPercent(?float $percent): string {
		if ($percent === null) {
			return '';
		}
		return ($percent > 0 ? '+' : '') . number_format($percent, 1, ',', '.');
	}

	/** Betrag in Cent, mit Währungszeichen: 123450 → "1.234,50 €" */
	public static function cents(int $cents): string {
		return self::money($cents / 100) . ' €';
	}

	/**
	 * ISO-Datum in deutscher Schreibweise: "2026-01-31" → "31.01.2026".
	 * Unbekannte Formate bleiben unverändert – lieber ein roher Wert im
	 * Bericht als eine stillschweigend falsche Angabe.
	 */
	public static function date(?string $iso): string {
		if (!$iso) {
			return '';
		}
		// Auch Zeitstempel („2026-10-09 12:53:41“, „2026-10-09T12:53:41“) kommen hier an: nur das Datum zählt.
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?!\d)/', $iso, $m) === 1) {
			return $m[3] . '.' . $m[2] . '.' . $m[1];
		}
		return $iso;
	}
}
