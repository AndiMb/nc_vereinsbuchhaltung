<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BudgetComparison;
use PHPUnit\Framework\TestCase;

/**
 * Finanzplan gegen Vergleichsperiode (Issue #114): Plan minus Vergleich,
 * Prozent bezogen auf den Betrag des Vergleichswerts.
 */
class BudgetComparisonTest extends TestCase {

	public function testErhoehungIstPositiv(): void {
		// Das Beispiel aus dem Issue: Vorjahr 1.100 €, Plan 1.200 €
		$this->assertSame(10000, BudgetComparison::delta(120000, 110000));
		$this->assertSame(9.1, BudgetComparison::percent(120000, 110000));
	}

	public function testKuerzungIstNegativ(): void {
		$this->assertSame(-30000, BudgetComparison::delta(90000, 120000));
		$this->assertSame(-25.0, BudgetComparison::percent(90000, 120000));
	}

	public function testOhneVergleichswertKeinProzent(): void {
		$this->assertSame(50000, BudgetComparison::delta(50000, 0));
		$this->assertNull(BudgetComparison::percent(50000, 0));
	}

	public function testGleichstand(): void {
		$this->assertSame(0, BudgetComparison::delta(100, 100));
		$this->assertSame(0.0, BudgetComparison::percent(100, 100));
	}

	public function testNegativerVergleichswertBeziehtSichAufDenBetrag(): void {
		// z. B. ein Ergebnis von −1.000 €, geplant sind −500 €: eine
		// Verbesserung, also positiv
		$this->assertSame(50.0, BudgetComparison::percent(-50000, -100000));
	}

	public function testBasis(): void {
		$this->assertSame('plan', BudgetComparison::basis('plan'));
		$this->assertSame('actual', BudgetComparison::basis('actual'));
		$this->assertSame('actual', BudgetComparison::basis('quatsch'));
		$this->assertSame('actual', BudgetComparison::basis(null));
	}
}
