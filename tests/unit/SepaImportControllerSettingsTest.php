<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\SepaImportController;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\Sepa\IncomingPaymentMatchingService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaMatchingService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaSettingsAccountValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SepaImportController::updateSettings() (Issue #101): die Konten-
 * Einstellungen der Rücklastschrift-Verbuchung werden geprüft, bevor etwas
 * geschrieben wird; `0` hebt eine Auswahl auf; ein unverändert mitgesendetes
 * Konto blockiert nichts; die Weiterbelastung verlangt ein Gebührenkonto.
 */
class SepaImportControllerSettingsTest extends TestCase {

	private const FEE_ACCOUNT = 7;
	private const REVENUE_ACCOUNT = 8;
	private const BANK_ACCOUNT = 1;

	private AccountMapper&MockObject $accounts;
	/** @var array<string, string> */
	private array $store = [];

	protected function setUp(): void {
		$this->store = [];
		$this->accounts = $this->createMock(AccountMapper::class);
		$this->accounts->method('find')->willReturnCallback(function (int $id): Account {
			return match ($id) {
				self::FEE_ACCOUNT => $this->account($id, 'expense'),
				self::REVENUE_ACCOUNT => $this->account($id, 'income'),
				self::BANK_ACCOUNT => $this->account($id, 'asset', isBank: true),
				default => throw new DoesNotExistException('weg'),
			};
		});
	}

	private function account(int $id, string $type, bool $isBank = false): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber((string)(4000 + $id));
		$account->setName('Konto ' . $id);
		$account->setType($type);
		$account->setIsBank($isBank);
		$account->setActive(true);
		return $account;
	}

	private function settings(): SepaImportSettingsService {
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

	private function controller(SepaImportSettingsService $settings): SepaImportController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		return new SepaImportController(
			$this->createMock(IRequest::class),
			$this->createMock(BankTxSepaDetailMapper::class),
			$this->createMock(BankTransactionMapper::class),
			$this->createMock(SepaMatchingService::class),
			$this->createMock(SepaImportConfirmationService::class),
			$this->createMock(IncomingPaymentMatchingService::class),
			$settings,
			new SepaSettingsAccountValidator($this->accounts, $l10n),
			$this->createMock(PermissionService::class),
			$l10n,
		);
	}

	public function testGueltigeKontenWerdenGespeichertUndZurueckgegeben(): void {
		$settings = $this->settings();

		$response = $this->controller($settings)->updateSettings(self::FEE_ACCOUNT, '1', self::REVENUE_ACCOUNT);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['returnFeeAccountId' => self::FEE_ACCOUNT, 'returnFeeRechargeEnabled' => true, 'contributionDefaultAccountId' => self::REVENUE_ACCOUNT], $response->getData());
	}

	public function testErtragskontoAlsGebuehrenkontoWirdAbgelehntUndNichtsWirdGeschrieben(): void {
		$settings = $this->settings();

		$response = $this->controller($settings)->updateSettings(self::REVENUE_ACCOUNT, null, self::REVENUE_ACCOUNT);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Aufwandskonto', $response->getData()['message']);
		$this->assertNull($settings->returnFeeAccountId());
		$this->assertNull($settings->contributionDefaultAccountId(), 'auch das zweite, gültige Konto wird nicht halb gespeichert');
	}

	public function testGeldkontoWirdAbgelehnt(): void {
		$response = $this->controller($this->settings())->updateSettings(null, null, self::BANK_ACCOUNT);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Geldkonto', $response->getData()['message']);
	}

	public function testUnbekanntesKontoWirdAbgelehnt(): void {
		$response = $this->controller($this->settings())->updateSettings(999, null, null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('nicht gefunden', $response->getData()['message']);
	}

	public function testNullHebtDieAuswahlAuf(): void {
		$settings = $this->settings();
		$settings->setReturnFeeAccountId(self::FEE_ACCOUNT);
		$settings->setContributionDefaultAccountId(self::REVENUE_ACCOUNT);

		$response = $this->controller($settings)->updateSettings(0, null, 0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($settings->returnFeeAccountId());
		$this->assertNull($settings->contributionDefaultAccountId());
	}

	public function testNichtGesendeteFelderBleibenUnangetastet(): void {
		$settings = $this->settings();
		$settings->setReturnFeeAccountId(self::FEE_ACCOUNT);

		$this->controller($settings)->updateSettings(null, null, self::REVENUE_ACCOUNT);

		$this->assertSame(self::FEE_ACCOUNT, $settings->returnFeeAccountId());
		$this->assertSame(self::REVENUE_ACCOUNT, $settings->contributionDefaultAccountId());
	}

	/** Die Einstellungsseite sendet immer alle Felder: ein inzwischen ungültiges, aber unverändertes Konto darf nichts blockieren. */
	public function testUnveraendertesUngueltigGewordenesKontoBlockiertNichts(): void {
		$settings = $this->settings();
		// Das gespeicherte Gebührenkonto gibt es nicht mehr (z. B. von Hand aus der Datenbank entfernt)
		$settings->setReturnFeeAccountId(999);

		$response = $this->controller($settings)->updateSettings(999, '0', self::REVENUE_ACCOUNT);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::REVENUE_ACCOUNT, $settings->contributionDefaultAccountId());
	}

	public function testWeiterbelastungOhneGebuehrenkontoWirdAbgelehnt(): void {
		$settings = $this->settings();

		$response = $this->controller($settings)->updateSettings(null, '1', null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Konto für Rücklastschriftgebühren', $response->getData()['message']);
		$this->assertFalse($settings->isReturnFeeRechargeEnabled());
	}

	public function testWeiterbelastungMitGleichzeitigGewaehltemKontoIstErlaubt(): void {
		$settings = $this->settings();

		$response = $this->controller($settings)->updateSettings(self::FEE_ACCOUNT, '1', null);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($settings->isReturnFeeRechargeEnabled());
	}

	public function testKontoAbwaehlenWaehrendDieWeiterbelastungAnBleibtWirdAbgelehnt(): void {
		$settings = $this->settings();
		$settings->setReturnFeeAccountId(self::FEE_ACCOUNT);
		$settings->setReturnFeeRechargeEnabled(true);

		$response = $this->controller($settings)->updateSettings(0, null, null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(self::FEE_ACCOUNT, $settings->returnFeeAccountId(), 'das Konto bleibt stehen, solange die Weiterbelastung daran hängt');
	}

	public function testKontoAbwaehlenUndWeiterbelastungAusschaltenGehtZusammen(): void {
		$settings = $this->settings();
		$settings->setReturnFeeAccountId(self::FEE_ACCOUNT);
		$settings->setReturnFeeRechargeEnabled(true);

		$response = $this->controller($settings)->updateSettings(0, '0', null);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($settings->returnFeeAccountId());
		$this->assertFalse($settings->isReturnFeeRechargeEnabled());
	}
}
