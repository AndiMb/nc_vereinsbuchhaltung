<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Dateiname nur aus ASCII-Zeichen für Berichts-PDFs: Umlaute werden ausgeschrieben
 * („Größe“ → „Groesse“), alles andere außer Buchstaben, Ziffern, Punkt und Strich
 * wird zu „-“. Anders als {@see SafeFileName} (lässt Umlaute stehen) taugt das
 * Ergebnis für jede Umgebung, in der der Download landet, und ein Schrägstrich in
 * einem Vorlagen- oder Periodennamen ergibt nie einen Pfad.
 */
final class AsciiFileName {

	public static function of(string $text): string {
		$text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
		return (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $text);
	}
}
