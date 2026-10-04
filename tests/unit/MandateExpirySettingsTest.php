<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\MandateExpirySettings;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Ablauf-Vorwarnung der Mandate als Einstellung (Spec §4
 * `expiry_warning_days`, Issue #101): Standard 180 Tage, geschützt gegen
 * Tippfehler in beide Richtungen.
 */
class MandateExpirySettingsTest extends TestCase {

	private IConfig&MockObject $config;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
	}

	public function testStandardSindHundertachtzigTage(): void {
		$this->config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

		$this->assertSame(180, (new MandateExpirySettings($this->config))->warningDays());
	}

	public function testGespeicherterWertWirdGelesen(): void {
		$this->config->method('getAppValue')->with(Application::APP_ID, 'expiry_warning_days', '180')->willReturn('90');

		$this->assertSame(90, (new MandateExpirySettings($this->config))->warningDays());
	}

	/** @dataProvider ungueltigeGespeicherteWerte */
	public function testUngueltigerGespeicherterWertFaelltAufStandardZurueck(string $stored): void {
		$this->config->method('getAppValue')->willReturn($stored);

		$this->assertSame(180, (new MandateExpirySettings($this->config))->warningDays());
	}

	/** @return array<string, array{string}> */
	public static function ungueltigeGespeicherteWerte(): array {
		return ['null' => ['0'], 'negativ' => ['-5'], 'zu gross' => ['366'], 'kein Zahlenwert' => ['abc']];
	}

	public function testSetWarningDaysSchreibtDenWert(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'expiry_warning_days', '30');

		(new MandateExpirySettings($this->config))->setWarningDays(30);
	}

	/** @dataProvider ungueltigeEingaben */
	public function testSetWarningDaysLehntUnplausibleWerteAbOhneZuSchreiben(int $days): void {
		$this->config->expects($this->never())->method('setAppValue');

		$this->expectException(\InvalidArgumentException::class);
		(new MandateExpirySettings($this->config))->setWarningDays($days);
	}

	/** @return array<string, array{int}> */
	public static function ungueltigeEingaben(): array {
		return ['null' => [0], 'negativ' => [-1], 'ueber einem Jahr' => [366]];
	}

	public function testGrenzwerteSindErlaubt(): void {
		$this->config->expects($this->exactly(2))->method('setAppValue');
		$settings = new MandateExpirySettings($this->config);

		$settings->setWarningDays(1);
		$settings->setWarningDays(365);
	}
}
