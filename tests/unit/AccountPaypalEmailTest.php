<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

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
use PHPUnit\Framework\TestCase;

/**
 * PayPal-Adresse am Geldkonto (Issue #375): nur ein Geldkonto trägt sie, und
 * sie wird in Normalform gespeichert.
 */
class AccountPaypalEmailTest extends TestCase {

	public function testGeldkontoSpeichertNormalisiert(): void {
		$account = $this->service(null)->create('u', '1200', 'PayPal', 'asset', null, true, paypalEmail: 'Kasse@Example.org');

		$this->assertSame('kasse@example.org', $account->getPaypalEmail());
		$this->assertSame('kasse@example.org', $account->jsonSerialize()['paypalEmail']);
	}

	public function testNichtGeldkontoVerwirft(): void {
		$account = $this->service(null)->create('u', '1200', 'PayPal', 'asset', null, false, paypalEmail: 'kasse@example.org');

		$this->assertNull($account->getPaypalEmail());
	}

	public function testWegfallDesGeldkontoKennzeichensLeert(): void {
		$existing = new Account();
		$existing->setUserId('u');
		$existing->setNumber('1200');
		$existing->setName('PayPal');
		$existing->setType('asset');
		$existing->setIsBank(true);
		$existing->setPaypalEmail('kasse@example.org');

		$saved = $this->service($existing)->update(1, 'u', ['isBank' => false]);

		$this->assertNull($saved->getPaypalEmail());
	}

	public function testUpdateLeerstringLoescht(): void {
		$existing = new Account();
		$existing->setUserId('u');
		$existing->setNumber('1200');
		$existing->setName('PayPal');
		$existing->setType('asset');
		$existing->setIsBank(true);
		$existing->setPaypalEmail('kasse@example.org');

		$saved = $this->service($existing)->update(1, 'u', ['paypalEmail' => '']);

		$this->assertNull($saved->getPaypalEmail());
	}

	private function service(?Account $existing): AccountService {
		$mapper = $this->createMock(AccountMapper::class);
		if ($existing !== null) {
			$mapper->method('find')->willReturn($existing);
		}
		$mapper->method('findByNumber')->willReturn(null);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('update')->willReturnArgument(0);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$locker = $this->createMock(AccountEvaluationLock::class);

		return new AccountService(
			$mapper,
			$this->createMock(JournalLineMapper::class),
			$this->createMock(RuleMapper::class),
			$this->createMock(BudgetMapper::class),
			$this->createMock(TransactionRunner::class),
			new RowNormalizer(),
			$this->createMock(CostCenterService::class),
			$locker,
			$this->createMock(AuditService::class),
			$this->createMock(IbanValidator::class),
			$this->createMock(SepaDebtorAccountService::class),
			$this->createMock(SepaImportSettingsService::class),
			$l10n,
		);
	}
}
