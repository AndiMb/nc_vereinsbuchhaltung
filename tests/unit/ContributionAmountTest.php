<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ContributionAmount;
use PHPUnit\Framework\TestCase;

/**
 * Betrag einer Beitragsperiode für beide Betragsangaben (Issue #270): der
 * Monatsbetrag wird nur multipliziert, der Jahresbetrag gilt im vollen Jahr
 * exakt und wird nur im angebrochenen Jahr geteilt und gerundet. Ein Fehler
 * hier fällt als falscher Einzugsbetrag auf, nicht als Fehlermeldung.
 */
class ContributionAmountTest extends TestCase {

	public function testMonatsbetragWirdMitDenMonatenMultipliziert(): void {
		$this->assertSame(5004, ContributionAmount::cents(417, null, true, '2026-01-01', '2026-12-31'));
		$this->assertSame(2502, ContributionAmount::cents(417, null, true, '2026-07-01', '2026-12-31'));
	}

	public function testJahresbetragGiltImVollenJahrExakt(): void {
		$this->assertSame(5000, ContributionAmount::cents(417, 5000, true, '2026-01-01', '2026-12-31'));
		$this->assertSame(5000, ContributionAmount::cents(417, 5000, false, '2026-01-01', '2026-12-31'));
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function angebrocheneJahre(): array {
		return [
			'sechs Monate, glatt' => ['2026-07-01', '2026-12-31', 2500],
			'sieben Monate, aufgerundet' => ['2026-06-01', '2026-12-31', 2917],
			'ein Monat, aufgerundet' => ['2026-12-01', '2026-12-31', 417],
			'fünf Monate, abgerundet' => ['2026-01-01', '2026-05-31', 2083],
			'Austritt mitten im Monat zählt den Monat voll' => ['2026-01-01', '2026-06-10', 2500],
		];
	}

	/** @dataProvider angebrocheneJahre */
	public function testJahresbetragWirdImAngebrochenenJahrAnteiligGerundet(string $from, string $to, int $expected): void {
		$this->assertSame($expected, ContributionAmount::cents(417, 5000, true, $from, $to));
	}

	public function testJahresbetragOhneAnteiligeBerechnungBleibtVoll(): void {
		$this->assertSame(5000, ContributionAmount::cents(417, 5000, false, '2026-12-01', '2026-12-31'));
		$this->assertSame(5000, ContributionAmount::cents(417, 5000, false, '2026-01-01', '2026-03-31'));
	}

	public function testHalberCentWirdAufgerundet(): void {
		// 6 Cent im Jahr, ein Monat: 0,5 Cent.
		$this->assertSame(1, ContributionAmount::cents(1, 6, true, '2026-12-01', '2026-12-31'));
	}

	public function testBeitragsfreierJahresbetragBleibtNull(): void {
		$this->assertSame(0, ContributionAmount::cents(0, 0, true, '2026-06-01', '2026-12-31'));
		$this->assertSame(0, ContributionAmount::cents(0, 0, false, '2026-06-01', '2026-12-31'));
	}

	public function testMonatsbetragAusJahresbetragIstDerGerundeteZwoelftel(): void {
		$this->assertSame(417, ContributionAmount::monthlyFromAnnual(5000));
		$this->assertSame(208, ContributionAmount::monthlyFromAnnual(2500));
		$this->assertSame(0, ContributionAmount::monthlyFromAnnual(0));
	}

	public function testNegativerJahresbetragWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		ContributionAmount::cents(0, -1, true, '2026-01-01', '2026-12-31');
	}
}
