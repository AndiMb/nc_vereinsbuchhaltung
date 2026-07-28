<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Formatiert CSV-Zeilen für die Exporte (Semikolon, doppelte Anführungszeichen).
 *
 * Als eigenständige Klasse ohne Nextcloud-Abhängigkeiten, damit sich vor allem
 * {@see safeField()} ohne laufende Instanz testen lässt – dort steckt eine
 * Sicherheitsentscheidung, und die will man abgedeckt haben.
 */
class CsvFormatter {

	/**
	 * Zeichen, die eine Tabellenkalkulation am Feldanfang als Formelbeginn
	 * versteht.
	 */
	private const FORMULA_STARTERS = "=+-@\t\r";

	/**
	 * Entschärft Felder, die Excel/LibreOffice als Formel auffassen würden.
	 *
	 * Ein Verwendungszweck stammt aus einer fremden Überweisung – wer Geld
	 * schickt, bestimmt den Text. Ohne diese Behandlung ließe sich einer
	 * Kassenprüferin, die den Export in der Tabellenkalkulation öffnet, eine
	 * Formel unterschieben (Nachladen externer Inhalte, DDE-Aufrufe). Ein
	 * vorangestelltes Apostroph erzwingt die Text-Interpretation; angezeigt
	 * wird weiterhin der Originaltext.
	 *
	 * Zahlen bleiben ausgenommen: sonst würde jeder negative Betrag
	 * ("-1.234,56") zu Text und die Summenspalten wären nicht mehr rechenbar.
	 */
	public static function safeField(string $value): string {
		if ($value === '') {
			return $value;
		}
		if (preg_match('/^-?[\d.]+(,\d+)?$/', $value) === 1) {
			return $value;
		}
		if (str_contains(self::FORMULA_STARTERS, $value[0])) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Eine CSV-Zeile: Semikolon-getrennt, jedes Feld in Anführungszeichen,
	 * enthaltene Anführungszeichen verdoppelt, CRLF am Ende (Excel-tauglich).
	 *
	 * @param array<int, string> $fields
	 */
	public static function line(array $fields): string {
		$escaped = array_map(
			static fn (string $f): string => '"' . str_replace('"', '""', self::safeField($f)) . '"',
			$fields,
		);
		return implode(';', $escaped) . "\r\n";
	}
}
