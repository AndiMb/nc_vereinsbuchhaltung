<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use PHPUnit\Framework\TestCase;

class ContributionGroupTest extends TestCase {

	public function testAllowedIntervalsArraySortiertUndDedupliziert(): void {
		$group = new ContributionGroup();
		$group->setAllowedIntervalsArray([12, 1, 1, 3]);
		$this->assertSame('1,3,12', $group->getAllowedIntervals());
		$this->assertSame([1, 3, 12], $group->getAllowedIntervalsArray());
	}

	public function testLeereAllowedIntervalsErgibtLeeresArray(): void {
		$group = new ContributionGroup();
		$group->setAllowedIntervals('');
		$this->assertSame([], $group->getAllowedIntervalsArray());
	}

	public function testJsonSerializeRechnetCentsInEuroUm(): void {
		$group = new ContributionGroup();
		$group->setMinMonthlyAmountCents(500);
		$group->setDefaultMonthlyAmountCents(800);
		$group->setAllowedIntervalsArray([1, 12]);
		$data = $group->jsonSerialize();
		$this->assertEquals(5.0, $data['minMonthlyAmount']);
		$this->assertEquals(8.0, $data['defaultMonthlyAmount']);
		$this->assertSame([1, 12], $data['allowedIntervals']);
	}

	/**
	 * Regressionstest für einen live beobachteten Bug (PR #83): QBMapper::
	 * insert() schreibt laut eigenem Docblock nur "als geändert markierte"
	 * Felder (Entity::getUpdatedFields()) in die INSERT-Anweisung.
	 * Entity::setter() markiert ein Feld aber nur dann als geändert, wenn
	 * sich der neue Wert vom aktuellen PHP-Property-Wert unterscheidet – ein
	 * frisch angelegtes Objekt, dessen Setter zufällig denselben Wert wie
	 * ein "realistischer" Klassen-Default bekommt (hier: allowedIntervals=
	 * '1,12', defaultInterval=12 – beides die verbreitetsten Werte
	 * überhaupt), ließ die Spalte sonst stillschweigend aus dem INSERT aus
	 * und schlug an der NOT-NULL-Constraint fehl (beobachtet: SQLSTATE[23000]
	 * NOT NULL constraint failed: allowed_intervals, ausgelöst durch genau
	 * die Werte, die ContributionGroupDialog.vue als Vorbelegung anbietet).
	 * Ohne echte Datenbank reproduzierbar, weil {@see OCP\AppFramework\Db\Entity}
	 * eine vollständige, nicht gemockte Basisklasse ist.
	 */
	public function testAlleFelderSindNachDemSetzenAlsGeaendertMarkiert(): void {
		$group = new ContributionGroup();
		$group->setName('Test');
		$group->setMinMonthlyAmountCents(500);
		$group->setDefaultMonthlyAmountCents(800);
		$group->setAllowedIntervalsArray([1, 12]);
		$group->setDefaultInterval(12);
		$group->setIsActive(true);
		$group->setCreatedAt('2026-01-01');

		$updated = array_keys($group->getUpdatedFields());
		foreach (['name', 'minMonthlyAmountCents', 'defaultMonthlyAmountCents', 'allowedIntervals', 'defaultInterval', 'isActive', 'createdAt'] as $field) {
			$this->assertContains($field, $updated, "Feld '$field' fehlt in getUpdatedFields() - QBMapper::insert() würde die Spalte auslassen.");
		}
	}
}
