<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use PHPUnit\Framework\TestCase;

/**
 * „Der Zeitraum ist der Status" (Spec §2.2) – dasselbe Prinzip wie
 * {@see \OCA\Vereinsbuchhaltung\Db\Member::isActive()}.
 */
class AssignmentTest extends TestCase {

	private function assignment(string $validFrom, ?string $validTo): Assignment {
		$a = new Assignment();
		$a->setValidFrom($validFrom);
		$a->setValidTo($validTo);
		return $a;
	}

	public function testAktivOhneValidToIstImmerAktiv(): void {
		$this->assertTrue($this->assignment('2026-01-01', null)->isActive('2030-01-01'));
	}

	public function testNochNichtBegonnenIstNichtAktiv(): void {
		$this->assertFalse($this->assignment('2026-06-01', null)->isActive('2026-01-01'));
	}

	public function testBeendeteZuweisungIstNichtMehrAktiv(): void {
		$this->assertFalse($this->assignment('2026-01-01', '2026-06-30')->isActive('2026-07-01'));
	}

	public function testAmLetztenGueltigenTagNochAktiv(): void {
		$this->assertTrue($this->assignment('2026-01-01', '2026-06-30')->isActive('2026-06-30'));
	}

	public function testEffectiveMinOhneOverrideIstGruppenUntergrenze(): void {
		$group = new ContributionGroup();
		$group->setMinMonthlyAmountCents(500);
		$assignment = new Assignment();
		$this->assertSame(500, $assignment->effectiveMinMonthlyAmountCents($group));
	}

	public function testEffectiveMinMitOverrideErsetztDieGruppenUntergrenze(): void {
		$group = new ContributionGroup();
		$group->setMinMonthlyAmountCents(500);
		$assignment = new Assignment();
		$assignment->setMinMonthlyAmountOverrideCents(200);
		$this->assertSame(200, $assignment->effectiveMinMonthlyAmountCents($group));
	}
}
