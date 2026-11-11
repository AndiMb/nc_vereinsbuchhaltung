<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\CostCenter\LineAllocationWriter;
use OCA\Vereinsbuchhaltung\Service\EntryNumberService;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Anbindung der Kostenstellen an JournalService (Issue #7).
 *
 * Beim Ändern einer Buchung werden alle Zeilen gelöscht und neu geschrieben.
 * Die Verteilung überlebt das nur, wenn der Dienst sie VORHER festhält und
 * NACH dem Neuschreiben zurückgibt – fehlte einer der beiden Schritte oder
 * stünde er an falscher Stelle, verlöre jede Bearbeitung die Kostenstellen
 * still. Die Regeln selbst prüfen AllocationRulesTest und
 * LineAllocationWriterTest; hier geht es nur um Reihenfolge und Weitergabe.
 */
class JournalServiceAllocationWiringTest extends TestCase {

	private const JOURNAL = 40;
	private const SNAPSHOT = [
		['accountId' => 5300, 'debitCents' => 100000, 'creditCents' => 0, 'allocations' => [['costCenterId' => 3, 'amountCents' => 60000]]],
		['accountId' => 1200, 'debitCents' => 0, 'creditCents' => 100000, 'allocations' => []],
	];
	private const LINES = [
		['accountId' => 5300, 'debitCents' => 100000, 'creditCents' => 0],
		['accountId' => 1200, 'debitCents' => 0, 'creditCents' => 100000],
	];

	/** @var list<string> */
	private array $calls = [];
	/** @var array<string, mixed> */
	private array $applied = [];
	private AuditService&MockObject $audit;
	private JournalLineMapper&MockObject $lines;
	private AccountMapper&MockObject $accounts;

	private function service(): JournalService {
		$journal = new Journal();
		$journal->setId(self::JOURNAL);
		$journal->setUserId('u');
		$journal->setEntryNo(7);
		$journal->setDate('2026-03-01');
		$journal->setPeriodId(1);
		$journal->setUpdatedAt('2026-03-01 10:00:00.000000');

		$journals = $this->createMock(JournalMapper::class);
		$journals->method('find')->willReturn($journal);
		$journals->method('update')->willReturnArgument(0);
		$journals->method('insert')->willReturnCallback(static function (Journal $new): Journal {
			$new->setId(self::JOURNAL);
			return $new;
		});

		$this->lines = $this->createMock(JournalLineMapper::class);
		$this->lines->method('deleteByJournal')->willReturnCallback(function (): void {
			$this->calls[] = 'deleteLines';
		});
		$this->lines->method('insert')->willReturnCallback(function (JournalLine $line): JournalLine {
			$this->calls[] = 'insertLine ' . $line->getAccountId();
			return $line;
		});

		$writer = $this->createMock(LineAllocationWriter::class);
		$writer->method('snapshot')->willReturnCallback(function (): array {
			$this->calls[] = 'snapshot';
			return self::SNAPSHOT;
		});
		$writer->method('deleteByJournal')->willReturnCallback(function (): void {
			$this->calls[] = 'deleteAllocations';
		});
		$writer->method('apply')->willReturnCallback(function (int $journalId, string $userId, array $lines, array $snapshot = []): string {
			$this->calls[] = 'apply';
			$this->applied = ['journalId' => $journalId, 'lines' => $lines, 'snapshot' => $snapshot];
			return 'SEN 600,00';
		});
		$writer->method('afterReassign')->willReturnCallback(function (int $lineId, Account $target): void {
			$this->calls[] = 'afterReassign ' . $lineId . ' → ' . $target->getId();
		});

		$period = new Period();
		$period->setId(1);
		$periods = $this->createMock(PeriodService::class);
		$periods->method('forDateOrCreate')->willReturn($period);

		$this->accounts = $this->createMock(AccountMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$transaction->method('runWithRetry')->willReturnCallback(static fn (callable $fn) => $fn());
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new JournalService(
			$journals,
			$this->lines,
			$this->accounts,
			$this->createMock(BankTransactionMapper::class),
			$this->createMock(AttachmentStorageService::class),
			$periods,
			$this->audit,
			$this->createMock(EntryNumberService::class),
			$transaction,
			$l10n,
			ClubCurrencyStub::of($this),
			null,
			null,
			$writer,
		);
	}

	public function testAendernHaeltDieVerteilungVorDemLoeschenFestUndGibtSieDanachZurueck(): void {
		$this->service()->updateBookingLines(self::JOURNAL, 'u', '2026-03-01', 'Trikots', null, self::LINES, '2026-03-01 10:00:00.000000');

		$this->assertSame(
			['snapshot', 'deleteAllocations', 'deleteLines', 'insertLine 5300', 'insertLine 1200', 'apply'],
			$this->calls,
		);
		$this->assertSame(self::JOURNAL, $this->applied['journalId']);
		$this->assertSame(self::LINES, $this->applied['lines']);
		$this->assertSame(self::SNAPSHOT, $this->applied['snapshot']);
	}

	public function testAnlegenSchreibtDieVerteilungNachDenZeilenOhneAltenStand(): void {
		$service = $this->service();
		$this->audit->expects($this->once())->method('log')->with(
			'Buchung angelegt',
			'journal',
			self::JOURNAL,
			$this->callback(static fn (array $details): bool => ($details['kostenstellen'] ?? null) === 'SEN 600,00'),
		);

		$service->createBookingLines('u', '2026-03-01', 'Trikots', null, self::LINES);

		$this->assertSame(['insertLine 5300', 'insertLine 1200', 'apply'], $this->calls);
		$this->assertSame([], $this->applied['snapshot']);
	}

	public function testLoeschenRaeumtDieVerteilungVorDenZeilen(): void {
		$this->service()->deleteBooking(self::JOURNAL, 'u');

		$this->assertSame(['deleteAllocations', 'deleteLines'], $this->calls);
	}

	public function testUmbuchenMeldetDieUmgebuchteZeileMitDemZielkonto(): void {
		$service = $this->service();
		$line = static function (int $id, int $accountId): JournalLine {
			$l = new JournalLine();
			$l->setId($id);
			$l->setJournalId(self::JOURNAL);
			$l->setAccountId($accountId);
			return $l;
		};
		$this->lines->method('findByJournal')->willReturn([$line(77, 5300), $line(78, 1200)]);
		$this->accounts->method('find')->willReturnCallback(static function (int $id): Account {
			$account = new Account();
			$account->setId($id);
			$account->setNumber((string)$id);
			$account->setName('Konto ' . $id);
			return $account;
		});

		$service->reassignLine(self::JOURNAL, 'u', 5300, 5400, '2026-03-01 10:00:00.000000');

		$this->assertSame(['afterReassign 77 → 5400'], $this->calls);
	}
}
