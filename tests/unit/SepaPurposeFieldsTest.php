<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\SepaPurposeFields;
use PHPUnit\Framework\TestCase;

class SepaPurposeFieldsTest extends TestCase {

	public function testZerlegtBekanntePraefixe(): void {
		$fields = SepaPurposeFields::parse('EREF+E2E-1 MREF+M-1 KREF+MSG-1 SVWZ+Beitrag 2026');

		$this->assertSame('E2E-1', $fields['EREF']);
		$this->assertSame('M-1', $fields['MREF']);
		$this->assertSame('MSG-1', $fields['KREF']);
		$this->assertSame('Beitrag 2026', $fields['SVWZ']);
	}

	/** Spec §5: "Präfixe brechen über Subfeldgrenzen um" - der Text ist bereits konkateniert, Reihenfolge ist beliebig. */
	public function testFunktioniertUnabhaengigVonDerReihenfolge(): void {
		$fields = SepaPurposeFields::parse('SVWZ+Freitext MREF+M-9 EREF+E2E-9');

		$this->assertSame('M-9', $fields['MREF']);
		$this->assertSame('E2E-9', $fields['EREF']);
	}

	public function testOhnePraefixLiefertLeeresArray(): void {
		$this->assertSame([], SepaPurposeFields::parse('Nur ein normaler Verwendungszweck ohne Struktur'));
		$this->assertSame([], SepaPurposeFields::parse(''));
	}

	public function testAmountCentsWandeltSepaBetragsformat(): void {
		$this->assertSame(5500, SepaPurposeFields::amountCents('EUR55,00'));
		$this->assertSame(500, SepaPurposeFields::amountCents('5,00'));
		$this->assertNull(SepaPurposeFields::amountCents(null));
		$this->assertNull(SepaPurposeFields::amountCents(''));
	}

	public function testOamtUndCoamAusDemVerwendungszweck(): void {
		$fields = SepaPurposeFields::parse('EREF+E2E-1 OAMT+EUR45,00 COAM+EUR5,00');

		$this->assertSame(4500, SepaPurposeFields::amountCents($fields['OAMT'] ?? null));
		$this->assertSame(500, SepaPurposeFields::amountCents($fields['COAM'] ?? null));
	}
}
