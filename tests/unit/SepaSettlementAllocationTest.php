<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService;
use PHPUnit\Framework\TestCase;

/**
 * Kontoteile einer SEPA-Sammelbuchung mit den Kostenstellen der
 * Beitragsgruppen (Issue #333): je Erlöskonto ein Teil, darin die Beträge je
 * Kostenstelle summiert; was keiner Kostenstelle gehört, bleibt ohne.
 */
class SepaSettlementAllocationTest extends TestCase {

	public function testEinKontoEineKostenstelle(): void {
		$parts = SepaImportConfirmationService::partsWithAllocations([
			['accountId' => 42, 'amountCents' => 3000, 'costCenterId' => 3],
		]);

		$this->assertSame([
			['accountId' => 42, 'amountCents' => 3000, 'allocations' => [['costCenterId' => 3, 'amountCents' => 3000]]],
		], $parts);
	}

	/** Mitglieder verschiedener Gruppen auf demselben Erlöskonto: ein Teil, mehrere Kostenstellen, Rest ohne. */
	public function testGemischteGruppenAufEinemKonto(): void {
		$parts = SepaImportConfirmationService::partsWithAllocations([
			['accountId' => 42, 'amountCents' => 3000, 'costCenterId' => 3],
			['accountId' => 42, 'amountCents' => 2000, 'costCenterId' => null],
			['accountId' => 42, 'amountCents' => 1500, 'costCenterId' => 5],
		]);

		$this->assertSame([
			['accountId' => 42, 'amountCents' => 6500, 'allocations' => [
				['costCenterId' => 3, 'amountCents' => 3000],
				['costCenterId' => 5, 'amountCents' => 1500],
			]],
		], $parts);
	}

	public function testMehrereMitgliederDerselbenKostenstelleWerdenSummiert(): void {
		$parts = SepaImportConfirmationService::partsWithAllocations([
			['accountId' => 42, 'amountCents' => 3000, 'costCenterId' => 3],
			['accountId' => 42, 'amountCents' => 4500, 'costCenterId' => 3],
		]);

		$this->assertSame([
			['accountId' => 42, 'amountCents' => 7500, 'allocations' => [['costCenterId' => 3, 'amountCents' => 7500]]],
		], $parts);
	}

	public function testZweiKontenGetrennteTeile(): void {
		$parts = SepaImportConfirmationService::partsWithAllocations([
			['accountId' => 42, 'amountCents' => 3000, 'costCenterId' => 3],
			['accountId' => 43, 'amountCents' => 1000, 'costCenterId' => 3],
			['accountId' => 42, 'amountCents' => 500, 'costCenterId' => null],
		]);

		$this->assertSame([
			['accountId' => 42, 'amountCents' => 3500, 'allocations' => [['costCenterId' => 3, 'amountCents' => 3000]]],
			['accountId' => 43, 'amountCents' => 1000, 'allocations' => [['costCenterId' => 3, 'amountCents' => 1000]]],
		], $parts);
	}

	/** Ohne Kostenstellen sieht die Aufteilung genau so aus wie vor der Funktion – kein Schlüssel. */
	public function testOhneKostenstellenKeinSchluessel(): void {
		$parts = SepaImportConfirmationService::partsWithAllocations([
			['accountId' => 42, 'amountCents' => 4500, 'costCenterId' => null],
			['accountId' => 42, 'amountCents' => 5000, 'costCenterId' => null],
		]);

		$this->assertSame([['accountId' => 42, 'amountCents' => 9500]], $parts);
	}

	public function testNullbetraegeWerdenUebergangen(): void {
		$parts = SepaImportConfirmationService::partsWithAllocations([
			['accountId' => 42, 'amountCents' => 0, 'costCenterId' => 3],
			['accountId' => 43, 'amountCents' => 1000, 'costCenterId' => null],
		]);

		$this->assertSame([['accountId' => 43, 'amountCents' => 1000]], $parts);
	}
}
