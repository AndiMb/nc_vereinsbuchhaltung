<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\WhatsNewService;
use PHPUnit\Framework\TestCase;

/**
 * Der Splash-Screen zeigt sich nur, wenn wirklich eine neuere Version
 * vorliegt. Ein naiver String-Vergleich würde "0.9.0" für neuer als
 * "0.10.0" halten - das ist der eigentliche Fehlerfall, den dieser Test
 * absichern soll.
 */
class WhatsNewServiceTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function paare(): array {
		return [
			'gleiche Version' => ['0.25.0', '0.25.0', 0],
			'einfacher Patch-Unterschied' => ['0.25.1', '0.25.0', 1],
			'zweistellig vs. einstellig' => ['0.10.0', '0.9.0', 1],
			'umgekehrt zweistellig vs. einstellig' => ['0.9.0', '0.10.0', -1],
			'unterschiedliche Segmentanzahl' => ['1.0', '1.0.0', 0],
			'unterschiedliche Segmentanzahl, neuer' => ['1.0.1', '1.0', 1],
		];
	}

	/**
	 * @dataProvider paare
	 */
	public function testVersionCompare(string $a, string $b, int $erwartetesVorzeichen): void {
		$diff = WhatsNewService::versionCompare($a, $b);
		if ($erwartetesVorzeichen === 0) {
			$this->assertSame(0, $diff);
		} elseif ($erwartetesVorzeichen > 0) {
			$this->assertGreaterThan(0, $diff);
		} else {
			$this->assertLessThan(0, $diff);
		}
	}

	public function testIsNewer(): void {
		$this->assertTrue(WhatsNewService::isNewer('0.25.0', '0.24.3'));
		$this->assertFalse(WhatsNewService::isNewer('0.24.3', '0.25.0'));
		$this->assertFalse(WhatsNewService::isNewer('0.25.0', '0.25.0'));
	}

	public function testLeererLastSeenGiltAlsAelter(): void {
		$this->assertTrue(WhatsNewService::isNewer('0.25.0', ''));
	}

	public function testFehlerhafteEingabeWirdAlsNullBehandelt(): void {
		$this->assertSame(0, WhatsNewService::versionCompare('', ''));
		$this->assertTrue(WhatsNewService::isNewer('0.1.0', 'keine-version'));
	}
}
