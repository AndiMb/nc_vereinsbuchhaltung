<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Issue #263: „heute“ ist der Kalendertag des Vereins (Server-Zeitzone,
 * sonst Europe/Berlin), nicht der UTC-Tag. Zwischen 00:00 und 02:00 Uhr
 * deutscher Zeit liegen die beiden auseinander.
 */
class ClubClockTest extends TestCase {

	protected function tearDown(): void {
		ClubClock::configureZone('');
	}

	private function clockAt(string $utc): ClubClock {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($utc, new \DateTimeZone('UTC')));
		return new ClubClock($time);
	}

	public function testKurzNachMitternachtIstSchonDerNeueTag(): void {
		ClubClock::configureZone('Europe/Berlin');
		$this->assertSame('2026-07-01', $this->clockAt('2026-06-30 22:30:00')->today());
	}

	public function testWinterzeit(): void {
		ClubClock::configureZone('Europe/Berlin');
		$this->assertSame('2027-01-01', $this->clockAt('2026-12-31 23:30:00')->today());
	}

	public function testVorMitternachtBleibtDerAlteTag(): void {
		ClubClock::configureZone('Europe/Berlin');
		$this->assertSame('2026-06-30', $this->clockAt('2026-06-30 21:30:00')->today());
	}

	public function testNowLiegtInDerVereinszone(): void {
		ClubClock::configureZone('Europe/Berlin');
		$now = $this->clockAt('2026-06-30 22:30:00')->now();
		$this->assertSame('Europe/Berlin', $now->getTimezone()->getName());
		$this->assertSame('2026-07-01 00:30:00', $now->format('Y-m-d H:i:s'));
	}

	public function testAndereZoneDesServers(): void {
		ClubClock::configureZone('America/New_York');
		$this->assertSame('America/New_York', ClubClock::zone()->getName());
		$this->assertSame('2026-06-30', $this->clockAt('2026-07-01 02:30:00')->today());
	}

	public function testUngueltigeZoneFaelltAufBerlinZurueck(): void {
		ClubClock::configureZone('Mars/Olympus');
		$this->assertSame('Europe/Berlin', ClubClock::zone()->getName());
	}

	public function testLeereZoneFaelltAufBerlinZurueck(): void {
		ClubClock::configureZone('America/New_York');
		ClubClock::configureZone('');
		$this->assertSame('Europe/Berlin', ClubClock::zone()->getName());
	}

	/** Gespeicherte UTC-Zeitstempel (ATOM oder 'Y-m-d H:i:s') werden zum Kalendertag des Vereins. */
	public function testLocalDateRechnetUtcZeitstempelInDenVereinstagUm(): void {
		ClubClock::configureZone('Europe/Berlin');
		$this->assertSame('2026-07-01', ClubClock::localDate('2026-06-30T22:30:00+00:00'));
		$this->assertSame('2026-07-01', ClubClock::localDate('2026-06-30 22:30:00'));
		$this->assertSame('2026-06-30', ClubClock::localDate('2026-06-30 21:30:00'));
		$this->assertSame('2027-01-01', ClubClock::localDate('2026-12-31 23:30:00'));
	}

	public function testLocalDateLaesstReineTagesdatenUnveraendert(): void {
		ClubClock::configureZone('America/New_York');
		$this->assertSame('2026-07-01', ClubClock::localDate('2026-07-01'));
		$this->assertSame('', ClubClock::localDate(''));
		$this->assertSame('kein Datum', ClubClock::localDate('kein Datum'));
	}

	public function testTodayFromSystemClockNutztDieVereinszone(): void {
		ClubClock::configureZone('Europe/Berlin');
		$expected = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
		$this->assertSame($expected, ClubClock::todayFromSystemClock());
	}
}
