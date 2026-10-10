<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\AnonymizationEligibilityCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Fristberechnung der DSGVO-Anonymisierung (Spec §3.8, Issue #78, T30) –
 * siehe {@see AnonymizationEligibilityCalculator}-Klassendoc für die genaue
 * Herleitung des 1.-Januar-Stichtags und die Dominanz-Regel.
 */
class AnonymizationEligibilityCalculatorTest extends TestCase {

	// --- 10-Jahres-Grenze ---------------------------------------------------

	public function testCutoffIstDer1JanuarDesElftenJahresNachDerBuchung(): void {
		$this->assertSame('2037-01-01', AnonymizationEligibilityCalculator::tenYearCutoffDate('2026-05-15'));
	}

	public function testCutoffHaengtNurVomBuchungsjahrAb(): void {
		// 1. Januar wie 31. Dezember desselben Jahres fuehren zum selben Stichtag.
		$this->assertSame(
			AnonymizationEligibilityCalculator::tenYearCutoffDate('2026-01-01'),
			AnonymizationEligibilityCalculator::tenYearCutoffDate('2026-12-31'),
		);
	}

	public function testNichtReifKurzVorDemStichtag(): void {
		$this->assertFalse(AnonymizationEligibilityCalculator::isEligible('2026-05-15', '2036-12-31'));
	}

	public function testReifGenauAmStichtag(): void {
		$this->assertTrue(AnonymizationEligibilityCalculator::isEligible('2026-05-15', '2037-01-01'));
	}

	public function testReifLangeNachDemStichtag(): void {
		$this->assertTrue(AnonymizationEligibilityCalculator::isEligible('2026-05-15', '2050-06-30'));
	}

	// --- Dominanz gegenüber der SEPA-14-Monats-Untergrenze (Spec §8) --------

	public function testSepaUntergrenzeAlleinReichtNichtWennZehnJahresgrenzeNochOffenIst(): void {
		// Mandat vor über 14 Monaten beendet - fuer sich genommen waere die
		// SEPA-Untergrenze laengst erfuellt ...
		$mandateEndedAt = '2035-01-01';
		$this->assertLessThan('2037-01-01', AnonymizationEligibilityCalculator::sepaFloorDate($mandateEndedAt));

		// ... trotzdem ist das Mitglied nicht anonymisierungsreif, weil die
		// 10-Jahres-Grenze (aus der letzten Buchung 2026) erst 2037 erreicht
		// ist: die 10-Jahres-Regel dominiert (Spec §3.8), isEligible() nutzt
		// die SEPA-Untergrenze gar nicht erst.
		$this->assertFalse(AnonymizationEligibilityCalculator::isEligible('2026-05-15', '2036-06-01'));
	}

	public function testZehnJahresgrenzeBleibtMassgeblichAuchWennSieSpaeterLiegtAlsDieSepaUntergrenze(): void {
		$lastBooking = '2026-05-15';
		$mandateEndedAt = '2026-06-01'; // SEPA-Untergrenze waere schon 2027-08-01 erfuellt
		$this->assertLessThan(
			AnonymizationEligibilityCalculator::tenYearCutoffDate($lastBooking),
			AnonymizationEligibilityCalculator::sepaFloorDate($mandateEndedAt),
		);

		// Erst am 10-Jahres-Stichtag reif, nicht schon an der SEPA-Untergrenze.
		$this->assertFalse(AnonymizationEligibilityCalculator::isEligible($lastBooking, '2027-08-02'));
		$this->assertTrue(AnonymizationEligibilityCalculator::isEligible($lastBooking, AnonymizationEligibilityCalculator::tenYearCutoffDate($lastBooking)));
	}

	public function testSepaFloorDateAddiertVierzehnMonate(): void {
		$this->assertSame('2027-03-01', AnonymizationEligibilityCalculator::sepaFloorDate('2026-01-01'));
	}
}
