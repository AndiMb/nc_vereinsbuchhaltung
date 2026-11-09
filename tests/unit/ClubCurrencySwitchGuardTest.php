<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\ClubCurrencySwitchGuard;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Issue #202: Weg von Euro nur ohne laufende SEPA-Lastschrift – sonst blieben
 * Mandate und freigegebene Sammeleinzüge halbfertig zurück. Zurück auf Euro
 * geht immer.
 */
class ClubCurrencySwitchGuardTest extends TestCase {

	private function guard(string $current, int $liveMandates, int $releasedBatches): ClubCurrencySwitchGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $key === ClubCurrency::SETTING ? $current : $default
		);
		$mandates = $this->createMock(MandateMapper::class);
		$mandates->method('countNotEnded')->willReturn($liveMandates);
		$batches = $this->createMock(DebitBatchMapper::class);
		$batches->method('findReleasedNotSubmitted')->willReturn(array_fill(0, $releasedBatches, new DebitBatch()));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
		return new ClubCurrencySwitchGuard(new ClubCurrency($config), $mandates, $batches, $l10n);
	}

	public function testSupportedCurrencyWithoutSepaIsAllowed(): void {
		$this->assertNull($this->guard('EUR', 0, 0)->error('CHF'));
	}

	public function testUnsupportedCurrencyIsRejected(): void {
		$this->assertNotNull($this->guard('EUR', 0, 0)->error('USD'));
	}

	public function testSwitchAwayFromEuroWithLiveMandatesIsBlocked(): void {
		$error = $this->guard('EUR', 3, 0)->error('CHF');
		$this->assertNotNull($error);
		$this->assertStringContainsString('3', $error);
	}

	public function testSwitchAwayFromEuroWithReleasedBatchIsBlocked(): void {
		$this->assertNotNull($this->guard('EUR', 0, 1)->error('CHF'));
	}

	public function testSwitchBackToEuroIsAlwaysAllowed(): void {
		$this->assertNull($this->guard('CHF', 3, 1)->error('EUR'));
	}

	public function testUnchangedEuroIsAlwaysAllowed(): void {
		$this->assertNull($this->guard('EUR', 3, 1)->error('EUR'));
	}

	public function testSwitchBetweenNonEuroCurrenciesIsAllowed(): void {
		$this->assertNull($this->guard('CHF', 3, 1)->error('GBP'));
	}
}
