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
	 * @return array<string, array{string, int, int, float, string}>
	 */
	public static function levelProvider(): array {
		$rows = [
			// Ausgabe
			['expense', 100000, 79999, 1.0, 'good'],
			['expense', 100000, 80000, 1.0, 'warn'],
			['expense', 100000, 120000, 1.0, 'warn'],
			['expense', 100000, 120001, 1.0, 'crit'],
			['expense', 100000, 50000, 0.5, 'warn'],
			['expense', 100000, -5000, 1.0, 'good'],
			['expense', 0, 5000, 1.0, 'unplanned'],
			['expense', 0, 0, 1.0, 'none'],
			['expense', 100000, 50000, 0.0, 'none'],
			// Einnahme
			['income', 100000, 100000, 1.0, 'good'],
			['income', 100000, 99999, 1.0, 'warn'],
			['income', 100000, 80000, 1.0, 'warn'],
			['income', 100000, 79999, 1.0, 'crit'],
			['income', 0, 5000, 1.0, 'unplanned'],
			// Ergebnis
			['result', 10000, 10000, 1.0, 'good'],
			['result', 10000, 8000, 1.0, 'warn'],
			['result', 10000, 7999, 1.0, 'crit'],
			['result', -10000, -12000, 1.0, 'warn'],
			['result', 0, -20, 1.0, 'warn'],
			['result', 0, -21, 1.0, 'crit'],
			['result', 0, 0, 1.0, 'none'],
			['result', 10000, 5000, 0.0, 'none'],
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
	public function testLevel(string $nature, int $plan, int $actual, float $factor, string $level): void {
		$this->assertSame($level, BudgetStatus::of($nature, $plan, $actual, $factor, BudgetStatus::DEFAULTS)['level']);
	}

	public function testRatio(): void {
		$this->assertSame(0.8, BudgetStatus::of('expense', 100000, 80000, 1.0, BudgetStatus::DEFAULTS)['ratio']);
		$this->assertNull(BudgetStatus::of('expense', 0, 5000, 1.0, BudgetStatus::DEFAULTS)['ratio']);
		$this->assertNull(BudgetStatus::of('expense', 0, 0, 1.0, BudgetStatus::DEFAULTS)['ratio']);
		$this->assertNull(BudgetStatus::of('result', 10000, 8000, 1.0, BudgetStatus::DEFAULTS)['ratio']);
	}

	public function testForecast(): void {
		$this->assertSame(120000, BudgetStatus::forecast(60000, 6, 12));
		$this->assertSame(40000, BudgetStatus::forecast(10000, 3, 12));
		$this->assertNull(BudgetStatus::forecast(5000, 0, 12));
		$this->assertSame(400, BudgetStatus::forecast(100, 3, 12));
	}
}
