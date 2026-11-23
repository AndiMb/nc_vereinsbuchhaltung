<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\ExportController;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AccountStatementService;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\Export\CsvExportService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use PHPUnit\Framework\TestCase;

/**
 * CSV-Journalexport mit Auswahl: Ist die Buchungsliste gefiltert (Konto,
 * Zeitraum, Suche, „nur ohne Beleg“), enthält die Datei nur die angezeigten
 * Buchungen – nicht wieder das ganze Journal.
 */
class CsvJournalSelectionTest extends TestCase {

	private function journal(int $id, int $entryNo): Journal {
		$journal = new Journal();
		$journal->setId($id);
		$journal->setEntryNo($entryNo);
		$journal->setDate('2026-03-0' . $entryNo);
		$journal->setDescription('Buchung ' . $entryNo);
		return $journal;
	}

	private function line(int $id, int $journalId, int $accountId, int $debit, int $credit): JournalLine {
		$line = new JournalLine();
		$line->setId($id);
		$line->setJournalId($journalId);
		$line->setAccountId($accountId);
		$line->setDebitCents($debit);
		$line->setCreditCents($credit);
		return $line;
	}

	/**
	 * @param list<int>|null $onlyIds
	 * @return list<string> die Nummern (erste Spalte) der Datenzeilen
	 */
	private function exportedEntryNumbers(?array $onlyIds): array {
		$account = new Account();
		$account->setId(1200);
		$account->setNumber('1200');
		$account->setName('Bank');
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$account]);

		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findAll')->willReturn([$this->journal(40, 1), $this->journal(41, 2), $this->journal(42, 3)]);

		$allLines = [
			40 => [$this->line(1, 40, 1200, 100, 0), $this->line(2, 40, 1200, 0, 100)],
			41 => [$this->line(3, 41, 1200, 200, 0), $this->line(4, 41, 1200, 0, 200)],
			42 => [$this->line(5, 42, 1200, 300, 0), $this->line(6, 42, 1200, 0, 300)],
		];
		$lines = $this->createMock(JournalLineMapper::class);
		$lines->method('findByJournals')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($allLines, array_flip($ids)),
		);
		$periods = $this->createMock(PeriodService::class);
		$periods->method('range')->willReturn([null, null]);

		$csv = new CsvExportService(
			$accounts,
			$journals,
			$lines,
			$this->createMock(ReportService::class),
			$periods,
			$this->createMock(AccountStatementService::class),
			$this->createMock(BudgetOverviewService::class),
			ClubCurrencyStub::of($this),
		);
		$content = substr($csv->journal('u', null, $onlyIds)->content, 3);
		$rows = array_values(array_filter(preg_split('/\r?\n/', $content) ?: [], static fn (string $l): bool => $l !== ''));
		$this->assertSame('Nr.', str_getcsv($rows[0], ';')[0]);
		return array_map(static fn (string $row): string => (string)str_getcsv($row, ';')[0], array_slice($rows, 1));
	}

	/** Der Export ist ein POST, aber kein Schreibzugriff: auch der Revisor darf ihn. */
	public function testDerAuswahlExportBrauchtNurLeserechte(): void {
		$attributes = (new \ReflectionMethod(ExportController::class, 'journalSelection'))->getAttributes(RequiresRole::class);
		$this->assertCount(1, $attributes);
		$this->assertSame(PermissionService::ROLE_READ, $attributes[0]->newInstance()->role);
	}

	public function testOhneAuswahlKommtDasGanzeJournal(): void {
		$this->assertSame(['1', '2', '3'], $this->exportedEntryNumbers(null));
	}

	public function testMitAuswahlKommenNurDieGewaehltenBuchungen(): void {
		$this->assertSame(['1', '3'], $this->exportedEntryNumbers([42, 40]));
	}

	public function testFremdeOderUnbekannteNummernWerdenIgnoriert(): void {
		// Maßgeblich bleibt, was der Mapper für diesen Nutzer und Zeitraum liefert.
		$this->assertSame(['2'], $this->exportedEntryNumbers([41, 999]));
	}

	public function testLeereAuswahlErgibtNurDieKopfzeile(): void {
		$this->assertSame([], $this->exportedEntryNumbers([]));
	}
}
