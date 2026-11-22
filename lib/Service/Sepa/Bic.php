<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

/**
 * Die BIC einer Bankverbindung in der Form, die pain.008 verlangt.
 *
 * Eine einzige BIC, die dem Schema nicht entspricht, macht die ganze
 * Lastschriftdatei ungültig – für alle Mitglieder des Laufs, nicht nur für
 * das eine. Deshalb wird sie bei der Eingabe geprüft und beim Schreiben der
 * Datei noch einmal: was schon gespeichert ist, darf die Datei nicht brechen.
 */
final class Bic {
	/** `BICIdentifier` aus pain.008.001.02: 8 oder 11 Zeichen. */
	private const PATTERN = '/^[A-Z]{6}[A-Z2-9][A-NP-Z0-9](?:[A-Z0-9]{3})?$/';

	/**
	 * Großgeschrieben und ohne Leerzeichen; null, wenn nichts angegeben ist
	 * oder der Wert nicht dem Schema entspricht. Ohne BIC ermittelt die Bank
	 * sie aus der IBAN („NOTPROVIDED“).
	 */
	public static function normalize(?string $bic): ?string {
		$bic = strtoupper((string)preg_replace('/\s+/', '', (string)$bic));
		return preg_match(self::PATTERN, $bic) === 1 ? $bic : null;
	}
}
