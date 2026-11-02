<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Die Konten-Einstellungen der Rücklastschrift-Verbuchung räumen sich mit
 * ihrem Konto ab (Issue #101): löscht jemand das Konto oder setzt den
 * Bestand zurück, darf keine ID stehen bleiben, die erst bei der nächsten
 * Verbuchung ins Leere läuft.
 */
class SepaImportSettingsServiceTest extends TestCase {

	private IAppConfig $config;

	private function service(): SepaImportSettingsService {
		return new SepaImportSettingsService($this->config);
	}

	protected function setUp(): void {
		$this->config = AppConfigStub::create($this);
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
		$this->assertArrayNotHasKey('sepa_return_fee_account_id', AppConfigStub::values($this->config));
	}
}
