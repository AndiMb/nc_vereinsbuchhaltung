<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;

/**
 * Der gemeinsame Datenbestand und das gewählte Geschäftsjahr – die zwei
 * Angaben, die praktisch jeder Endpunkt dieser App braucht.
 *
 * Vorher stand userId() in elf Controllern wortgleich da. Das war nicht nur
 * Tipparbeit: wer die Bedeutung von Application::BOOK sucht, fand elf
 * gleichwertige Fundstellen und keine, die erkennbar die maßgebliche war.
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

	/**
	 * Datumsgrenzen des gewählten Geschäftsjahres, [null, null] für alle Jahre.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function yearRange(?int $year): array {
		return FiscalYear::range($year);
	}
}
