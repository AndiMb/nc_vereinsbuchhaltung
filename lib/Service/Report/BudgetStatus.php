<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

/**
 * Die Ampel des Gliederungsberichts: Plan gegen Ist zu einer Stufe.
 *
 * Stufen: `good`, `warn`, `crit`, `unplanned` (Ist ohne Plan) und `none`
 * (nichts zu vergleichen). Der Faktor ist der Anteil des Plans, der zum Stand
 * schon „fällig“ ist (1.0 = ganzes Jahr, 0.5 = halbes Jahr); bei 0 gibt es
 * keinen Vergleich. Alle Beträge sind Cent, Schwellen sind Prozent.
 *
 * Die Grenzwerte werden in Ganzzahl-Prozenten gegen Cent verglichen
 * (`Ist × 100` gegen `Schwelle × Bezug`), damit „genau 80 %“ nicht an
 * Gleitkomma-Rundung scheitert.
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
	 * @param float $factor Anteil des Plans, der zum Stand fällig ist
	 * @param array<string, int|float> $thresholds Schwellen in Prozent (siehe DEFAULTS)
	 * @return array{level:string, ratio:?float} ratio = Ist ÷ Bezug, bei
	 *         Ergebnis, ohne Plan und ohne Vergleich null
	 */
	public static function of(string $nature, int $plan, int $actual, float $factor, array $thresholds): array {
		$t = $thresholds + self::DEFAULTS;
		if ($plan === 0 && $actual === 0) {
			return self::result(self::NONE);
		}
		if ($nature === 'result') {
			return self::result(self::ofResult($plan, $actual, $factor, (float)$t['resTol']));
		}
		if ($plan === 0) {
			return self::result(self::UNPLANNED);
		}
		if ($factor <= 0.0) {
			return self::result(self::NONE);
		}

		$reference = $plan * $factor;
		$scaled = $actual * 100;
		$ratio = $actual / $reference;
		if ($nature === 'income') {
			if ($scaled >= $t['incGood'] * $reference) {
				return self::result(self::GOOD, $ratio);
			}
			return self::result($scaled >= $t['incWarn'] * $reference ? self::WARN : self::CRIT, $ratio);
		}
		if ($scaled < $t['expWarn'] * $reference) {
			return self::result(self::GOOD, $ratio);
		}
		return self::result($scaled <= $t['expCrit'] * $reference ? self::WARN : self::CRIT, $ratio);
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
	 * Abweichung zum Rot macht).
	 */
	private static function ofResult(int $plan, int $actual, float $factor, float $tolerance): string {
		if ($factor <= 0.0) {
			return self::NONE;
		}
		$reference = $plan * $factor;
		$diff = $actual - $reference;
		if ($diff >= 0) {
			return self::GOOD;
		}
		return $diff * 100 >= -$tolerance * max(abs($reference), 100.0) ? self::WARN : self::CRIT;
	}

	/**
	 * @return array{level:string, ratio:?float}
	 */
	private static function result(string $level, ?float $ratio = null): array {
		return ['level' => $level, 'ratio' => $ratio];
	}
}
