<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Report\BudgetStatus;
use PHPUnit\Framework\TestCase;

/**
 * Die Ampel des Gliederungsberichts: Grenzwerte exakt, Beträge in Cent.
 */
class BudgetStatusTest extends TestCase {

	/**
	 * @return array<string, array{string, int, int, int, int, string}>
	 */
	public static function levelProvider(): array {
		$rows = [
			// Ausgabe
			['expense', 100000, 79999, 1, 1, 'good'],
			['expense', 100000, 80000, 1, 1, 'warn'],
			['expense', 100000, 120000, 1, 1, 'warn'],
			['expense', 100000, 120001, 1, 1, 'crit'],
			['expense', 100000, 50000, 1, 2, 'warn'],
			['expense', 100000, -5000, 1, 1, 'good'],
			['expense', 0, 5000, 1, 1, 'unplanned'],
			['expense', 0, 0, 1, 1, 'none'],
			['expense', 100000, 50000, 0, 12, 'none'],
			// Ausgabe, zeitanteilig an der Grenze (Bezug 100 Cent)
			['expense', 1200, 79, 1, 12, 'good'],
			['expense', 1200, 80, 1, 12, 'warn'],
			['expense', 1200, 120, 1, 12, 'warn'],
			['expense', 1200, 121, 1, 12, 'crit'],
			// Negativer Plan: kein sinnvoller Vergleich
			['expense', -50000, 0, 1, 1, 'none'],
			['income', -50000, 1000, 1, 1, 'none'],
			// Einnahme
			['income', 100000, 100000, 1, 1, 'good'],
			['income', 100000, 99999, 1, 1, 'warn'],
			['income', 100000, 80000, 1, 1, 'warn'],
			['income', 100000, 79999, 1, 1, 'crit'],
			['income', 0, 5000, 1, 1, 'unplanned'],
			// 132 € Jahresplan, nach 7 von 12 Monaten genau 77 €
			['income', 13200, 7700, 7, 12, 'good'],
			['income', 13200, 7699, 7, 12, 'warn'],
			// Ergebnis
			['result', 10000, 10000, 1, 1, 'good'],
			['result', 10000, 8000, 1, 1, 'warn'],
			['result', 10000, 7999, 1, 1, 'crit'],
			['result', -10000, -12000, 1, 1, 'warn'],
			['result', 0, -20, 1, 1, 'warn'],
			['result', 0, -21, 1, 1, 'crit'],
			['result', 0, 0, 1, 1, 'none'],
			['result', 10000, 5000, 0, 12, 'none'],
			['result', 13200, 7700, 7, 12, 'good'],
			['result', 12000, 5600, 7, 12, 'warn'],
			['result', 12000, 5599, 7, 12, 'crit'],
		];
		$cases = [];
		foreach ($rows as $row) {
			$cases[implode(' ', array_map('strval', $row))] = $row;
		}
		return $cases;
	}

	/**
	 * @dataProvider levelProvider
	 */
	public function testLevel(string $nature, int $plan, int $actual, int $elapsed, int $total, string $level): void {
		$this->assertSame($level, BudgetStatus::of($nature, $plan, $actual, $elapsed, $total, BudgetStatus::DEFAULTS)['level']);
	}

	public function testRatio(): void {
		$this->assertSame(0.8, BudgetStatus::of('expense', 100000, 80000, 1, 1, BudgetStatus::DEFAULTS)['ratio']);
		$this->assertSame(1.0, BudgetStatus::of('income', 13200, 7700, 7, 12, BudgetStatus::DEFAULTS)['ratio']);
		$this->assertNull(BudgetStatus::of('expense', 0, 5000, 1, 1, BudgetStatus::DEFAULTS)['ratio']);
		$this->assertNull(BudgetStatus::of('expense', 0, 0, 1, 1, BudgetStatus::DEFAULTS)['ratio']);
		$this->assertNull(BudgetStatus::of('result', 10000, 8000, 1, 1, BudgetStatus::DEFAULTS)['ratio']);
	}

	public function testPartialThresholdsFallBackToDefaults(): void {
		$only = ['expWarn' => 50];
		$this->assertSame('good', BudgetStatus::of('expense', 100000, 49999, 1, 1, $only)['level']);
		$this->assertSame('warn', BudgetStatus::of('expense', 100000, 50000, 1, 1, $only)['level']);
		// expCrit, incGood, incWarn und resTol aus DEFAULTS
		$this->assertSame('warn', BudgetStatus::of('expense', 100000, 120000, 1, 1, $only)['level']);
		$this->assertSame('crit', BudgetStatus::of('expense', 100000, 120001, 1, 1, $only)['level']);
		$this->assertSame('warn', BudgetStatus::of('income', 100000, 80000, 1, 1, $only)['level']);
		$this->assertSame('crit', BudgetStatus::of('income', 100000, 79999, 1, 1, $only)['level']);
		$this->assertSame('warn', BudgetStatus::of('result', 10000, 8000, 1, 1, $only)['level']);
	}

	public function testForecast(): void {
		$this->assertSame(120000, BudgetStatus::forecast(60000, 6, 12));
		$this->assertSame(40000, BudgetStatus::forecast(10000, 3, 12));
		$this->assertNull(BudgetStatus::forecast(5000, 0, 12));
		$this->assertSame(400, BudgetStatus::forecast(100, 3, 12));
	}
}
