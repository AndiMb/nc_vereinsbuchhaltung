<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

/**
 * Die Ampel des Gliederungsberichts: Plan gegen Ist zu einer Stufe.
 *
 * Stufen: `good`, `warn`, `crit`, `unplanned` (Ist ohne Plan) und `none`
 * (nichts zu vergleichen). Der zum Stand „fällige“ Anteil des Plans ist der
 * Bruch `$elapsed / $total` (1/1 = ganzes Jahr, 6/12 = halbes Jahr); bei
 * `$elapsed` 0 gibt es keinen Vergleich. Alle Beträge sind Cent, Schwellen
 * sind ganze Prozent.
 *
 * Verglichen wird nur in Ganzzahlen: statt `Ist × 100` gegen
 * `Schwelle × Plan × elapsed / total` wird mit `total` durchmultipliziert
 * (`Ist × 100 × total` gegen `Schwelle × Plan × elapsed`). So liegt ein Ist,
 * das genau auf dem (zeitanteiligen) Grenzwert liegt, auch genau darauf.
 *
 * Wie {@see \OCA\Vereinsbuchhaltung\Service\BudgetTree} statisch und ohne
 * Datenbank.
 */
final class BudgetStatus {

	public const GOOD = 'good';
	public const WARN = 'warn';
	public const CRIT = 'crit';
	public const UNPLANNED = 'unplanned';
	public const NONE = 'none';

	public const DEFAULTS = [
		'expWarn' => 80,
		'expCrit' => 120,
		'incGood' => 100,
		'incWarn' => 80,
		'resTol' => 20,
	];

	/**
	 * Ampelstufe einer Zeile.
	 *
	 * @param string $nature `income`, `expense` oder `result`
	 * @param int $plan Plan in Cent
	 * @param int $actual Ist in Cent
	 * @param int $elapsed fälliger Anteil des Plans: Zähler (Jahresbasis 1)
	 * @param int $total fälliger Anteil des Plans: Nenner (Jahresbasis 1)
	 * @param array<string, int|float> $thresholds Schwellen in Prozent (siehe DEFAULTS; fehlende Einträge von dort)
	 * @return array{level:string, ratio:?float} ratio = Ist ÷ Bezug (null bei Ergebnis, ohne Plan und ohne Vergleich)
	 */
	public static function of(string $nature, int $plan, int $actual, int $elapsed, int $total, array $thresholds): array {
		$t = array_map('intval', $thresholds + self::DEFAULTS);
		if ($plan === 0 && $actual === 0) {
			return self::result(self::NONE);
		}
		if ($nature === 'result') {
			return self::result(self::ofResult($plan, $actual, $elapsed, $total, $t['resTol']));
		}
		if ($plan === 0) {
			return self::result(self::UNPLANNED);
		}
		// Ein negativer Plan kehrt den Vergleich um – dafür gibt es keine Ampel.
		if ($plan < 0 || $elapsed <= 0 || $total <= 0) {
			return self::result(self::NONE);
		}

		$lhs = $actual * 100 * $total;
		$reference = $plan * $elapsed;
		$ratio = (float)($actual * $total) / $reference;
		if ($nature === 'income') {
			if ($lhs >= $t['incGood'] * $reference) {
				return self::result(self::GOOD, $ratio);
			}
			return self::result($lhs >= $t['incWarn'] * $reference ? self::WARN : self::CRIT, $ratio);
		}
		if ($lhs < $t['expWarn'] * $reference) {
			return self::result(self::GOOD, $ratio);
		}
		return self::result($lhs <= $t['expCrit'] * $reference ? self::WARN : self::CRIT, $ratio);
	}

	/**
	 * Hochrechnung auf das Gesamtjahr aus dem bisherigen Verlauf.
	 *
	 * @param int $actual Ist bis zum Stand in Cent
	 * @param int $elapsed vergangene Monate
	 * @param int $total Monate insgesamt
	 * @return int|null null, wenn noch kein Monat vergangen ist
	 */
	public static function forecast(int $actual, int $elapsed, int $total): ?int {
		if ($elapsed <= 0) {
			return null;
		}
		return (int)round($actual * $total / $elapsed);
	}

	/**
	 * Ergebnis: Abweichung vom zeitanteiligen Plan, Toleranz in Prozent des
	 * Bezugs (mindestens 100 Cent, damit ein Plan von 0 nicht jede
	 * Abweichung zum Rot macht). Wie in {@see of()} mit `total`
	 * durchmultipliziert, damit alles ganzzahlig bleibt.
	 */
	private static function ofResult(int $plan, int $actual, int $elapsed, int $total, int $tolerance): string {
		if ($elapsed <= 0 || $total <= 0) {
			return self::NONE;
		}
		$reference = $plan * $elapsed;
		$diff = $actual * $total - $reference;
		if ($diff >= 0) {
			return self::GOOD;
		}
		return $diff * 100 >= -$tolerance * max(abs($reference), 100 * $total) ? self::WARN : self::CRIT;
	}

	/**
	 * @return array{level:string, ratio:?float}
	 */
	private static function result(string $level, ?float $ratio = null): array {
		return ['level' => $level, 'ratio' => $ratio];
	}
}
