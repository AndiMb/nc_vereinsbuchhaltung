<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\ReadCache;
use PHPUnit\Framework\TestCase;

/**
 * Lese-Puffer der Aufgabenliste (Issue #252): wirkt nur innerhalb von
 * during(), lädt dort jeden Bestand einmal und gibt Kopien heraus.
 */
class ReadCacheTest extends TestCase {

	private function member(int $id, string $name): Member {
		$m = new Member();
		$m->setId($id);
		$m->setLastName($name);
		return $m;
	}

	public function testOhneDuringLaedtJederAufrufNeu(): void {
		$cache = new ReadCache();
		$loads = 0;
		$load = function () use (&$loads): array {
			$loads++;
			return [$this->member(1, 'A')];
		};

		$cache->entities('members', $load);
		$cache->entities('members', $load);

		$this->assertSame(2, $loads);
		$this->assertFalse($cache->isActive());
		$this->assertNull($cache->byId('members', $load, 1));
	}

	public function testInnerhalbVonDuringEinmalGeladen(): void {
		$cache = new ReadCache();
		$loads = 0;
		$load = function () use (&$loads): array {
			$loads++;
			return [$this->member(1, 'A'), $this->member(2, 'B')];
		};

		$cache->during(function () use ($cache, $load): void {
			$cache->entities('members', $load);
			$cache->entities('members', $load);
			$this->assertSame('B', $cache->byId('members', $load, 2)?->getLastName());
			$this->assertNull($cache->byId('members', $load, 99));
		});

		$this->assertSame(1, $loads);
		$this->assertFalse($cache->isActive());
	}

	public function testNachDuringIstDerPufferLeer(): void {
		$cache = new ReadCache();
		$loads = 0;
		$load = function () use (&$loads): array {
			$loads++;
			return [];
		};

		$cache->during(fn () => $cache->entities('members', $load));
		$cache->during(fn () => $cache->entities('members', $load));

		$this->assertSame(2, $loads);
	}

	public function testVeraenderteKopieBeruehrtDenPufferNicht(): void {
		$cache = new ReadCache();
		$load = fn (): array => [$this->member(1, 'Original')];

		$cache->during(function () use ($cache, $load): void {
			$cache->entities('members', $load)[0]->setLastName('Geändert');
			$cache->byId('members', $load, 1)?->setLastName('Auch geändert');

			$this->assertSame('Original', $cache->entities('members', $load)[0]->getLastName());
			$this->assertSame('Original', $cache->byId('members', $load, 1)?->getLastName());
		});
	}

	public function testVerschachteltNutztDenAeusserenPuffer(): void {
		$cache = new ReadCache();
		$loads = 0;
		$load = function () use (&$loads): array {
			$loads++;
			return [];
		};

		$cache->during(function () use ($cache, $load): void {
			$cache->entities('members', $load);
			$cache->during(fn () => $cache->entities('members', $load));
			$this->assertTrue($cache->isActive());
		});

		$this->assertSame(1, $loads);
	}

	public function testDerPufferEndetAuchBeiEinerAusnahme(): void {
		$cache = new ReadCache();
		try {
			$cache->during(static function (): void {
				throw new \RuntimeException('Abbruch');
			});
		} catch (\RuntimeException) {
		}
		$this->assertFalse($cache->isActive());
	}
}
