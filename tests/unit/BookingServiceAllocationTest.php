<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\CostCenter\LineAllocationWriter;
use OCA\Vereinsbuchhaltung\Service\EntryNumberService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Kostenstellen bei der Zuordnung eines Bankumsatzes (Issue #7).
 *
 * Eine erneute Zuordnung löscht den alten Buchungssatz und legt einen neuen
 * an. Dabei darf weder eine Verteilung verloren gehen, zu der niemand etwas
 * gesagt hat, noch eine an gelöschten Zeilen hängen bleiben.
 */
class BookingServiceAllocationTest extends TestCase {

	private const BANK = 1200;
	private const OLD_JOURNAL = 9;
	private const NEW_JOURNAL = 10;

	private LineAllocationWriter&MockObject $writer;
	/** @var list<string> Reihenfolge der Aufrufe am Schreiber und am Zeilen-Mapper */
	private array $calls = [];
	/** @var array{journalId:int, lines:array, snapshot:array}|null */
	private ?array $applied = null;
	/** @var list<array<string, mixed>> */
	private array $snapshot = [];

	protected function setUp(): void {
		$this->calls = [];
		$this->applied = null;
		$this->snapshot = [];
		$this->writer = $this->createMock(LineAllocationWriter::class);
		$this->writer->method('snapshot')->willReturnCallback(function (int $journalId): array {
			$this->calls[] = 'snapshot ' . $journalId;
			return $this->snapshot;
		});
		$this->writer->method('deleteByJournal')->willReturnCallback(function (int $journalId): void {
			$this->calls[] = 'deleteAllocations ' . $journalId;
		});
		$this->writer->method('apply')->willReturnCallback(function (int $journalId, string $userId, array $lines, array $snapshot = []): string {
			$this->calls[] = 'apply ' . $journalId;
			$this->applied = ['journalId' => $journalId, 'lines' => $lines, 'snapshot' => $snapshot];
			return '';
		});
	}

	private function service(): BookingService {
		$journals = $this->createMock(JournalMapper::class);
		$old = new Journal();
		$old->setId(self::OLD_JOURNAL);
		$old->setUserId('__verein__');
		$old->setDate('2026-03-01');
		$old->setPeriodId(1);
		$journals->method('find')->willReturn($old);
		$journals->method('insert')->willReturnCallback(static function (Journal $journal): Journal {
			$journal->setId(self::NEW_JOURNAL);
			return $journal;
		});

		$lines = $this->createMock(JournalLineMapper::class);
		$lines->method('deleteByJournal')->willReturnCallback(function (int $journalId): void {
			$this->calls[] = 'deleteLines ' . $journalId;
		});

		$txs = $this->createMock(BankTransactionMapper::class);
		$txs->method('update')->willReturnArgument(0);

		$accounts = $this->createMock(AccountService::class);
		$accounts->method('find')->willReturnCallback(static function (int $id): Account {
			$account = new Account();
			$account->setId($id);
			$account->setNumber((string)$id);
			$account->setName('Konto ' . $id);
			$account->setType('expense');
			return $account;
		});
		$bank = new Account();
		$bank->setId(self::BANK);
		$bank->setType('asset');
		$accounts->method('resolveBankAccount')->willReturn($bank);

		$period = new Period();
		$period->setId(1);
		$periods = $this->createMock(PeriodService::class);
		$periods->method('forDateOrCreate')->willReturn($period);

		$runner = $this->createMock(TransactionRunner::class);
		$runner->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$runner->method('runWithRetry')->willReturnCallback(static fn (callable $fn) => $fn());
		$runner->method('isActive')->willReturn(false);

		return new BookingService(
			$journals,
			$lines,
			$txs,
			$accounts,
			$this->createMock(AttachmentStorageService::class),
			$periods,
			$this->createMock(AuditService::class),
			$this->createMock(EntryNumberService::class),
			$runner,
			$this->createMock(IL10N::class),
			ClubCurrencyStub::of($this),
			null,
			null,
			$this->writer,
		);
	}

	/** Ausgang über 1.000,00 €, optional schon einem Buchungssatz zugeordnet. */
	private function transaction(?int $journalId = null): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId(5);
		$tx->setUserId('__verein__');
		$tx->setBookingDate('2026-03-01');
		$tx->setAmountCents(-100000);
		$tx->setJournalId($journalId);
		$tx->setStatus($journalId === null ? 'unassigned' : 'assigned');
		return $tx;
	}

	private const SEN_600 = [['costCenterId' => 3, 'amountCents' => 60000]];
	private const AJU_400 = [['costCenterId' => 5, 'amountCents' => 40000]];

	public function testAufteilungReichtVerteilungJeTeilDurch(): void {
		$this->service()->assignParts($this->transaction(), [
			['accountId' => 5300, 'amountCents' => 60000, 'allocations' => self::SEN_600],
			['accountId' => 5400, 'amountCents' => 40000, 'allocations' => self::AJU_400],
		]);

		$this->assertSame(self::NEW_JOURNAL, $this->applied['journalId'] ?? null);
		$this->assertSame([
			['accountId' => 5300, 'debitCents' => 60000, 'creditCents' => 0, 'allocations' => self::SEN_600],
			['accountId' => 5400, 'debitCents' => 40000, 'creditCents' => 0, 'allocations' => self::AJU_400],
			['accountId' => self::BANK, 'debitCents' => 0, 'creditCents' => 100000],
		], $this->applied['lines']);
	}

	public function testEinzelzuordnungReichtVerteilungDurch(): void {
		$this->service()->assign($this->transaction(), 5300, self::SEN_600);
		$this->assertSame(
			['accountId' => 5300, 'debitCents' => 100000, 'creditCents' => 0, 'allocations' => self::SEN_600],
			$this->applied['lines'][0],
		);
	}

	public function testZuordnungOhneAngabeLaesstDenSchluesselWeg(): void {
		$this->service()->assign($this->transaction(), 5300);
		$this->assertArrayNotHasKey('allocations', $this->applied['lines'][0]);
		$this->assertSame([], $this->applied['snapshot']);
	}

	public function testGeldeingangBuchtDasGegenkontoImHaben(): void {
		$tx = $this->transaction();
		$tx->setAmountCents(50000);
		$this->service()->assign($tx, 4000, [['costCenterId' => 3, 'amountCents' => 50000]]);
		$this->assertSame([
			['accountId' => self::BANK, 'debitCents' => 50000, 'creditCents' => 0],
			['accountId' => 4000, 'debitCents' => 0, 'creditCents' => 50000, 'allocations' => [['costCenterId' => 3, 'amountCents' => 50000]]],
		], $this->applied['lines']);
	}

	public function testErneuteZuordnungGibtDenAltenStandWeiter(): void {
		$this->snapshot = [
			['accountId' => 5300, 'debitCents' => 100000, 'creditCents' => 0, 'allocations' => self::SEN_600],
			['accountId' => self::BANK, 'debitCents' => 0, 'creditCents' => 100000, 'allocations' => []],
		];
		$this->service()->assign($this->transaction(self::OLD_JOURNAL), 5300);

		// Den Rest entscheidet AllocationRules::carryOver(): gleiches Konto und
		// gleicher Betrag übernehmen, alles andere verwirft.
		$this->assertSame($this->snapshot, $this->applied['snapshot']);
		$this->assertArrayNotHasKey('allocations', $this->applied['lines'][0]);
	}

	public function testErneuteZuordnungRaeumtDieAlteVerteilungVorDenZeilen(): void {
		$this->service()->assign($this->transaction(self::OLD_JOURNAL), 5400);
		$this->assertSame([
			'snapshot ' . self::OLD_JOURNAL,
			'deleteAllocations ' . self::OLD_JOURNAL,
			'deleteLines ' . self::OLD_JOURNAL,
			'apply ' . self::NEW_JOURNAL,
		], $this->calls);
	}

	public function testUnassignEntferntVerteilungVorDenZeilen(): void {
		$this->service()->unassign($this->transaction(self::OLD_JOURNAL));
		$this->assertSame([
			'deleteAllocations ' . self::OLD_JOURNAL,
			'deleteLines ' . self::OLD_JOURNAL,
		], $this->calls);
	}
}
