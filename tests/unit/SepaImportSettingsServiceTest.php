<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Die Konten-Einstellungen der Rücklastschrift-Verbuchung räumen sich mit
 * ihrem Konto ab (Issue #101): löscht jemand das Konto oder setzt den
 * Bestand zurück, darf keine ID stehen bleiben, die erst bei der nächsten
 * Verbuchung ins Leere läuft.
 */
class SepaImportSettingsServiceTest extends TestCase {

	/** @var array<string, string> */
	private array $store = [];

	private function service(): SepaImportSettingsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->store[$key] = $value;
		});
		$config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key): void {
			unset($this->store[$key]);
		});
		return new SepaImportSettingsService($config);
	}

	protected function setUp(): void {
		$this->store = [];
	}

	public function testForgetIfSetToRaeumtNurDasPassendeKontoAb(): void {
		$service = $this->service();
		$service->setReturnFeeAccountId(5);
		$service->setContributionDefaultAccountId(8);

		$service->forgetIfSetTo(5);

		$this->assertNull($service->returnFeeAccountId());
		$this->assertSame(8, $service->contributionDefaultAccountId(), 'das andere Konto bleibt unberührt');
	}

	public function testForgetIfSetToRaeumtBeideAbWennDasselbeKontoBeidesIst(): void {
		$service = $this->service();
		$service->setReturnFeeAccountId(5);
		$service->setContributionDefaultAccountId(5);

		$service->forgetIfSetTo(5);

		$this->assertNull($service->returnFeeAccountId());
		$this->assertNull($service->contributionDefaultAccountId());
	}

	public function testForgetIfSetToLaesstFremdeKontenStehen(): void {
		$service = $this->service();
		$service->setReturnFeeAccountId(5);

		$service->forgetIfSetTo(99);

		$this->assertSame(5, $service->returnFeeAccountId());
	}

	public function testForgetAccountsLeertBeideEinstellungen(): void {
		$service = $this->service();
		$service->setReturnFeeAccountId(5);
		$service->setContributionDefaultAccountId(8);

		$service->forgetAccounts();

		$this->assertNull($service->returnFeeAccountId());
		$this->assertNull($service->contributionDefaultAccountId());
		$this->assertArrayNotHasKey('sepa_return_fee_account_id', $this->store);
	}
}
