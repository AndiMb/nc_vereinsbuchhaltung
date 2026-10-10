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

	/**
	 * Regressionstest für einen live beobachteten Bug (PR #83, siehe
	 * ContributionGroupTest::testAlleFelderSindNachDemSetzenAlsGeaendertMarkiert()
	 * für die ausführliche Begründung): intervalMonths=12 ist der mit Abstand
	 * häufigste Turnus (AssignmentDialog.vue-Vorbelegung) – ein Klassen-
	 * Default von 12 hätte QBMapper::insert() die Spalte für genau diesen,
	 * alltäglichen Fall auslassen lassen und an der NOT-NULL-Constraint
	 * scheitern lassen.
	 */
	public function testAlleFelderSindNachDemSetzenAlsGeaendertMarkiert(): void {
		$assignment = new Assignment();
		$assignment->setMemberId(1);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(0);
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$assignment->setValidFrom('2026-01-01');
		$assignment->setCreatedAt('2026-01-01');

		$updated = array_keys($assignment->getUpdatedFields());
		foreach (['memberId', 'groupId', 'intervalMonths', 'monthlyAmountCents', 'paymentMethod', 'validFrom', 'createdAt'] as $field) {
			$this->assertContains($field, $updated, "Feld '$field' fehlt in getUpdatedFields() - QBMapper::insert() würde die Spalte auslassen.");
		}
	}
}
