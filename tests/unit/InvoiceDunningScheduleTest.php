<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDunningSchedule;
use PHPUnit\Framework\TestCase;

/** Wann die nächste Mahnstufe fällig wird: Stufe 1 nach dem Ziel, die übrigen nach der Vorstufe. */
class InvoiceDunningScheduleTest extends TestCase {

	private const DAYS = [1 => 7, 2 => 14, 3 => 21];

	public function testErinnerungFolgtDemZahlungszielNachSiebenTagen(): void {
		$this->assertSame(['level' => 1, 'dueOn' => '2026-03-26'], InvoiceDunningSchedule::next('2026-03-19', 0, null, self::DAYS));
	}

	public function testDieErsteMahnungFolgtDerErinnerungUndNichtDemZiel(): void {
		$this->assertSame(['level' => 2, 'dueOn' => '2026-04-14'], InvoiceDunningSchedule::next('2026-03-19', 1, '2026-03-31T09:00:00+00:00', self::DAYS));
	}

	public function testDieZweiteMahnungFolgtDerErstenMahnung(): void {
		$this->assertSame(['level' => 3, 'dueOn' => '2026-05-05'], InvoiceDunningSchedule::next('2026-03-19', 2, '2026-04-14', self::DAYS));
	}

	public function testNachDerLetztenStufeMahntDieAppNichtMehr(): void {
		$this->assertNull(InvoiceDunningSchedule::next('2026-03-19', 3, '2026-05-05', self::DAYS));
	}

	public function testNullTageBedeutenSofortAmTagNachDemZiel(): void {
		$this->assertSame(['level' => 1, 'dueOn' => '2026-03-19'], InvoiceDunningSchedule::next('2026-03-19', 0, null, [1 => 0, 2 => 0, 3 => 0]));
	}
}
