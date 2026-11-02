<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\OpenItem;
use PHPUnit\Framework\TestCase;

/**
 * Issue #263: Überfällig ist ein Posten ab dem Kalendertag nach seiner
 * Fälligkeit – gemessen am Tag des Vereins, nicht am UTC-Tag.
 */
class OpenItemTest extends TestCase {

	private function item(string $dueDate, string $status = 'open'): OpenItem {
		$item = new OpenItem();
		$item->setStatus($status);
		$item->setDueDate($dueDate);
		return $item;
	}

	public function testUeberfaelligNachDeutschemKalendertag(): void {
		$this->assertTrue($this->item('2026-06-30')->isOverdue('2026-07-01'));
		$this->assertFalse($this->item('2026-07-01')->isOverdue('2026-07-01'));
	}

	public function testBezahlterPostenIstNieUeberfaellig(): void {
		$this->assertFalse($this->item('2026-06-30', 'paid')->isOverdue('2026-07-01'));
	}
}
