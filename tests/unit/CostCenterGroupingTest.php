<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * Testet, wie ein Konto seiner Kostenstelle zugeordnet wird – für beide
 * Sichtweisen, die ohne Datenbank auskommen:
 *
 *  - 'group'  – Kostenstelle steckt in der Kontonummer ({@see ReportService::costCode()})
 *  - 'manual' – Kostenstelle ist am Konto hinterlegt ({@see ReportService::manualGroupKey()})
 */
class CostCenterGroupingTest extends TestCase {

	// --- Modus 'group': Kostenstelle aus der Kontonummer ------------------

	public function testZweiteZahlengruppeIstDieKostenstelle(): void {
		$this->assertSame('51', ReportService::costCode('111 51 2021'));
		$this->assertSame('01', ReportService::costCode('546 01 01'));
	}

	public function testKontonummerOhneZweiteGruppeHatKeineKostenstelle(): void {
		$this->assertNull(ReportService::costCode('111'));
		$this->assertNull(ReportService::costCode('4000'));
	}

	public function testNurZweistelligeZahlenGeltenAlsKostenstelle(): void {
		$this->assertNull(ReportService::costCode('111 5 2021'), 'einstellig');
		$this->assertNull(ReportService::costCode('111 512 2021'), 'dreistellig');
		$this->assertNull(ReportService::costCode('111 ab'), 'keine Zahl');
	}

	// --- Modus 'manual': frei definierte Kostenstellen ---------------------

	public function testZugeordnetesKontoLandetInSeinerKostenstelle(): void {
		$defined = [7 => 'Sommerfest', 9 => 'Verbandszeitung'];
		$this->assertSame('cc-7', ReportService::manualGroupKey(7, $defined));
		$this->assertSame('cc-9', ReportService::manualGroupKey(9, $defined));
	}

	public function testKontoOhneZuordnungHatKeineKostenstelle(): void {
		$this->assertNull(ReportService::manualGroupKey(null, [7 => 'Sommerfest']));
	}

	public function testZuordnungAufGeloeschteKostenstelleZaehltAlsOhne(): void {
		// Es gibt keine Fremdschlüssel im Schema – eine verwaiste ID darf keine
		// namenlose Gruppe erzeugen, siehe manualGroupKey().
		$this->assertNull(ReportService::manualGroupKey(42, [7 => 'Sommerfest']));
	}

	public function testGruppenSindProKostenstelleUnterscheidbar(): void {
		$defined = [1 => 'A', 2 => 'B'];
		$this->assertNotSame(
			ReportService::manualGroupKey(1, $defined),
			ReportService::manualGroupKey(2, $defined),
		);
	}
}
