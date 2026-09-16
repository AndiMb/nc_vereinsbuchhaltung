<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Das Beitragsjahr ist eigenständig vom Geschäftsjahr der Kern-Buchhaltung
 * (Spec §3.3/§4) – dieser Test prüft nur die Einstellung selbst und die
 * Weitergabe als PeriodRule-Raster, nicht PeriodRule::containing() erneut
 * (siehe PeriodRuleTest für die Rasterlogik selbst).
 */
class ContributionYearServiceTest extends TestCase {

	private IConfig&MockObject $config;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
	}

	public function testStandardIstJanuar(): void {
		$this->config->method('getAppValue')->willReturn('1');
		$service = new ContributionYearService($this->config);
		$this->assertSame(1, $service->getStartMonth());
	}

	public function testGespeicherterMonatWirdGelesen(): void {
		$this->config->method('getAppValue')->with(Application::APP_ID, 'fiscal_year_start_month', '1')->willReturn('10');
		$service = new ContributionYearService($this->config);
		$this->assertSame(10, $service->getStartMonth());
	}

	public function testUngueltigerGespeicherterWertFaelltAufStandardZurueck(): void {
		$this->config->method('getAppValue')->willReturn('13');
		$service = new ContributionYearService($this->config);
		$this->assertSame(1, $service->getStartMonth());
	}

	public function testSetStartMonthLehntUngueltigenMonatAb(): void {
		$service = new ContributionYearService($this->config);
		$this->expectException(\InvalidArgumentException::class);
		$service->setStartMonth(13);
	}

	public function testSetStartMonthSchreibtDenWert(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'fiscal_year_start_month', '10');
		$service = new ContributionYearService($this->config);
		$service->setStartMonth(10);
	}

	public function testPeriodRuleForReichtStartMonatUndTurnusWeiter(): void {
		$this->config->method('getAppValue')->willReturn('10');
		$service = new ContributionYearService($this->config);
		$this->assertSame(['startDay' => 1, 'startMonth' => 10, 'lengthMonths' => 3], $service->periodRuleFor(3));
	}

	public function testPeriodRuleForLehntUngueltigenTurnusAb(): void {
		$service = new ContributionYearService($this->config);
		$this->expectException(\InvalidArgumentException::class);
		$service->periodRuleFor(5);
	}

	public function testPeriodContainingNutztDasKonfigurierteRaster(): void {
		$this->config->method('getAppValue')->willReturn('10');
		$service = new ContributionYearService($this->config);
		// Turnus 12 Monate, Startmonat Oktober: der 15. Januar liegt in der
		// Periode 2025-10-01 bis 2026-09-30.
		$this->assertSame(['2025-10-01', '2026-09-30'], $service->periodContaining(12, '2026-01-15'));
	}
}
