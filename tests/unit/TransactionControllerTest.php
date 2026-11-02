<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\TransactionController;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/transactions/context` (Issue #255): „Zuzuordnen" lädt nur noch
 * offene Umsätze; Historie für Vorschläge, Fortschritt und die Umsätze der
 * Journal-Zeilen kommen als Kennzahlen aus diesem Endpunkt.
 */
class TransactionControllerTest extends TestCase {

	private BankTransactionMapper&MockObject $txMapper;
	private PeriodService&MockObject $periods;

	protected function setUp(): void {
		$this->txMapper = $this->createMock(BankTransactionMapper::class);
		$this->periods = $this->createMock(PeriodService::class);
	}

	private function controller(): TransactionController {
		return new TransactionController(
			$this->createMock(IRequest::class),
			$this->txMapper,
			$this->createMock(BookingService::class),
			$this->periods,
			$this->createMock(IL10N::class),
		);
	}

	private function assigned(int $id, int $journalId, ?string $counterparty, ?int $contra): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId($id);
		$tx->setStatus('assigned');
		$tx->setJournalId($journalId);
		$tx->setCounterparty($counterparty);
		$tx->setContraAccountId($contra);
		return $tx;
	}

	public function testKontextLiefertHistorieFortschrittUndBuchungenDerPeriode(): void {
		$this->periods->expects($this->once())->method('range')->with($this->anything(), 7)->willReturn(['2026-01-01', '2026-12-31']);
		$history = [['counterparty' => 'Stadtwerke', 'contraAccountId' => 4200, 'count' => 3]];
		$this->txMapper->method('counterpartyHistory')->willReturn($history);
		$this->txMapper->method('statusCounts')->willReturn(['total' => 10, 'assigned' => 6]);
		$this->txMapper->expects($this->once())->method('findAssignedInRange')
			->with($this->anything(), '2026-01-01', '2026-12-31')
			->willReturn([
				$this->assigned(1, 101, 'Stadtwerke', 4200),
				$this->assigned(2, 102, 'Bäckerei', 4300),
				// Ohne Zahlungspartner oder Gegenkonto bietet das Journal keine Regel an.
				$this->assigned(3, 103, null, 4300),
				$this->assigned(4, 104, 'Kasse', null),
			]);

		$data = $this->controller()->context(7)->getData();

		$this->assertSame(['history', 'progress', 'byJournal'], array_keys($data));
		$this->assertSame($history, $data['history']);
		$this->assertSame(['total' => 10, 'assigned' => 6], $data['progress']);
		$this->assertSame([101, 102], array_keys($data['byJournal']));
		$this->assertSame(1, $data['byJournal'][101]->getId());
		$this->assertSame(2, $data['byJournal'][102]->getId());
	}

	public function testKontextOhneTreffernLiefertEinLeeresObjektStattEinesArrays(): void {
		$this->periods->method('range')->willReturn([null, null]);
		$this->txMapper->method('counterpartyHistory')->willReturn([]);
		$this->txMapper->method('statusCounts')->willReturn(['total' => 0, 'assigned' => 0]);
		$this->txMapper->method('findAssignedInRange')->willReturn([]);

		$data = $this->controller()->context(null)->getData();

		$this->assertInstanceOf(\stdClass::class, $data['byJournal']);
		$this->assertSame('{}', json_encode($data['byJournal']));
	}
}
