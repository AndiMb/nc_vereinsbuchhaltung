<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\EntryNumberService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * #297: Der Buchungssatz eines Bankumsatzes kann in einem anderen
 * Geschäftsjahr liegen als der Umsatz (das Datum lässt sich verschieben).
 * Liegt er in einem abgeschlossenen Jahr, darf ihn das Aufheben der
 * Zuordnung weder löschen noch die übrigen Buchungen neu nummerieren.
 */
class BookingServicePeriodLockTest extends TestCase {

	private JournalMapper&MockObject $journals;
	private JournalLineMapper&MockObject $lines;
	private BankTransactionMapper&MockObject $txs;
	private EntryNumberService&MockObject $entryNumbers;
	private PeriodService&MockObject $periods;

	protected function setUp(): void {
		$this->journals = $this->createMock(JournalMapper::class);
		$this->lines = $this->createMock(JournalLineMapper::class);
		$this->txs = $this->createMock(BankTransactionMapper::class);
		$this->entryNumbers = $this->createMock(EntryNumberService::class);
		$this->periods = $this->createMock(PeriodService::class);
		// 2012 ist abgeschlossen, 2013 offen.
		$this->periods->method('assertOpen')->willReturnCallback(static function (string $userId, string $date): void {
			if (str_starts_with($date, '2012-')) {
				throw new PeriodClosedException('Das Geschäftsjahr 2012 ist abgeschlossen.');
			}
		});
	}

	private function service(): BookingService {
		$runner = $this->createMock(TransactionRunner::class);
		$runner->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$runner->method('runWithRetry')->willReturnCallback(static fn (callable $fn) => $fn());
		return new BookingService(
			$this->journals,
			$this->lines,
			$this->txs,
			$this->createMock(AccountService::class),
			$this->createMock(AttachmentStorageService::class),
			$this->periods,
			$this->createMock(AuditService::class),
			$this->entryNumbers,
			$runner,
			$this->createMock(IL10N::class),
			ClubCurrencyStub::of($this),
		);
	}

	private function transaction(string $date, int $journalId): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId(5);
		$tx->setUserId('__verein__');
		$tx->setBookingDate($date);
		$tx->setAmountCents(1234);
		$tx->setJournalId($journalId);
		$tx->setStatus('assigned');
		return $tx;
	}

	private function journal(string $date): Journal {
		$journal = new Journal();
		$journal->setId(9);
		$journal->setUserId('__verein__');
		$journal->setDate($date);
		$journal->setPeriodId(1);
		$this->journals->method('find')->willReturn($journal);
		return $journal;
	}

	public function testUnassignLaesstBuchungImAbgeschlossenenJahrUnangetastet(): void {
		$this->journal('2012-12-31');
		$this->journals->expects($this->never())->method('delete');
		$this->lines->expects($this->never())->method('deleteByJournal');
		$this->entryNumbers->expects($this->never())->method('renumberPeriod');
		$this->txs->expects($this->never())->method('update');

		$this->expectException(PeriodClosedException::class);
		$this->service()->unassign($this->transaction('2013-01-02', 9));
	}

	public function testUnassignImOffenenJahrLoeschtDieBuchung(): void {
		$this->journal('2013-01-02');
		$this->journals->expects($this->once())->method('delete');
		$this->entryNumbers->expects($this->once())->method('renumberPeriod');
		$this->txs->method('update')->willReturnArgument(0);

		$tx = $this->service()->unassign($this->transaction('2013-01-02', 9));

		$this->assertNull($tx->getJournalId());
		$this->assertSame('unassigned', $tx->getStatus());
	}

	/**
	 * Review 2026-11-18 (Backend 2): ein Datenbankfehler beim Laden des alten
	 * Buchungssatzes ist kein „gibt es nicht". Er muss die Transaktion
	 * abbrechen – sonst stünde der Umsatz als offen da, während die Buchung
	 * bleibt, oder eine erneute Zuordnung buchte den Betrag ein zweites Mal.
	 */
	public function testUnassignBrichtBeiDatenbankfehlerAb(): void {
		$this->journals->method('find')->willThrowException(new \RuntimeException('Verbindung verloren'));
		$this->txs->expects($this->never())->method('update');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Verbindung verloren');
		$this->service()->unassign($this->transaction('2013-01-02', 9));
	}

	public function testErneuteZuordnungBuchtBeiDatenbankfehlerNichtDoppelt(): void {
		$this->journals->method('find')->willThrowException(new \RuntimeException('Verbindung verloren'));
		$this->journals->expects($this->never())->method('insert');
		$this->txs->expects($this->never())->method('update');

		$this->expectException(\RuntimeException::class);
		$this->service()->assign($this->transaction('2013-01-02', 9), 42);
	}

	/** Ist der alte Buchungssatz schon weg, gibt es nichts zu löschen – das bleibt still. */
	public function testUnassignOhneVorhandenenBuchungssatzGehtDurch(): void {
		$this->journals->method('find')->willThrowException(new DoesNotExistException('weg'));
		$this->journals->expects($this->never())->method('delete');
		$this->txs->expects($this->once())->method('update')->willReturnArgument(0);

		$tx = $this->service()->unassign($this->transaction('2013-01-02', 9));

		$this->assertSame('unassigned', $tx->getStatus());
	}
}
