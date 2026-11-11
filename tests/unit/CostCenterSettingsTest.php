<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Der Schalter für Kostenstellen an der Buchungszeile (Issue #7) ist
 * standardmäßig aus: bestehende Installationen merken von der Funktion
 * nichts, solange niemand sie einschaltet.
 */
class CostCenterSettingsTest extends TestCase {

	/** @var array<string, string> */
	private array $store = [];

	private function settings(): CostCenterSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		return new CostCenterSettings($config);
	}

	public function testStandardIstAus(): void {
		$this->assertFalse($this->settings()->isEnabled());
	}

	public function testEinsHeisstEin(): void {
		$this->store['cost_centers_enabled'] = '1';
		$this->assertTrue($this->settings()->isEnabled());
	}

	public function testUnbekannterWertHeisstAus(): void {
		$this->store['cost_centers_enabled'] = 'yes';
		$this->assertFalse($this->settings()->isEnabled());
	}

	public function testSchluesselHeisstWieInDerSpezifikation(): void {
		$this->assertSame('cost_centers_enabled', CostCenterSettings::SETTING_ENABLED);
	}
}
