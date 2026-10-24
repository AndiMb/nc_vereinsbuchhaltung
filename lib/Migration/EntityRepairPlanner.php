<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use OCA\Vereinsbuchhaltung\Service\Text\EntityDecoder;

/**
 * Plant die Reparatur gespeicherter Texte mit kodierten Sonderzeichen
 * (Issue #112). Reine Funktionen ohne Datenbank: die Migration
 * {@see Version000162Date20261024000000} liest die Kandidaten, lässt sie hier
 * prüfen und schreibt, was sich ändert.
 *
 * Bis 0.41.1 dekodierte der xbuc-Import nur eine Stufe; aus "M&amp;#252;ller"
 * wurde "M&üller", und in manchen Beständen stehen die Entitäten ganz
 * unaufgelöst ("M&amp;#252;ller", "M#252;ller"). Repariert wird mit
 * {@see EntityDecoder::repair()}, also vorsichtig: Zahlen ohne "&" nur, wenn
 * sie sicher ein Zeichen meinen. Ein Text, der dabei leer würde, bleibt, wie
 * er ist – lieber ein unschöner Text als ein verschwundener.
 */
final class EntityRepairPlanner {

	/** Reparierte Spalten je Tabelle mit ihrer Länge. */
	public const COLUMNS = [
		'vbh_journal' => ['description' => 255, 'document_ref' => 64],
		'vbh_accounts' => ['name' => 255],
	];

	/**
	 * @param list<array<string, mixed>> $rows Zeilen mit `id` und den Spalten aus $columns
	 * @param array<string, int> $columns Spalte => Länge
	 * @return list<array{id: int, column: string, before: string, after: string}>
	 */
	public static function plan(array $rows, array $columns): array {
		$changes = [];
		foreach ($rows as $row) {
			foreach ($columns as $column => $length) {
				$before = $row[$column] ?? null;
				if (!is_string($before) || !EntityDecoder::needsRepair($before)) {
					continue;
				}
				$after = mb_substr(EntityDecoder::repair($before), 0, $length);
				if ($after === '' || $after === $before) {
					continue;
				}
				$changes[] = ['id' => (int)$row['id'], 'column' => $column, 'before' => $before, 'after' => $after];
			}
		}
		return $changes;
	}
}
