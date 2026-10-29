<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCA\Vereinsbuchhaltung\Service\InvoiceBankAccountService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Bankverbindung der Rechnung (Issue #215): eigene Wahl, sonst SEPA-Einzugskonto,
 * sonst das einzige Geldkonto mit IBAN – und wohin der Editor bei Fehlen verlinkt.
 */
class InvoiceBankAccountServiceTest extends TestCase {

	/** @var array<string, string> */
	private array $config = [];
	/** @var array<int, Account> */
	private array $rows = [];

	private function account(int $id, string $iban, bool $isBank = true, bool $active = true): void {
		$a = new Account();
		$a->setId($id);
		$a->setIsBank($isBank);
		$a->setActive($active);
		$a->setIban($iban === '' ? null : $iban);
		$this->rows[$id] = $a;
	}

	private function service(): InvoiceBankAccountService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->config[$key] ?? $default);
		$l10n = $this->createMock(IL10N::class);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturnCallback(fn (int $id) => $this->rows[$id] ?? throw new DoesNotExistException(''));
		$accounts->method('findAll')->willReturnCallback(fn () => array_values($this->rows));
		return new InvoiceBankAccountService(
			new InvoiceSettings($config, new FolderPathValidator($l10n), $l10n),
			new SepaDebtorAccountService($config),
			$accounts,
		);
	}

	public function testEigeneWahlGehtVor(): void {
		$this->account(1, 'DE02120300000000202051');
		$this->account(2, 'DE02500105170137075030');
		$this->config = ['invoice_account_id' => '2', 'sepa_debtor_account_id' => '1'];

		$this->assertSame(2, $this->service()->resolve()?->getId());
		$this->assertNull($this->service()->fix());
	}

	public function testEigeneWahlOhneIbanWeichtNichtAus(): void {
		$this->account(1, 'DE02120300000000202051');
		$this->account(2, '');
		$this->config = ['invoice_account_id' => '2', 'sepa_debtor_account_id' => '1'];

		$this->assertNull($this->service()->resolve());
		$this->assertSame(['fix' => 'account', 'accountId' => 2], $this->service()->fix());
	}

	public function testOhneWahlGiltDasSepaKonto(): void {
		$this->account(1, 'DE02120300000000202051');
		$this->account(2, 'DE02500105170137075030');
		$this->config = ['sepa_debtor_account_id' => '2'];

		$this->assertSame(2, $this->service()->resolve()?->getId());
	}

	public function testSepaKontoOhneIbanFaelltAufDasEinzigeKontoMitIbanZurueck(): void {
		$this->account(1, 'DE02120300000000202051');
		$this->account(2, '');
		$this->config = ['sepa_debtor_account_id' => '2'];

		$this->assertSame(1, $this->service()->resolve()?->getId());
	}

	public function testEinzigesAktivesGeldkontoMitIban(): void {
		$this->account(1, 'DE02120300000000202051');
		$this->account(2, 'DE02500105170137075030', active: false);
		$this->account(3, 'DE02100100100006820101', isBank: false);
		$this->account(4, '');

		$this->assertSame(1, $this->service()->resolve()?->getId());
	}

	public function testMehrereKontenMitIbanVerlangenEineWahl(): void {
		$this->account(1, 'DE02120300000000202051');
		$this->account(2, 'DE02500105170137075030');

		$this->assertNull($this->service()->resolve());
		$this->assertSame(['fix' => 'settings', 'accountId' => null], $this->service()->fix());
	}

	public function testOhneIbanFuehrtZumEinzigenGeldkonto(): void {
		$this->account(1, '');
		$this->account(2, '', active: false);

		$this->assertSame(['fix' => 'account', 'accountId' => 1], $this->service()->fix());
	}

	public function testOhneIbanFuehrtZumSepaKonto(): void {
		$this->account(1, '');
		$this->account(2, '');
		$this->config = ['sepa_debtor_account_id' => '2'];

		$this->assertSame(['fix' => 'account', 'accountId' => 2], $this->service()->fix());
	}

	public function testOhneIbanBeiMehrerenGeldkontenFuehrtZumKontenplan(): void {
		$this->account(1, '');
		$this->account(2, '');

		$this->assertSame(['fix' => 'account', 'accountId' => null], $this->service()->fix());
	}
}
