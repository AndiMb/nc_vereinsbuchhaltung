<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

/**
 * Zerlegt den deutschen „strukturierten Verwendungszweck", wie ihn Banken in
 * MT940 `:86:` (nach Konkatenation von `?20`–`?29`/`?60`–`?63`, siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser}) oder in
 * unstrukturierten Verwendungszweck-Freitexten schreiben, in seine benannten
 * Teile: `EREF+`, `MREF+`, `KREF+`, `CRED+`, `SVWZ+`, `ABWA+`, `ABWE+`,
 * `OAMT+`, `COAM+`.
 *
 * Spec §5 Implementierungs-Hinweis: „?20–29/?60–63 konkatenieren, dann an
 * SEPA-Präfixen splitten (Präfixe brechen über Subfeldgrenzen um)" – deshalb
 * arbeitet diese Klasse auf dem bereits zusammengesetzten String, nicht auf
 * den einzelnen `:86:`-Unterfeldern.
 *
 * Eigene, kleine Klasse statt Inline-Regex im Parser: dieselbe Zerlegung
 * greift potenziell an mehreren Stellen (MT940-Verwendungszweck, künftig ggf.
 * unstrukturierte camt-`Ustrd`-Texte als Fallback) und ist isoliert leichter
 * zu testen als eingebettet in die deutlich komplexere Feldzuordnung des
 * MT940-Parsers.
 */
class SepaPurposeFields {

	/** Bekannte Präfixe in fester, für den Split irrelevanter Reihenfolge. */
	private const PREFIXES = ['EREF', 'KREF', 'MREF', 'CRED', 'DEBT', 'SVWZ', 'ABWA', 'ABWE', 'OAMT', 'COAM'];

	/**
	 * @return array<string, string> Präfix (ohne '+') => Wert, nur vorhandene Präfixe
	 */
	public static function parse(string $text): array {
		$text = trim($text);
		if ($text === '' || !str_contains($text, '+')) {
			return [];
		}

		// Alle Vorkommen eines Präfix + '+' als Split-Punkte sammeln, dann nach
		// Position sortiert in Segmente zerlegen – ein Präfix kann in
		// beliebiger Reihenfolge auftreten, SVWZ+ steht oft als letztes Feld
		// mit unstrukturiertem Freitext, der selbst wieder "+"-Zeichen tragen
		// kann (deshalb wird NICHT an jedem "+" getrennt, sondern nur an
		// bekannten Präfixen).
		$positions = [];
		foreach (self::PREFIXES as $prefix) {
			$offset = 0;
			while (($pos = strpos($text, $prefix . '+', $offset)) !== false) {
				$positions[] = ['prefix' => $prefix, 'pos' => $pos, 'valueStart' => $pos + strlen($prefix) + 1];
				$offset = $pos + 1;
			}
		}
		if ($positions === []) {
			return [];
		}
		usort($positions, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

		$out = [];
		foreach ($positions as $i => $entry) {
			$end = $positions[$i + 1]['pos'] ?? strlen($text);
			$value = trim(substr($text, $entry['valueStart'], $end - $entry['valueStart']));
			if ($value !== '') {
				// Erstes Vorkommen gewinnt, falls ein Präfix (kann bei
				// zusammengesetzten SVWZ-Freitexten vorkommen) mehrfach auftaucht.
				$out[$entry['prefix']] ??= $value;
			}
		}
		return $out;
	}

	/**
	 * Wandelt einen SEPA-Betragswert (z. B. "EUR55,00", vereinzelt auch ohne
	 * Währungspräfix) in Cent. Gebraucht für `OAMT+`/`COAM+`.
	 */
	public static function amountCents(?string $value): ?int {
		if ($value === null || trim($value) === '') {
			return null;
		}
		// Führenden 3-stelligen Währungscode abtrennen, falls vorhanden.
		$v = preg_replace('/^[A-Z]{3}/', '', trim($value)) ?? trim($value);
		$v = str_replace('.', '', $v);
		$v = str_replace(',', '.', $v);
		if (!is_numeric($v)) {
			return null;
		}
		return (int)round(((float)$v) * 100);
	}
}
