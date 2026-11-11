<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

/**
 * Der Verlauf der visuellen Gliederungsansicht als Inline-SVG für die
 * Druckseite: kumuliertes Ist je Monat (durchgezogen), Plan-Linie
 * (gepunktet, linear von plan/N im ersten auf plan im letzten Monat) und
 * Hochrechnung (gestrichelt, vom letzten Ist-Punkt zum Wert am Periodenende).
 * Dieselben Regeln und Linienarten wie `trendDatasets()` in
 * `src/lib/budgetVisual.js` und das Diagramm am Bildschirm.
 *
 * Einnahmen graublau (am Bildschirm die Akzentfarbe), Ausgaben schwarz (dort
 * die Textfarbe) – auch schwarzweiß gedruckt unterscheidbar; die Linienart
 * trägt Ist/Plan/Hochrechnung. Kein Skript.
 */
final class TrendSvg {

	public const WIDTH = 720;
	public const HEIGHT = 240;
	/** Zeichenfläche innerhalb der viewBox */
	public const LEFT = 96;
	public const RIGHT = 708;
	public const TOP = 12;
	public const BOTTOM = 204;

	public const INCOME_COLOR = '#5b7a99';
	public const EXPENSE_COLOR = '#222222';
	public const DASH_PLAN = '1.5 3';
	public const DASH_FORECAST = '6 4';

	/**
	 * @param array{months: list<string>, income: list<int>, expense: list<int>} $series kumuliertes Ist in Cent
	 * @param int $planIncome Jahresplan Einnahmen in Cent
	 * @param int $planExpense Jahresplan Ausgaben in Cent
	 * @param int|null $forecastIncome Hochrechnung zum Periodenende in Cent oder null
	 * @param int|null $forecastExpense Hochrechnung zum Periodenende in Cent oder null
	 * @param list<string> $monthLabels Beschriftung je Monat der Periode (ihre Anzahl ist die Periodenlänge)
	 * @param callable(int): string $formatAmount Cent → Betrag mit Währung
	 * @param array{title?: string, desc?: string} $texts übersetzte Texte für `<title>` und `<desc>`
	 * @return string SVG, leer ohne Ist-Monate
	 */
	public static function render(array $series, int $planIncome, int $planExpense, ?int $forecastIncome, ?int $forecastExpense, array $monthLabels, callable $formatAmount, array $texts = []): string {
		$months = $series['months'];
		$total = count($monthLabels);
		if ($months === [] || $total === 0) {
			return '';
		}
		$n = min(count($months), $total);
		$lines = [];
		foreach ([
			'income' => [$series['income'], $planIncome, $forecastIncome],
			'expense' => [$series['expense'], $planExpense, $forecastExpense],
		] as $nature => [$values, $plan, $forecast]) {
			$actual = array_map('intval', array_slice($values, 0, $n));
			$planLine = [];
			for ($i = 0; $i < $total; $i++) {
				$planLine[$i] = (int)round($plan * ($i + 1) / $total);
			}
			$forecastLine = [];
			if ($forecast !== null && $n < $total) {
				$forecastLine = [$n - 1 => $actual[$n - 1], $total - 1 => $forecast];
			}
			$lines[$nature] = ['actual' => $actual, 'plan' => $planLine, 'forecast' => $forecastLine];
		}

		$all = [0];
		foreach ($lines as $byKind) {
			foreach ($byKind as $points) {
				array_push($all, ...array_values($points));
			}
		}
		[$min, $max, $step] = self::scale(min($all), max($all));
		$x = static fn (int $i): float => $total <= 1
			? (self::LEFT + self::RIGHT) / 2
			: self::LEFT + $i * (self::RIGHT - self::LEFT) / ($total - 1);
		$y = static fn (int $v): float => self::BOTTOM - ($v - $min) * (self::BOTTOM - self::TOP) / ($max - $min);

		$h = '<svg xmlns="http://www.w3.org/2000/svg" class="trend" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" role="img">'
			. '<title>' . PrintableReportPage::escape($texts['title'] ?? '') . '</title>'
			. '<desc>' . PrintableReportPage::escape($texts['desc'] ?? '') . '</desc>';

		// y-Achse: Gitterlinien mit Beträgen
		for ($v = $min; $v <= $max; $v += $step) {
			$yy = self::num($y($v));
			$h .= '<line x1="' . self::LEFT . '" y1="' . $yy . '" x2="' . self::RIGHT . '" y2="' . $yy . '" stroke="#d0d0d0" stroke-width="1"/>'
				. '<text x="' . (self::LEFT - 6) . '" y="' . self::num($y($v) + 4) . '" text-anchor="end" font-size="11" fill="#555">'
				. PrintableReportPage::escape($formatAmount($v)) . '</text>';
		}
		// x-Achse: Monate, bei mehr als zwölf jeder zweite
		$every = $total > 12 ? 2 : 1;
		foreach ($monthLabels as $i => $label) {
			if ($i % $every === 0) {
				$h .= '<text x="' . self::num($x($i)) . '" y="' . (self::BOTTOM + 18) . '" text-anchor="middle" font-size="11" fill="#555">'
					. PrintableReportPage::escape($label) . '</text>';
			}
		}

		$colors = ['income' => self::INCOME_COLOR, 'expense' => self::EXPENSE_COLOR];
		foreach (['plan' => [self::DASH_PLAN, 1.5], 'forecast' => [self::DASH_FORECAST, 2], 'actual' => [null, 2.5]] as $kind => [$dash, $width]) {
			foreach ($colors as $nature => $color) {
				$h .= self::line($nature . ' ' . $kind, $lines[$nature][$kind], $x, $y, $color, $dash, $width);
			}
		}
		return $h . '</svg>';
	}

	/**
	 * Polylinie, ein einzelner Punkt als Kreis.
	 *
	 * @param array<int, int> $points Monatsindex => Wert
	 */
	private static function line(string $class, array $points, callable $x, callable $y, string $color, ?string $dash, float $width): string {
		if ($points === []) {
			return '';
		}
		if (count($points) === 1) {
			$i = array_key_first($points);
			return '<circle class="' . $class . '" cx="' . self::num($x($i)) . '" cy="' . self::num($y($points[$i])) . '" r="3" fill="' . $color . '"/>';
		}
		$coords = [];
		foreach ($points as $i => $v) {
			$coords[] = self::num($x($i)) . ',' . self::num($y($v));
		}
		return '<polyline class="' . $class . '" points="' . implode(' ', $coords) . '" fill="none" stroke="' . $color . '" stroke-width="' . self::num($width) . '"'
			. ($dash !== null ? ' stroke-dasharray="' . $dash . '"' : '')
			. ' stroke-linecap="round" stroke-linejoin="round"/>';
	}

	/**
	 * Runde Achsengrenzen: Schrittweite 1, 2 oder 5 × 10^k, etwa drei
	 * Abschnitte; 0 liegt immer im Bereich.
	 *
	 * @return array{int, int, int} [untere Grenze, obere Grenze, Schritt]
	 */
	private static function scale(int $min, int $max): array {
		if ($max <= $min) {
			$max = $min + 100;
		}
		$raw = ($max - $min) / 3;
		$magnitude = 10 ** floor(log10($raw));
		$norm = $raw / $magnitude;
		$step = (int)max(1, ($norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10))) * $magnitude);
		return [(int)(floor($min / $step) * $step), (int)(ceil($max / $step) * $step), $step];
	}

	/** Koordinate mit höchstens einer Nachkommastelle, ohne „.0“. */
	private static function num(float $v): string {
		$s = number_format($v, 1, '.', '');
		return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
	}
}
