<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\JournalController;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\AccountStatementService;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\CostCenter\LineAllocationWriter;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Abwärtskompatibilität der Journal-Liste (Issue #7): Solange das Modul
 * Kostenstellen aus ist, ist die Antwort zeichengleich zum Stand davor –
 * kein neues Feld, keine zusätzliche Abfrage. Erst eingeschaltet trägt jede
 * Zeile `allocations`.
 */
class JournalIndexCompatibilityTest extends TestCase {

	private function line(): JournalLine {
		$line = new JournalLine();
		$line->setId(77);
		$line->setJournalId(40);
		$line->setAccountId(5300);
		$line->setDebitCents(100000);
		$line->setCreditCents(0);
		return $line;
	}

	private function controller(bool $enabled): JournalController {
		$journal = new Journal();
		$journal->setId(40);
		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findAll')->willReturn([$journal]);
		$lines = $this->createMock(JournalLineMapper::class);
		$lines->method('findByJournals')->willReturn([40 => [$this->line()]]);
		$periods = $this->createMock(PeriodService::class);
		$periods->method('range')->willReturn([null, null]);

		$settings = $this->createMock(CostCenterSettings::class);
		$settings->method('isEnabled')->willReturn($enabled);
		$writer = $this->createMock(LineAllocationWriter::class);
		if ($enabled) {
			$writer->method('forJournals')->with([40])->willReturn([
				77 => [['costCenterId' => 3, 'amount' => 600.0]],
			]);
		} else {
			$writer->expects($this->never())->method('forJournals');
		}

		return new JournalController(
			$this->createMock(IRequest::class),
			$journals,
			$lines,
			$this->createMock(AccountMapper::class),
			$this->createMock(BankTransactionMapper::class),
			$periods,
			$this->createMock(JournalService::class),
			$this->createMock(AccountStatementService::class),
			$this->createMock(IL10N::class),
			$this->createMock(ClubCurrency::class),
			$settings,
			$writer,
		);
	}

	/** @return array<string, mixed> die erste Zeile der Antwort, so wie sie als JSON hinausgeht */
	private function firstLine(JournalController $controller): array {
		$json = json_encode($controller->index()->getData(), JSON_THROW_ON_ERROR);
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		return $data[0]['lines'][0];
	}

	public function testModulAusLiefertZeilenWieBisher(): void {
		$line = $this->firstLine($this->controller(false));
		$this->assertSame(
			json_decode(json_encode($this->line(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
			$line,
		);
		$this->assertArrayNotHasKey('allocations', $line);
	}

	public function testModulEinLiefertAllocationsJeZeile(): void {
		$line = $this->firstLine($this->controller(true));
		$this->assertSame([['costCenterId' => 3, 'amount' => 600]], $line['allocations']);
		// Die bisherigen Felder bleiben unverändert daneben stehen.
		$this->assertSame(5300, $line['accountId']);
		$this->assertSame(100000, $line['debitCents']);
	}
}
