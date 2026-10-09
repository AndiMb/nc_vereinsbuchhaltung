<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Datum in Aufgabentexten: „01.11.2026“ statt „2026-11-01“. Die Aufgabenliste
 * liest ein Verein, kein Programm; die Servertexte kommen als fertiger Satz
 * (siehe TaskService), deshalb wird das Datum hier vor dem Einsetzen formatiert.
 */
final class GermanDate {
	/** @param string|null $iso JJJJ-MM-TT (auch mit Uhrzeit); ungültige Werte kommen unverändert zurück */
	public static function format(?string $iso): string {
		if ($iso === null || $iso === '') {
			return '';
		}
		try {
			return (new \DateTimeImmutable($iso))->format('d.m.Y');
		} catch (\Exception) {
			return $iso;
		}
	}
}
