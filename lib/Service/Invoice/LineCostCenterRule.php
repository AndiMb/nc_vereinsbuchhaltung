<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

/**
 * Welche Kostenstelle eine Rechnungszeile beim Speichern bekommt (Issue #332).
 *
 * Reine Funktion, damit die eine Regel, auf die es ankommt, ohne Datenbank
 * prüfbar ist: Fehlt der Schlüssel in der Nutzlast – der Editor schickt ihn
 * bei ausgeschaltetem Modul nicht –, bleibt der gespeicherte Wert der Zeile
 * stehen. Beim Speichern eines Entwurfs werden alle Zeilen gelöscht und neu
 * geschrieben; ohne diese Regel ginge die Kostenstelle dabei still verloren.
 */
final class LineCostCenterRule {

	public const KEY = 'bookingCostCenterId';

	/**
	 * @param array<string, mixed> $row Zeile aus der Nutzlast
	 * @param int|null $stored Kostenstelle der bisherigen Zeile an derselben Position
	 * @param list<int> $knownIds alle vorhandenen Kostenstellen, auch inaktive
	 * @param bool $lenient unbekannte Kostenstelle wird zu „keine“ statt zum Fehler (Lauf einer wiederkehrenden Rechnung)
	 * @return array{0: ?int, 1: ?string} Kostenstelle und Fehlercode (`unknown`) oder null
	 */
	public static function resolve(array $row, ?int $stored, array $knownIds, bool $lenient = false): array {
		if (!array_key_exists(self::KEY, $row)) {
			return [$stored, null];
		}
		$value = $row[self::KEY];
		if ($value === null || $value === '' || (int)$value <= 0) {
			return [null, null];
		}
		$id = (int)$value;
		if (!in_array($id, $knownIds, true)) {
			return [null, $lenient ? null : 'unknown'];
		}
		return [$id, null];
	}
}
