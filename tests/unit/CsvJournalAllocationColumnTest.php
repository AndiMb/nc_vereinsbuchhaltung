<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLine;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\AccountStatementService;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\CostCenter\LineAllocationWriter;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCA\Vereinsbuchhaltung\Service\Export\CsvExportService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use PHPUnit\Framework\TestCase;

/**
 * CSV-Journalexport und Kostenstellen (Issue #7).
 *
 * Ausgeschaltet bleibt die Datei zeichengleich zum Stand davor – wer sie in
 * einer Tabelle weiterverarbeitet, merkt nichts. Eingeschaltet kommt genau
 * eine Spalte dazu, und zwar am Ende, damit die bisherigen Spalten ihre
 * Position behalten.
 */
class CsvJournalAllocationColumnTest extends TestCase {

	private const HEAD = ['Nr.', 'Datum', 'Beschreibung', 'Belegnr.', 'Soll-Nr.', 'Soll-Konto', 'Haben-Nr.', 'Haben-Konto', 'Betrag (EUR)'];

	private function account(int $id, string $name): Account {
		$account = new Account();
		$account->setId($id);
		$account->setNumber((string)$id);
		$account->setName($name);
		return $account;
	}

	private function journal(int $id, int $entryNo, string $description): Journal {
		$journal = new Journal();
		$journal->setId($id);
		$journal->setEntryNo($entryNo);
		$journal->setDate('2026-03-0' . $entryNo);
		$journal->setDescription($description);
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

	private function costCenter(int $id, string $code): BookingCostCenter {
		$cc = new BookingCostCenter();
		$cc->setId($id);
		$cc->setCode($code);
		return $cc;
	}

	/** @return list<string> die Zeilen der Datei ohne BOM */
	private function export(?bool $enabled): array {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([
			$this->account(5300, 'Ausrüstung'),
			$this->account(5400, 'Miete'),
			$this->account(1200, 'Bank'),
		]);
		$journals = $this->createMock(JournalMapper::class);
		$journals->method('findAll')->willReturn([
			$this->journal(40, 1, 'Trikots'),
			$this->journal(41, 2, 'Miete'),
			$this->journal(42, 3, 'Sammelrechnung'),
		]);
		$lines = $this->createMock(JournalLineMapper::class);
		$lines->method('findByJournals')->willReturn([
			40 => [$this->line(77, 40, 5300, 100000, 0), $this->line(78, 40, 1200, 0, 100000)],
			41 => [$this->line(79, 41, 5400, 50000, 0), $this->line(80, 41, 1200, 0, 50000)],
			// Splittbuchung: die Bankzeile verteilt sich auf zwei Ausgabezeilen.
			42 => [$this->line(81, 42, 5300, 30000, 0), $this->line(82, 42, 5400, 20000, 0), $this->line(83, 42, 1200, 0, 50000)],
		]);
		$periods = $this->createMock(PeriodService::class);
		$periods->method('range')->willReturn([null, null]);

		$extra = [];
		if ($enabled !== null) {
			$settings = $this->createMock(CostCenterSettings::class);
			$settings->method('isEnabled')->willReturn($enabled);
			$writer = $this->createMock(LineAllocationWriter::class);
			$writer->method('costCentersById')->willReturn([3 => $this->costCenter(3, 'SEN'), 5 => $this->costCenter(5, 'AJU')]);
			$writer->method('forJournals')->willReturn([
				77 => [['costCenterId' => 3, 'amount' => 600.0], ['costCenterId' => 5, 'amount' => 400.0]],
				82 => [['costCenterId' => 5, 'amount' => 150.5]],
			]);
			if (!$enabled) {
				$writer->expects($this->never())->method('forJournals');
			}
			$extra = [$settings, $writer];
		}

		$csv = new CsvExportService(
			$accounts,
			$journals,
			$lines,
			$this->createMock(ReportService::class),
			$periods,
			$this->createMock(AccountStatementService::class),
			$this->createMock(BudgetOverviewService::class),
			ClubCurrencyStub::of($this),
			...$extra,
		);
		$content = substr($csv->journal('u')->content, 3);
		return array_values(array_filter(preg_split('/\r?\n/', $content) ?: [], static fn (string $l): bool => $l !== ''));
	}

	public function testModulAusIstDieDateiUnveraendert(): void {
		$off = $this->export(false);
		$this->assertSame(self::HEAD, str_getcsv($off[0], ';'));
		// Derselbe Inhalt wie ganz ohne die neuen Dienste – also wie vor der Funktion.
		$this->assertSame($this->export(null), $off);
		foreach ($off as $row) {
			$this->assertCount(9, str_getcsv($row, ';'));
		}
	}

	public function testModulEinHaengtDieSpalteAmEndeAn(): void {
		$on = $this->export(true);
		$this->assertSame([...self::HEAD, 'Kostenstellen'], str_getcsv($on[0], ';'));
		$this->assertSame('SEN 600,00 | AJU 400,00', str_getcsv($on[1], ';')[9]);
	}

	public function testDieBisherigenSpaltenBleibenAnIhrerStelle(): void {
		$off = $this->export(false);
		$on = $this->export(true);
		$this->assertCount(count($off), $on);
		foreach ($off as $i => $row) {
			$this->assertSame(str_getcsv($row, ';'), array_slice(str_getcsv($on[$i], ';'), 0, 9));
		}
	}

	public function testZeileOhneVerteilungHatLeereSpalte(): void {
		$on = $this->export(true);
		$miete = str_getcsv($on[2], ';');
		$this->assertCount(10, $miete);
		$this->assertSame('', $miete[9]);
	}

	/** In der Splittbuchung steht die Verteilung in der Zeile ihres Kontos, und nur dort. */
	public function testSplittbuchungZeigtDieVerteilungBeiIhremKonto(): void {
		$on = $this->export(true);
		$first = str_getcsv($on[3], ';');
		$second = str_getcsv($on[4], ';');
		$this->assertSame('5300', $first[4]);
		$this->assertSame('', $first[9]);
		$this->assertSame('5400', $second[4]);
		$this->assertSame('AJU 150,50', $second[9]);
	}
}
