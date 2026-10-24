<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Migration\EntityRepairPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Welche gespeicherten Texte die Migration 000162 repariert (Issue #112).
 */
class EntityRepairPlannerTest extends TestCase {

	private const JOURNAL = EntityRepairPlanner::COLUMNS['vbh_journal'];

	public function testNurBetroffeneFelderLandenImPlan(): void {
		$plan = EntityRepairPlanner::plan([
			['id' => '1', 'description' => 'Beitrag M&amp;#252;ller', 'document_ref' => 'B12'],
			['id' => '2', 'description' => 'Kaffee & Kuchen', 'document_ref' => null],
			['id' => '3', 'description' => 'Rechnung #12;', 'document_ref' => 'Kl#228;r1'],
		], self::JOURNAL);

		$this->assertSame([
			['id' => 1, 'column' => 'description', 'before' => 'Beitrag M&amp;#252;ller', 'after' => 'Beitrag Müller'],
			['id' => 3, 'column' => 'document_ref', 'before' => 'Kl#228;r1', 'after' => 'Klär1'],
		], $plan);
	}

	public function testZweiterLaufAendertNichts(): void {
		$rows = [
			['id' => 1, 'description' => 'M&amp;#252;ller#20;2026', 'document_ref' => 'B&amp;#252;'],
		];
		$plan = EntityRepairPlanner::plan($rows, self::JOURNAL);
		$this->assertCount(2, $plan);

		foreach ($plan as $change) {
			$rows[0][$change['column']] = $change['after'];
		}
		$this->assertSame([], EntityRepairPlanner::plan($rows, self::JOURNAL));
	}

	public function testKontonamenWerdenRepariert(): void {
		$plan = EntityRepairPlanner::plan(
			[['id' => 7, 'name' => 'Spenden f&#252;r Jugend']],
			EntityRepairPlanner::COLUMNS['vbh_accounts'],
		);

		$this->assertSame('Spenden für Jugend', $plan[0]['after']);
	}

	/** Lieber ein unschöner Text als ein verschwundener. */
	public function testEinTextWirdNieLeer(): void {
		$this->assertSame([], EntityRepairPlanner::plan([['id' => 1, 'description' => '&#20;', 'document_ref' => null]], self::JOURNAL));
	}

	public function testBelegnummerBleibtInIhrerLaenge(): void {
		$plan = EntityRepairPlanner::plan([['id' => 1, 'description' => null, 'document_ref' => str_repeat('a', 60) . '&amp;#252;']], self::JOURNAL);

		$this->assertSame(str_repeat('a', 60) . 'ü', $plan[0]['after']);
		$this->assertLessThanOrEqual(64, mb_strlen($plan[0]['after']));
	}
}
