<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ProrataCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Prorata ist monatsgranular: angebrochene Monate zählen an beiden Enden
 * voll, nirgends wird geteilt oder gerundet (Spec §3.3). Ein Fehler hier
 * fällt nicht als Fehlermeldung auf, sondern als falscher Einzugsbetrag.
 */
class ProrataCalculatorTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function monatsspannen(): array {
		return [
			'ganzer Monat' => ['2026-01-01', '2026-01-31', 1],
			'angebrochener Start' => ['2026-01-15', '2026-01-31', 1],
			'angebrochenes Ende' => ['2026-01-01', '2026-01-05', 1],
			'beide Enden angebrochen, drei Monate' => ['2026-01-20', '2026-03-05', 3],
			'Jahreswechsel' => ['2026-11-15', '2027-02-10', 4],
			'derselbe Tag' => ['2026-06-15', '2026-06-15', 1],
			'volles Jahr' => ['2026-01-01', '2026-12-31', 12],
		];
	}

	/**
	 * @dataProvider monatsspannen
	 */
	public function testMonthsSpanned(string $from, string $to, int $expected): void {
		$this->assertSame($expected, ProrataCalculator::monthsSpanned($from, $to));
	}

	public function testEndeVorAnfangWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		ProrataCalculator::monthsSpanned('2026-03-01', '2026-01-01');
	}

	public function testUngueltigesDatumWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		ProrataCalculator::monthsSpanned('2026-02-30', '2026-03-01');
	}

	/**
	 * Der Leitsatz aus der Spec wörtlich: Einzugsbetrag = Monatsbeitrag ×
	 * Turnusmonate, per Multiplikation – nie Division.
	 */
	public function testAmountCentsIstReineMultiplikation(): void {
		$this->assertSame(3000, ProrataCalculator::amountCents(1000, '2026-01-20', '2026-03-05'));
		$this->assertSame(1000, ProrataCalculator::amountCents(1000, '2026-01-01', '2026-01-31'));
		$this->assertSame(0, ProrataCalculator::amountCents(0, '2026-01-01', '2026-01-31'));
	}

	public function testNegativerMonatsbeitragWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		ProrataCalculator::amountCents(-100, '2026-01-01', '2026-01-31');
	}
}
