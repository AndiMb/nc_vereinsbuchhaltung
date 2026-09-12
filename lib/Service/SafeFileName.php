<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Dateisystem-tauglicher Name (Umlaute bleiben erhalten).
 *
 * Pfadtrenner, Nextclouds verbotene Zeichen und Steuerzeichen werden
 * ersetzt: eine Buchungsbeschreibung oder ein hochgeladener Dateiname ist
 * freier Text und könnte sonst aus dem vorgesehenen Ordner ausbrechen.
 * Gemeinsam für ZIP-Einträge und Uploads in den Wächter-Ordner.
 */
final class SafeFileName {

	public static function of(string $s, int $maxLen = 48): string {
		$s = preg_replace('/[\\\\\/:*?"<>|[:cntrl:]]/u', '_', $s) ?? '_';
		$s = trim(preg_replace('/\s+/u', ' ', $s) ?? '', ' ._');
		if ($s === '') {
			$s = '_';
		}
		return mb_substr($s, 0, $maxLen);
	}
}
