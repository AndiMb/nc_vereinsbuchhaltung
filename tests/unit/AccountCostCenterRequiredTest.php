<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AccountEvaluationLock;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\CostCenterService;
use OCA\Vereinsbuchhaltung\Service\IbanValidator;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Kennzeichen „Kostenstelle verpflichtend“ am Konto (Issue #334).
 *
 * Es ist eine Erinnerung, keine Sperre: gespeichert wird es am Konto, und es
 * fällt von selbst weg, sobald das Konto keine Kostenstelle mehr tragen darf.
 * Abgeschlossene Jahre berührt es nicht – es ändert keine Summe.
 */
class AccountCostCenterRequiredTest extends TestCase {
	private const BOOK = Application::BOOK;

	private AccountEvaluationLock&MockObject $lock;

	private function account(string $type = 'expense'): Account {
		$account = new Account();
		$account->setId(7);
		$account->setUserId(self::BOOK);
		$account->setNumber('5300');
		$account->setName('Ausrüstung');
		$account->setType($type);
		$account->setIsBank(false);
		$account->setActive(true);
		return $account;
	}

	private function service(Account $account): AccountService {
		$mapper = $this->createMock(AccountMapper::class);
		$mapper->method('find')->willReturn($account);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('insert')->willReturnArgument(0);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$this->lock = $this->createMock(AccountEvaluationLock::class);

		return new AccountService(
			$mapper,
			$this->createMock(JournalLineMapper::class),
			$this->createMock(RuleMapper::class),
			$this->createMock(BudgetMapper::class),
			$this->createMock(TransactionRunner::class),
			$this->createMock(RowNormalizer::class),
			$this->createMock(CostCenterService::class),
			$this->lock,
			$this->createMock(AuditService::class),
			$this->createMock(IbanValidator::class),
			$this->createMock(SepaDebtorAccountService::class),
			$this->createMock(SepaImportSettingsService::class),
			$l10n,
		);
	}

	public function testStandardIstAus(): void {
		$account = $this->account();
		$this->assertFalse($account->isCostCenterRequired());
		$this->assertFalse($account->jsonSerialize()['costCenterRequired']);
	}

	public function testKennzeichenWirdGespeichertUndAusgegeben(): void {
		$saved = $this->service($this->account())->update(7, self::BOOK, ['costCenterRequired' => true]);

		$this->assertTrue($saved->isCostCenterRequired());
		$this->assertTrue($saved->jsonSerialize()['costCenterRequired']);
	}

	public function testKennzeichenLaesstSichWiederAusschalten(): void {
		$account = $this->account();
		$account->setCostCenterRequired(true);

		$saved = $this->service($account)->update(7, self::BOOK, ['costCenterRequired' => false]);

		$this->assertFalse($saved->isCostCenterRequired());
	}

	public function testOhneAngabeBleibtDasKennzeichen(): void {
		$account = $this->account();
		$account->setCostCenterRequired(true);

		$saved = $this->service($account)->update(7, self::BOOK, ['name' => 'Ausrüstung und Trikots']);

		$this->assertTrue($saved->isCostCenterRequired());
	}

	public function testKennzeichenFaelltWegWennDasKontoKeineKostenstelleMehrTraegt(): void {
		$account = $this->account();
		$account->setCostCenterRequired(true);

		$saved = $this->service($account)->update(7, self::BOOK, ['type' => 'asset']);

		$this->assertFalse($saved->isCostCenterRequired());
	}

	public function testAufEinemBestandskontoLaesstEsSichNichtSetzen(): void {
		$saved = $this->service($this->account('asset'))->update(7, self::BOOK, ['costCenterRequired' => true]);

		$this->assertFalse($saved->isCostCenterRequired());
	}

	public function testDieFestschreibungPrueftDasKennzeichenNicht(): void {
		$service = $this->service($this->account());
		$this->lock->expects($this->never())->method('assertOpen');

		$service->update(7, self::BOOK, ['costCenterRequired' => true]);
	}
}
