<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;

/**
 * Der gemeinsame Datenbestand, auf dem alle Endpunkte dieser App arbeiten.
 *
 * Vorher stand userId() in elf Controllern wortgleich da. Das war nicht nur
 * Tipparbeit: wer die Bedeutung von Application::BOOK sucht, fand elf
 * gleichwertige Fundstellen und keine, die erkennbar die maßgebliche war.
 *
 * Bis 0.32.0 lag hier auch yearRange(): die Umrechnung einer Jahreszahl in
 * 01.01. und 31.12. war eine reine Rechnung und passte in einen Trait. Seit
 * Issue #8 ist das Geschäftsjahr ein Datensatz und kein Rechenergebnis mehr;
 * die Grenzen holt jeder Controller darum direkt beim PeriodService
 * (`$this->periods->range($this->userId(), $period)`), der ohnehin schon
 * injiziert ist. Ein Trait, der auf ein Feld der benutzenden Klasse
 * zugreift, wäre die unübersichtlichere Lösung.
 */
trait BookContext {

	/**
	 * Der Datenbestand, auf den alle Endpunkte arbeiten.
	 *
	 * Die Buchhaltung eines Vereins ist ein gemeinsamer Bestand, kein
	 * Nutzerbesitz: alle Berechtigten sehen dieselben Zahlen. Wer was darf,
	 * entscheidet die PermissionMiddleware, nicht diese Kennung.
	 */
	private function userId(): string {
		return Application::BOOK;
	}
}
