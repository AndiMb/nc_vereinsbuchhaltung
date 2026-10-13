<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\EntryNumberService;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Kommentar an einer Buchung ({@see JournalService::updateComment()}).
 *
 * Der springende Punkt: der Kommentar ist kein Teil des Buchungssatzes und
 * bleibt deshalb auch im abgeschlossenen Geschäftsjahr änderbar – die
 * Festschreibungsprüfung darf hier gar nicht erst laufen. Nachvollziehbar
 * bleibt jede Änderung über das Änderungsprotokoll.
 */
class JournalCommentTest extends TestCase {

	private JournalMapper&MockObject $journalMapper;
	private PeriodService&MockObject $periods;
	private AuditService&MockObject $audit;
	private JournalService $service;
	private Journal $journal;

	protected function setUp(): void {
		$this->journal = new Journal();
		$this->journal->setId(7);
		$this->journal->setEntryNo(3);
		$this->journal->setDate('2025-03-01');
		$this->journal->setUpdatedAt('2026-01-01 10:00:00');

		$this->journalMapper = $this->createMock(JournalMapper::class);
		$this->journalMapper->method('find')->willReturn($this->journal);
		$this->journalMapper->method('update')->willReturnArgument(0);
		$this->periods = $this->createMock(PeriodService::class);
		$this->audit = $this->createMock(AuditService::class);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->service = new JournalService(
			$this->journalMapper,
			$this->createMock(JournalLineMapper::class),
			$this->createMock(AccountMapper::class),
			$this->createMock(BankTransactionMapper::class),
			$this->createMock(AttachmentStorageService::class),
			$this->periods,
			$this->audit,
			$this->createMock(EntryNumberService::class),
			$transaction,
			$l10n,
		);
	}

	public function testKommentarWirdOhnePruefungDesZeitraumsGespeichert(): void {
		// Abgeschlossenes Jahr: assertOpen() würde werfen – darf aber gar nicht laufen.
		$this->periods->expects($this->never())->method('assertOpen');
		$this->audit->expects($this->once())->method('log')->with(
			'Buchungskommentar geändert',
			'journal',
			7,
			$this->callback(static fn (array $d): bool => $d['vorher'] === '–' && $d['nachher'] === 'Beleg fehlt, nachgereicht 04/2026'),
		);

		$result = $this->service->updateComment(7, 'u', '  Beleg fehlt, nachgereicht 04/2026  ', '2026-01-01 10:00:00');

		$this->assertSame('Beleg fehlt, nachgereicht 04/2026', $result->getComment());
		$this->assertNotSame('2026-01-01 10:00:00', $result->getUpdatedAt());
	}

	public function testLeererKommentarLoeschtIhn(): void {
		$this->journal->setComment('alt');

		$result = $this->service->updateComment(7, 'u', "  \n ", '2026-01-01 10:00:00');

		$this->assertNull($result->getComment());
	}

	public function testUnveraenderterKommentarSchreibtNichts(): void {
		$this->journal->setComment('gleich');
		$this->journalMapper->expects($this->never())->method('update');
		$this->audit->expects($this->never())->method('log');

		$result = $this->service->updateComment(7, 'u', 'gleich', '2026-01-01 10:00:00');

		$this->assertSame('2026-01-01 10:00:00', $result->getUpdatedAt());
	}

	public function testFremdaenderungFuehrtZumKonflikt(): void {
		$this->journalMapper->expects($this->never())->method('update');
		$this->expectException(ConflictException::class);

		$this->service->updateComment(7, 'u', 'neu', '2025-12-31 09:00:00');
	}

	public function testZuLangerKommentarWirdAbgelehnt(): void {
		$this->journalMapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->updateComment(7, 'u', str_repeat('ä', JournalService::COMMENT_MAX_LENGTH + 1), '2026-01-01 10:00:00');
	}

	public function testHoechstlaengeIstErlaubt(): void {
		$text = str_repeat('ä', JournalService::COMMENT_MAX_LENGTH);

		$result = $this->service->updateComment(7, 'u', $text, '2026-01-01 10:00:00');

		$this->assertSame($text, $result->getComment());
	}
}
