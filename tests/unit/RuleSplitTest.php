<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RulePart;
use OCA\Vereinsbuchhaltung\Service\RuleSplit;
use PHPUnit\Framework\TestCase;

/**
 * Aufteilung eines Umsatzes durch eine Importregel (Issue #163): feste
 * Anteile in ihrer Reihenfolge, der Rest aufs Gegenkonto. Reicht der Umsatz
 * nicht für alle Anteile, greift die Regel nicht.
 */
class RuleSplitTest extends TestCase {

	/** @param list<array{0:int, 1:int, 2?:int|null}> $parts [Konto, Cent, Kostenstelle] */
	private function rule(array $parts): Rule {
		$rule = new Rule();
		$rule->setContraAccountId(5);
		$rule->setCostCenterId(3);
		$rule->setPartsList(array_map(static function (array $p): RulePart {
			$part = new RulePart();
			$part->setAccountId($p[0]);
			$part->setAmountCents($p[1]);
			$part->setCostCenterId($p[2] ?? null);
			return $part;
		}, $parts));
		return $rule;
	}

	private function fixed(): Rule {
		return $this->rule([[10, 1000], [11, 500, 7], [12, 300]]);
	}

	public function testOhneTeileGanzerBetragAufsGegenkonto(): void {
		$this->assertSame(
			[['accountId' => 5, 'amountCents' => 6000, 'costCenterId' => 3]],
			RuleSplit::parts($this->rule([]), -6000),
		);
	}

	public function testFesteTeileUndRest(): void {
		$this->assertSame([
			['accountId' => 10, 'amountCents' => 1000, 'costCenterId' => null],
			['accountId' => 11, 'amountCents' => 500, 'costCenterId' => 7],
			['accountId' => 12, 'amountCents' => 300, 'costCenterId' => null],
			['accountId' => 5, 'amountCents' => 4200, 'costCenterId' => 3],
		], RuleSplit::parts($this->fixed(), 6000));
	}

	public function testRestNullOhneRestteil(): void {
		$parts = RuleSplit::parts($this->fixed(), 1800);
		$this->assertNotNull($parts);
		$this->assertCount(3, $parts);
		$this->assertNotContains(5, array_column($parts, 'accountId'));
	}

	public function testAusgangGleichWieEingang(): void {
		$this->assertSame(RuleSplit::parts($this->fixed(), 6000), RuleSplit::parts($this->fixed(), -6000));
	}

	public function testZuKleinLiefertNull(): void {
		$this->assertNull(RuleSplit::parts($this->fixed(), 1200));
	}
}
