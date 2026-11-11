<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nur Zeilen auf Ertrags- und Aufwandskonten tragen eine Kostenstelle
 * (Issue #7). Bank-, Bestands- und Eigenkapitalkonten beantworten die Frage
 * "für welche Abteilung war das Geld" nicht.
 */
class AccountCostCenterEligibilityTest extends TestCase {

	/** @return array<string, array{string, bool}> */
	public static function typen(): array {
		return [
			'Ertrag' => ['income', true],
			'Aufwand' => ['expense', true],
			'Bestand' => ['asset', false],
			'Verbindlichkeit' => ['liability', false],
			'Eigenkapital' => ['equity', false],
			'unbekannt' => ['', false],
		];
	}

	#[DataProvider('typen')]
	public function testNurErtragUndAufwandTragenKostenstellen(string $type, bool $expected): void {
		$account = new Account();
		$account->setType($type);
		$this->assertSame($expected, $account->canCarryCostCenter());
	}
}
