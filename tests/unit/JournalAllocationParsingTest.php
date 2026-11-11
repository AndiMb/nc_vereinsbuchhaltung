<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CostCenter\AllocationRules;
use PHPUnit\Framework\TestCase;

/**
 * Die Schnittstelle nimmt die Verteilung einer Zeile in Euro entgegen
 * (Issue #7). Entscheidend ist der Unterschied zwischen "Schlüssel fehlt"
 * (nichts gesagt, Verteilung bleibt) und "Schlüssel vorhanden" (ersetzen) –
 * und dass je Eintrag auf Cent gerundet wird, bevor irgendwer summiert.
 */
class JournalAllocationParsingTest extends TestCase {

	public function testFehlenderSchluesselBleibtNull(): void {
		$this->assertNull(AllocationRules::parseEuro(['accountId' => 1, 'debit' => 10]));
	}

	public function testLeereListeBleibtLeereListe(): void {
		$this->assertSame([], AllocationRules::parseEuro(['allocations' => []]));
	}

	public function testEuroWirdJeEintragAufCentGerundet(): void {
		$parsed = AllocationRules::parseEuro(['allocations' => [
			['costCenterId' => 1, 'amount' => 33.33],
			['costCenterId' => 2, 'amount' => 33.33],
			['costCenterId' => 3, 'amount' => 33.34],
		]]);
		$this->assertSame(
			[
				['costCenterId' => 1, 'amountCents' => 3333],
				['costCenterId' => 2, 'amountCents' => 3333],
				['costCenterId' => 3, 'amountCents' => 3334],
			],
			$parsed,
		);
		$this->assertSame(10000, array_sum(array_column($parsed, 'amountCents')));
	}

	public function testZahlenAlsTextWerdenGelesen(): void {
		$this->assertSame(
			[['costCenterId' => 3, 'amountCents' => 60000]],
			AllocationRules::parseEuro(['allocations' => [['costCenterId' => '3', 'amount' => '600.00']]]),
		);
	}

	public function testNichtArrayWirdZuLeererListe(): void {
		$this->assertSame([], AllocationRules::parseEuro(['allocations' => 'x']));
	}

	public function testEintraegeDieKeineArraysSindFallenWeg(): void {
		$this->assertSame(
			[['costCenterId' => 3, 'amountCents' => 100]],
			AllocationRules::parseEuro(['allocations' => ['x', ['costCenterId' => 3, 'amount' => 1]]]),
		);
	}

	public function testNullHeisstSchluesselFehlt(): void {
		$this->assertNull(AllocationRules::parseEuro(['allocations' => null]));
	}
}
