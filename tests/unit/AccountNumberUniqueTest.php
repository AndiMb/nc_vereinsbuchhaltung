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
use OCP\DB\Exception as DbException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Eine Kontonummer gibt es je Bestand nur einmal (Issue #327). Die Datenbank
 * erzwingt das über einen eindeutigen Index; ohne Prüfung davor kam der
 * Verstoß als interner Serverfehler an statt als lesbare Meldung.
 */
class AccountNumberUniqueTest extends TestCase {
	private const BOOK = Application::BOOK;

	private AccountMapper&MockObject $mapper;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AccountMapper::class);
	}

	private function account(int $id, string $number): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId(self::BOOK);
		$account->setNumber($number);
		$account->setName('Konto ' . $number);
		$account->setType('expense');
		$account->setIsBank(false);
		$account->setActive(true);
		return $account;
	}

	private function service(): AccountService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));

		return new AccountService(
			$this->mapper,
			$this->createMock(JournalLineMapper::class),
			$this->createMock(RuleMapper::class),
			$this->createMock(BudgetMapper::class),
			$this->createMock(TransactionRunner::class),
			$this->createMock(RowNormalizer::class),
			$this->createMock(CostCenterService::class),
			$this->createMock(AccountEvaluationLock::class),
			$this->createMock(AuditService::class),
			$this->createMock(IbanValidator::class),
			$this->createMock(SepaDebtorAccountService::class),
			$this->createMock(SepaImportSettingsService::class),
			$l10n,
		);
	}

	private function uniqueViolation(): DbException {
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $e;
	}

	public function testAnlegenMitVergebenerNummerWirdAbgelehnt(): void {
		$this->mapper->method('findByNumber')->with(self::BOOK, '4711')->willReturn($this->account(3, '4711'));
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Die Kontonummer 4711 ist bereits vergeben.');
		$this->service()->create(self::BOOK, ' 4711 ', 'Doppelt', 'expense', null, false);
	}

	public function testAnlegenMitFreierNummerSpeichert(): void {
		$this->mapper->method('findByNumber')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->assertSame('4712', $this->service()->create(self::BOOK, '4712', 'Neu', 'expense', null, false)->getNumber());
	}

	/** Zwei gleichzeitige Anfragen: beide bestehen die Prüfung, die Datenbank lehnt die zweite ab. */
	public function testAnlegenFaengtDenIndexverstossDerDatenbank(): void {
		$this->mapper->method('findByNumber')->willReturn(null);
		$this->mapper->method('insert')->willThrowException($this->uniqueViolation());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Die Kontonummer 4711 ist bereits vergeben.');
		$this->service()->create(self::BOOK, '4711', 'Doppelt', 'expense', null, false);
	}

	public function testAendernAufVergebeneNummerWirdAbgelehnt(): void {
		$this->mapper->method('find')->willReturn($this->account(7, '5300'));
		$this->mapper->method('findByNumber')->with(self::BOOK, '4711')->willReturn($this->account(3, '4711'));
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Die Kontonummer 4711 ist bereits vergeben.');
		$this->service()->update(7, self::BOOK, ['number' => '4711', 'parentId' => 0]);
	}

	/** Das Konto-Formular schickt die eigene Nummer immer mit – sie ist kein Konflikt. */
	public function testAendernMitEigenerNummerSpeichert(): void {
		$own = $this->account(7, '5300');
		$this->mapper->method('find')->willReturn($own);
		$this->mapper->method('findByNumber')->willReturn($own);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->assertSame('Umbenannt', $this->service()->update(7, self::BOOK, ['number' => '5300', 'name' => 'Umbenannt', 'parentId' => 0])->getName());
	}

	public function testAendernFaengtDenIndexverstossDerDatenbank(): void {
		$this->mapper->method('find')->willReturn($this->account(7, '5300'));
		$this->mapper->method('findByNumber')->willReturn(null);
		$this->mapper->method('update')->willThrowException($this->uniqueViolation());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Die Kontonummer 4711 ist bereits vergeben.');
		$this->service()->update(7, self::BOOK, ['number' => '4711', 'parentId' => 0]);
	}

	/** Ein anderer Datenbankfehler bleibt, was er ist. */
	public function testAndererDatenbankfehlerWirdNichtUmgedeutet(): void {
		$other = $this->createMock(DbException::class);
		$other->method('getReason')->willReturn(DbException::REASON_CONNECTION_LOST);
		$this->mapper->method('findByNumber')->willReturn(null);
		$this->mapper->method('insert')->willThrowException($other);

		$this->expectException(DbException::class);
		$this->service()->create(self::BOOK, '4711', 'Neu', 'expense', null, false);
	}
}
