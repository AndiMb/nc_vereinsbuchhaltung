<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementEventLog;
use OCA\Vereinsbuchhaltung\Service\ReimbursementFileService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementPaymentReverter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verschwindet der Buchungssatz einer ausgezahlten Auslage, wird die Auszahlung
 * zurückgenommen: Antrag wieder freigegeben, Posten wieder offen, Belege zurück.
 */
class ReimbursementPaymentReverterTest extends TestCase {

	private ReimbursementMapper&MockObject $reimbursements;
	private OpenItemMapper&MockObject $openItems;
	private ReimbursementEventLog&MockObject $log;
	private ReimbursementFileService&MockObject $files;

	private function reverter(): ReimbursementPaymentReverter {
		$this->reimbursements = $this->createMock(ReimbursementMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->log = $this->createMock(ReimbursementEventLog::class);
		$this->files = $this->createMock(ReimbursementFileService::class);
		$actor = $this->createMock(ActorContextService::class);
		$actor->method('actorUid')->willReturn('berta');
		return new ReimbursementPaymentReverter($this->reimbursements, $this->openItems, $this->log, $this->files, $actor, $this->createMock(AuditService::class));
	}

	public function testOhneZugehoerigeAuslageGeschiehtNichts(): void {
		$reverter = $this->reverter();
		$this->reimbursements->method('findPaidByJournal')->willReturn(null);
		$this->log->expects($this->never())->method('append');
		$this->files->expects($this->never())->method('restoreFromJournal');

		$reverter->revertForJournal(42);
	}

	public function testStelltAntragPostenUndBelegeWiederHer(): void {
		$reverter = $this->reverter();
		$r = new Reimbursement();
		$r->setId(5);
		$r->setStatus(Reimbursement::STATUS_PAID);
		$r->setPaidAt('2026-11-01');
		$r->setJournalId(42);
		$r->setOpenItemId(9);
		$item = new OpenItem();
		$item->setStatus('paid');
		$item->setPaidJournalId(42);
		$item->setSettledCents(1250);
		$this->reimbursements->method('findPaidByJournal')->with(42)->willReturn($r);
		$this->openItems->method('find')->with(9)->willReturn($item);
		$this->files->expects($this->once())->method('restoreFromJournal')->with($r, 42);
		$this->log->expects($this->once())->method('append')->with(5, ReimbursementEvent::ACTION_PAYMENT_REVERTED, 'berta', ReimbursementEvent::ROLE_STAFF, null, ['journalId' => 42, 'paidAt' => '2026-11-01']);
		$this->reimbursements->expects($this->once())->method('update');
		$this->openItems->expects($this->once())->method('update');

		$reverter->revertForJournal(42);

		$this->assertSame(Reimbursement::STATUS_APPROVED, $r->getStatus());
		$this->assertNull($r->getPaidAt());
		$this->assertNull($r->getJournalId());
		$this->assertSame('open', $item->getStatus());
		$this->assertNull($item->getPaidJournalId());
		$this->assertSame(0, $item->getSettledCents());
	}
}
