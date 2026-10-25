<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Invoice\CreditorReference;
use PHPUnit\Framework\TestCase;

/** Zahlungsreferenz nach ISO 11649 (RF…), abgeleitet aus der Rechnungsnummer. */
class CreditorReferenceTest extends TestCase {

	public function testBekanntesBeispielAusDerNormHatDiePruefziffern18(): void {
		// Beispiel aus ISO 11649 / Wikipedia: Basis 539007547034 ergibt RF18 5390 0754 7034.
		$this->assertTrue(CreditorReference::isValid('RF18539007547034'));
		$this->assertTrue(CreditorReference::isValid('rf18 5390 0754 7034'));
		$this->assertFalse(CreditorReference::isValid('RF19539007547034'));
	}

	public function testReferenzAusDerRechnungsnummer(): void {
		$reference = CreditorReference::fromInvoiceNumber('RE-2026-0042');

		$this->assertNotNull($reference);
		$this->assertStringStartsWith('RF', $reference);
		$this->assertStringEndsWith('RE20260042', $reference);
		$this->assertTrue(CreditorReference::isValid($reference));
	}

	public function testReineZiffernnummerErgibtDieNormReferenz(): void {
		$this->assertSame('RF18539007547034', CreditorReference::fromInvoiceNumber('5390-0754-7034'));
	}

	public function testJedeErzeugteReferenzIstGueltig(): void {
		foreach (['RE-2026-0001', 'ST-2026-0099', 'A/1', 'x9', '12345678901234567890A', 'R2027-12345'] as $number) {
			$reference = CreditorReference::fromInvoiceNumber($number);
			$this->assertNotNull($reference, $number);
			$this->assertTrue(CreditorReference::isValid($reference), $number);
			$this->assertLessThanOrEqual(25, strlen($reference), $number);
		}
	}

	public function testZuLangeLeereOderNichtAsciiNummernHabenKeineReferenz(): void {
		$this->assertNull(CreditorReference::fromInvoiceNumber('1234567890123456789012')); // 22 Zeichen
		$this->assertNull(CreditorReference::fromInvoiceNumber('---'));
		$this->assertNull(CreditorReference::fromInvoiceNumber('RÄ-2026-0001'));
	}

	public function testDarstellungInVierergruppen(): void {
		$this->assertSame('RF18 5390 0754 7034', CreditorReference::format('RF18539007547034'));
	}
}
