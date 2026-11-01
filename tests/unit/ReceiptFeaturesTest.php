<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptFeatures;
use PHPUnit\Framework\TestCase;

/**
 * Merkmale aus Text: Referenz-Kerne, Beträge und Daten für die Belegvorschläge.
 */
class ReceiptFeaturesTest extends TestCase {

	public function testSchraegstrichUndBindestrichErgebenDenselbenKern(): void {
		$this->assertSame(['2826'], ReceiptFeatures::refs('ReNr 28/26 DATUM 15.10.2026, 16.46 UHR'));
		$this->assertContains('2826', ReceiptFeatures::refs('RG-28-26-Muster'));
	}

	public function testPraefixeZaehlenNicht(): void {
		$this->assertSame(['20264711'], ReceiptFeatures::refs('R-2026-4711'));
		$this->assertContains('20264711', ReceiptFeatures::refs('Rechnung Nr. R2026/4711'));
		$this->assertSame(['20264232740'], ReceiptFeatures::refs('Rechnung20264232740'));
	}

	public function testEinzelfolgenBleibenBeiVerworfenemBlock(): void {
		$this->assertContains('4711', ReceiptFeatures::refs('Nr 4711-15.10.2026'));
	}

	public function testDatumUhrzeitJahrBetragWerdenVerworfen(): void {
		$this->assertSame([], ReceiptFeatures::refs('15.10.2026 15.10.26 2026-10-15 16.46 16:46 2026 119,00 1.234,56 12.50'));
	}

	public function testKurzeKerneWerdenVerworfen(): void {
		$this->assertSame([], ReceiptFeatures::refs('Nr 12 Pos 7/8'));
	}

	public function testSepaVerwendungszweckLiefertKeineIbanKerne(): void {
		$refs = ReceiptFeatures::refs('SVWZ+ReNr 28/26 IBAN DE02120300000000202051 EREF+NOTPROVIDED');
		$this->assertSame(['2826'], $refs);
	}

	public function testIbanMitLeerzeichenUndKleinbuchstabenLiefertKeineKerne(): void {
		$this->assertSame(['4711'], ReceiptFeatures::refs('IBAN DE02 1203 0000 0000 2020 51 RE 4711'));
		$this->assertSame([], ReceiptFeatures::refs('de02120300000000202051'));
		$this->assertSame(['4711'], ReceiptFeatures::refs('RE 4711'));
		$this->assertSame(['4711'], ReceiptFeatures::refs('Rechnung 2026 4711'));
	}

	public function testRefsWithSourceKenntDenAusschnitt(): void {
		$this->assertSame(['2826' => '28/26'], ReceiptFeatures::refsWithSource('ReNr 28/26'));
	}

	public function testKerneSindEindeutigUndBegrenzt(): void {
		$this->assertSame(['4711'], ReceiptFeatures::refs('4711 und 4711'));
		$text = implode(' ', array_map(static fn (int $i): string => (string)(10000 + $i), range(1, 400)));
		$this->assertCount(300, ReceiptFeatures::refs($text));
	}

	public function testBetraegeInVierSchreibweisen(): void {
		$this->assertSame([123456, 11900, 999], ReceiptFeatures::amounts('1.234,56 EUR · 119,00 € · 1,234.56 · 9.99'));
	}

	public function testBetraegeNullUndZuGrossFallenWeg(): void {
		$this->assertSame([], ReceiptFeatures::amounts('0,00 10.000.000,00'));
	}

	public function testUngueltigeDatenFallenWeg(): void {
		$today = new \DateTimeImmutable('2026-10-31');
		$this->assertSame(['2026-10-15', '2026-10-20'], ReceiptFeatures::dates('15.10.2026 31.02.2026 2026-10-20 01.01.2010', $today));
	}

	public function testZweistelligesJahr(): void {
		$today = new \DateTimeImmutable('2026-10-31');
		$this->assertSame(['2026-10-15'], ReceiptFeatures::dates('am 15.10.26', $today));
	}
}
