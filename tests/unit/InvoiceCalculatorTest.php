<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Service\InvoiceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Rechnungsrechnung in Ganzzahlen: Positionssummen, Rabatt, Rundung, Steuer
 * je Satz und die cent-genaue Verteilung von Teilzahlungen.
 */
class InvoiceCalculatorTest extends TestCase {

	/** @dataProvider rundung */
	public function testRoundDiv(int $n, int $d, int $expected): void {
		$this->assertSame($expected, InvoiceCalculator::roundDiv($n, $d));
	}

	/** @return array<string, array{int, int, int}> */
	public static function rundung(): array {
		return [
			'exakt' => [10, 5, 2],
			'abrunden' => [14, 10, 1],
			'halbe auf' => [15, 10, 2],
			'negativ halbe weg von null' => [-15, 10, -2],
			'negativ abrunden' => [-14, 10, -1],
			'negativer Nenner' => [15, -10, -2],
		];
	}

	/** @dataProvider positionen */
	public function testLineNet(int $qtyE4, int $price, int $discountBp, int $expected): void {
		$this->assertSame($expected, InvoiceCalculator::lineNet($qtyE4, $price, $discountBp));
	}

	/** @return array<string, array{int, int, int, int}> */
	public static function positionen(): array {
		return [
			'ein Stück' => [10000, 2500, 0, 2500],
			'drei Stück' => [30000, 1999, 0, 5997],
			'Stunden mit Bruchteil' => [15000, 4000, 0, 6000],
			'Viertelstunde' => [2500, 5999, 0, 1500],
			'zehn Prozent Rabatt' => [10000, 1000, 1000, 900],
			'Rabatt rundet kaufmännisch' => [10000, 1005, 1000, 904],
			'negative Menge (Storno)' => [-20000, 1500, 0, -3000],
			'voller Rabatt' => [10000, 500, 10000, 0],
		];
	}

	public function testSummenKleinunternehmerOhneSteuer(): void {
		$totals = InvoiceCalculator::totals([
			['lineNetCents' => 1000, 'taxRateBp' => 1900, 'taxCategory' => 'S'],
			['lineNetCents' => 550, 'taxRateBp' => 0, 'taxCategory' => 'E'],
		], Invoice::TAX_SMALL_BUSINESS);

		$this->assertSame(1550, $totals['netCents']);
		$this->assertSame(0, $totals['taxCents']);
		$this->assertSame(1550, $totals['grossCents']);
		$this->assertCount(1, $totals['taxBreakdown']);
		$this->assertSame('E', $totals['taxBreakdown'][0]['category']);
	}

	public function testSteuerWirdJeSatzEinmalGerundet(): void {
		$totals = InvoiceCalculator::totals([
			['lineNetCents' => 333, 'taxRateBp' => 1900, 'taxCategory' => 'S'],
			['lineNetCents' => 333, 'taxRateBp' => 1900, 'taxCategory' => 'S'],
			['lineNetCents' => 1000, 'taxRateBp' => 700, 'taxCategory' => 'S'],
			['lineNetCents' => 200, 'taxRateBp' => 0, 'taxCategory' => 'E'],
		], Invoice::TAX_REGULAR);

		// 666 × 19 % = 126,54 → 127; 1000 × 7 % = 70; Befreites bleibt ohne Steuer.
		$this->assertSame(1866, $totals['netCents']);
		$this->assertSame(197, $totals['taxCents']);
		$this->assertSame(2063, $totals['grossCents']);
		$this->assertCount(3, $totals['taxBreakdown']);
	}

	public function testLeereRechnungHatSummeNull(): void {
		$totals = InvoiceCalculator::totals([], Invoice::TAX_SMALL_BUSINESS);

		$this->assertSame(0, $totals['grossCents']);
		$this->assertSame([], $totals['taxBreakdown']);
	}

	/** @dataProvider verteilungen */
	public function testAllocateVerliertKeinenCent(int $amount, array $weights, array $expected): void {
		$parts = InvoiceCalculator::allocate($amount, $weights);

		$this->assertSame($expected, $parts);
		$this->assertSame($amount, array_sum($parts));
	}

	/** @return array<string, array{int, list<int>, list<int>}> */
	public static function verteilungen(): array {
		return [
			'gleichmäßig' => [100, [1, 1], [50, 50]],
			'Rest geht an den größten Rest' => [100, [1, 1, 1], [34, 33, 33]],
			'nach Gewicht' => [1000, [3000, 1000], [750, 250]],
			'ein Konto' => [777, [500], [777]],
			'Gewichte null' => [50, [0, 0], [50, 0]],
			'negativ (Rückzahlung)' => [-100, [1, 1, 1], [-34, -33, -33]],
			'Teilbetrag' => [1, [1, 1], [1, 0]],
			'leer' => [0, [], []],
		];
	}
}
