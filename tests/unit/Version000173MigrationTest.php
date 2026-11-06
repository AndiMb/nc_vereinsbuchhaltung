<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Migration\Version000173Date20261103000000;
use PHPUnit\Framework\TestCase;

/**
 * Nachträgliche Auslagennummern für bestehende Anträge: je Einreichungsjahr von 1 an,
 * in der Reihenfolge der Anträge; der Zähler läuft danach dort weiter.
 */
class Version000173MigrationTest extends TestCase {

	public function testNummertJeEinreichungsjahrDurch(): void {
		$rows = [
			['id' => 3, 'submitted_at' => '2025-12-30 10:00:00'],
			['id' => 5, 'submitted_at' => '2026-01-02 09:00:00'],
			['id' => 8, 'submitted_at' => '2026-10-31 18:30:00'],
		];

		$result = Version000173Date20261103000000::numberRows($rows, 'AUSL-{JJJJ}-{NNNN}');

		$this->assertSame([3 => 'AUSL-2025-0001', 5 => 'AUSL-2026-0001', 8 => 'AUSL-2026-0002'], $result['numbers']);
		$this->assertSame(3, $result['next']);
		$this->assertSame('2026', $result['counterKey']);
	}
}
