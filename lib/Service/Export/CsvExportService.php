<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\CsvFormatter;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\ReportService;

/**
 * Die CSV-Exporte: Journal, Saldenliste, Einnahmen-/Ausgaben-Übersicht,
 * Finanzplan und Mehrjahresübersicht.
 *
 * Getrennt vom Controller, weil hier keine HTTP-Entscheidung mehr fällt –
 * herauskommt eine Zeichenkette und ein Dateiname. Die Zahlen selbst stammen
 * aus {@see LedgerAggregator}; diese Klasse entscheidet nur über Spalten,
 * Reihenfolge und Beschriftung.
 *
 * Jede Datei beginnt mit dem UTF-8-BOM: ohne ihn zeigt Excel Umlaute in
 * Kontonamen als Mojibake an, und der Export landet als Fehlermeldung beim
 * Kassenwart statt als Tabelle.
 */
class CsvExportService {

	/** UTF-8-BOM, damit Excel die Kodierung erkennt. */
	private const BOM = "\xEF\xBB\xBF";

	public function __construct(
		private AccountMapper $accountMapper,
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private BudgetMapper $budgetMapper,
		private ReportService $reportService,
	) {
	}

	/**
	 * @param array<int, string> $fields
	 */
	private static function line(array $fields): string {
		return CsvFormatter::line($fields);
	}

	/** Namensbestandteil für das gewählte Jahr, oder „alle_jahre". */
	private static function yearLabel(?int $year): string {
		return FiscalYear::isSelected($year) ? (string)$year : 'alle_jahre';
	}

	/**
	 * Journal aller Buchungssätze.
	 * Format: Nr.;Datum;Beschreibung;Belegnr.;Soll-Nr.;Soll-Konto;Haben-Nr.;Haben-Konto;Betrag (EUR)
	 *
	 * Eine Splittbuchung belegt mehrere Zeilen mit derselben Nummer, siehe
	 * {@see JournalService::pairLines()}.
	 */
	public function journal(string $userId, ?int $year = null): CsvFile {
		[$from, $to] = FiscalYear::range($year);

		$accountMap = [];
		foreach ($this->accountMapper->findAll($userId) as $a) {
			$accountMap[$a->getId()] = ['number' => $a->getNumber(), 'name' => $a->getName()];
		}

		$journals = $this->journalMapper->findAll($userId, 100000, 0, $from, $to);
		// Alle Zeilen gebündelt laden – sonst eine Abfrage je Buchung.
		$linesByJournal = $this->lineMapper->findByJournals(array_map(
			static fn ($j): int => $j->getId(),
			$journals,
		));

		$rows = [];
		foreach ($journals as $journal) {
			$lines = array_map(
				static fn ($line): array => [
					'accountId' => $line->getAccountId(),
					'debitCents' => $line->getDebitCents(),
					'creditCents' => $line->getCreditCents(),
				],
				$linesByJournal[$journal->getId()] ?? [],
			);
			foreach (JournalService::pairLines($lines) as $pair) {
				$debitAcc = $accountMap[$pair['debitAccountId']] ?? null;
				$creditAcc = $accountMap[$pair['creditAccountId']] ?? null;
				$rows[] = [
					'sortDate' => (string)$journal->getDate(),
					'sortEntry' => (int)($journal->getEntryNo() ?? 0),
					'cells' => [
						(string)($journal->getEntryNo() ?? ''),
						ReportFormat::date((string)$journal->getDate()),
						(string)$journal->getDescription(),
						(string)($journal->getDocumentRef() ?? ''),
						$debitAcc['number'] ?? '',
						$debitAcc['name'] ?? '',
						$creditAcc['number'] ?? '',
						$creditAcc['name'] ?? '',
						ReportFormat::money($pair['amountCents'] / 100),
					],
				];
			}
		}

		// Die Ausgabezeilen einer Splittbuchung tragen dieselbe Nummer und
		// dasselbe Datum; ihre Reihenfolge untereinander bleibt damit die aus
		// pairLines() (usort in PHP 8 ist stabil).
		usort($rows, static fn ($a, $b) => [$a['sortDate'], $a['sortEntry']] <=> [$b['sortDate'], $b['sortEntry']]);

		$csv = self::BOM;
		$csv .= self::line(['Nr.', 'Datum', 'Beschreibung', 'Belegnr.', 'Soll-Nr.', 'Soll-Konto', 'Haben-Nr.', 'Haben-Konto', 'Betrag (EUR)']);
		foreach ($rows as $r) {
			$csv .= self::line($r['cells']);
		}

		return new CsvFile($csv, 'journal_' . self::yearLabel($year) . '.csv');
	}

	/**
	 * Saldenliste aller Konten.
	 * Format: Nr.;Konto;Typ;Kategorie;Soll (EUR);Haben (EUR);Saldo (EUR)
	 */
	public function balances(string $userId, ?int $year = null): CsvFile {
		[$from, $to] = FiscalYear::range($year);
		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		$balSums = $from !== null ? $this->lineMapper->sumByAccount($userId, null, $to) : $moveSums;

		$csv = self::BOM;
		$csv .= self::line(['Nr.', 'Konto', 'Typ', 'Kategorie', 'Soll (EUR)', 'Haben (EUR)', 'Saldo (EUR)']);
		foreach ($accounts as $account) {
			$movement = LedgerAggregator::movement($account, $moveSums);
			$csv .= self::line([
				$account->getNumber(),
				$account->getName(),
				self::typeLabel($account->getType()),
				(string)$account->getCategory(),
				ReportFormat::money($movement['debit'] / 100),
				ReportFormat::money($movement['credit'] / 100),
				ReportFormat::money(LedgerAggregator::listBalance($account, $moveSums, $balSums) / 100),
			]);
		}

		return new CsvFile($csv, 'saldenliste_' . self::yearLabel($year) . '.csv');
	}

	private static function typeLabel(string $type): string {
		return match ($type) {
			'income' => 'Einnahmen',
			'expense' => 'Ausgaben',
			'asset' => 'Anlage/Umlauf',
			'liability' => 'Verbindlichkeit',
			'equity' => 'Eigenkapital',
			default => $type,
		};
	}

	/**
	 * Einnahmen-/Ausgaben-Übersicht.
	 * Format: Typ;Nr.;Konto;Kategorie;Betrag (EUR)
	 */
	public function report(string $userId, ?int $year = null): CsvFile {
		[$from, $to] = FiscalYear::range($year);
		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		$erfolg = LedgerAggregator::incomeExpense($accounts, $moveSums);

		$csv = self::BOM;
		$csv .= self::line(['Typ', 'Nr.', 'Konto', 'Kategorie', 'Betrag (EUR)']);

		$csv .= $this->resultBlock('Einnahmen', $erfolg['income']);
		$csv .= self::line(['Einnahmen gesamt', '', '', '', ReportFormat::money($erfolg['incomeCents'] / 100)]);
		$csv .= self::line(['', '', '', '', '']);

		$csv .= $this->resultBlock('Ausgaben', $erfolg['expense']);
		$csv .= self::line(['Ausgaben gesamt', '', '', '', ReportFormat::money($erfolg['expenseCents'] / 100)]);
		$csv .= self::line(['', '', '', '', '']);

		$csv .= self::line(['Ergebnis', '', '', '', ReportFormat::money($erfolg['resultCents'] / 100)]);

		return new CsvFile($csv, 'einnahmen_ausgaben_' . self::yearLabel($year) . '.csv');
	}

	/**
	 * Kontozeilen einer Erfolgsseite. Konten ohne Bewegung bleiben weg; auf die
	 * Summen wirkt sich das nicht aus, sie steuern null bei.
	 *
	 * @param list<array{account:Account, cents:int}> $rows
	 */
	private function resultBlock(string $label, array $rows): string {
		$csv = '';
		foreach ($rows as $row) {
			if ($row['cents'] === 0) {
				continue;
			}
			$account = $row['account'];
			$csv .= self::line([
				$label,
				$account->getNumber(),
				$account->getName(),
				(string)$account->getCategory(),
				ReportFormat::money($row['cents'] / 100),
			]);
		}
		return $csv;
	}

	/**
	 * Finanzplan / Soll-Ist-Vergleich eines Jahres.
	 * Format: Typ;Nr.;Konto;Kategorie;Plan (EUR);Ist (EUR);Differenz (EUR);Notiz
	 */
	public function budget(string $userId, ?int $year = null): CsvFile {
		$year = FiscalYear::orCurrent($year);
		$accounts = $this->accountMapper->findAll($userId);
		$plan = $this->budgetMapper->findByYear($userId, $year);
		$actualSums = $this->lineMapper->sumByAccount($userId, FiscalYear::start($year), FiscalYear::end($year));

		$soll = LedgerAggregator::planActual($accounts, $actualSums, $plan);
		$rows = $soll['rows'];
		usort($rows, static fn ($a, $b) => strcmp(
			(string)$a['account']->getNumber(),
			(string)$b['account']->getNumber(),
		));

		$csv = self::BOM;
		$csv .= self::line(['Typ', 'Nr.', 'Konto', 'Kategorie', 'Plan (EUR)', 'Ist (EUR)', 'Differenz (EUR)', 'Notiz']);
		foreach ($rows as $r) {
			$account = $r['account'];
			$csv .= self::line([
				$account->getType() === 'income' ? 'Einnahmen' : 'Ausgaben',
				(string)$account->getNumber(),
				(string)$account->getName(),
				(string)$account->getCategory(),
				ReportFormat::money($r['planCents'] / 100),
				ReportFormat::money($r['actualCents'] / 100),
				ReportFormat::money(($r['actualCents'] - $r['planCents']) / 100),
				$r['note'],
			]);
		}

		$planResult = $soll['planIncomeCents'] - $soll['planExpenseCents'];
		$actualResult = $soll['actualIncomeCents'] - $soll['actualExpenseCents'];

		$csv .= self::line(['', '', '', '', '', '', '', '']);
		$csv .= self::planTotalLine('Einnahmen (Plan/Ist)', $soll['planIncomeCents'], $soll['actualIncomeCents']);
		$csv .= self::planTotalLine('Ausgaben (Plan/Ist)', $soll['planExpenseCents'], $soll['actualExpenseCents']);
		$csv .= self::planTotalLine('Ergebnis (Plan/Ist)', $planResult, $actualResult);

		return new CsvFile($csv, "finanzplan_soll_ist_{$year}.csv");
	}

	private static function planTotalLine(string $label, int $plan, int $actual): string {
		return self::line([
			$label, '', '', '',
			ReportFormat::money($plan / 100),
			ReportFormat::money($actual / 100),
			ReportFormat::money(($actual - $plan) / 100),
			'',
		]);
	}

	/**
	 * Mehrjahresübersicht als Matrix (Spalten = Geschäftsjahre):
	 *  1. Erfolgsrechnung nach Konten je Jahr, plus Vermögen zum 31.12.
	 *  2. Auswertung nach Kostenstellen/Projekten je Jahr.
	 *  3. Auswertung nach steuerlichen Sphären je Jahr.
	 *
	 * Ausgaben stehen mit negativem Vorzeichen, sodass sich das Ergebnis je
	 * Spalte als schlichte Summe der Zellen ergibt.
	 */
	public function multiyear(string $userId): CsvFile {
		$accounts = $this->accountMapper->findAll($userId);
		$years = $this->journalMapper->distinctYears($userId);
		sort($years); // aufsteigend für die Spaltenreihenfolge

		$movByYear = [];
		$cumByYear = [];
		foreach ($years as $y) {
			$movByYear[$y] = $this->lineMapper->sumByAccount($userId, FiscalYear::start($y), FiscalYear::end($y));
			$cumByYear[$y] = $this->lineMapper->sumByAccount($userId, null, FiscalYear::end($y));
		}

		usort($accounts, static fn ($a, $b) => strcmp((string)$a->getNumber(), (string)$b->getNumber()));

		$header = array_merge([''], array_map(static fn ($y) => (string)$y, $years));

		$csv = self::BOM;
		$csv .= self::line(['Mehrjahresübersicht — Erfolgsrechnung nach Konten']);
		$csv .= self::line($header);

		// --- Einnahmen (Haben-Natur) ---
		$incomeTotals = array_fill_keys($years, 0);
		$csv .= self::line(array_merge(['EINNAHMEN'], array_fill(0, count($years), '')));
		$csv .= $this->accountMatrix($accounts, $years, $movByYear, true, $incomeTotals);
		$csv .= $this->totalsLine('Summe Einnahmen', $years, $incomeTotals);

		// --- Ausgaben (Soll-Natur, negativ dargestellt) ---
		$expenseTotals = array_fill_keys($years, 0);
		$csv .= self::line(array_merge(['AUSGABEN'], array_fill(0, count($years), '')));
		$csv .= $this->accountMatrix($accounts, $years, $movByYear, false, $expenseTotals);
		$csv .= $this->totalsLine('Summe Ausgaben', $years, $expenseTotals);

		// --- Ergebnis + Vermögen ---
		$resultCells = ['Ergebnis'];
		$wealthCells = ['Vermögen (31.12.)'];
		foreach ($years as $y) {
			$resultCells[] = ReportFormat::money(($incomeTotals[$y] + $expenseTotals[$y]) / 100);
			// Da alle Konten außer Geldkonten und Eigenkapital erfolgswirksam sind,
			// gilt per doppelter Buchführung: Vermögen(J) = Vermögen(J−1) +
			// Ergebnis(J) – abweichend nur in Jahren mit Eröffnungsbuchungen.
			$wealthCells[] = ReportFormat::money(LedgerAggregator::wealth($accounts, $cumByYear[$y]) / 100);
		}
		$csv .= self::line($resultCells);
		$csv .= self::line(array_fill(0, count($years) + 1, ''));
		$csv .= self::line($wealthCells);

		$csv .= $this->costCenterMatrix($userId, $years, $header);
		$csv .= $this->sphereMatrix($userId, $years, $header);

		return new CsvFile($csv, 'mehrjahresuebersicht.csv');
	}

	/**
	 * Kontozeilen einer Seite der Mehrjahresmatrix. Konten ohne jeglichen Wert
	 * bleiben weg, damit die Matrix nicht von Nullzeilen überwuchert wird.
	 *
	 * @param Account[] $accounts
	 * @param int[] $years
	 * @param array<int, array<int, array{debit:int, credit:int}>> $movByYear
	 * @param array<int,int> $totals
	 */
	private function accountMatrix(array $accounts, array $years, array $movByYear, bool $creditSide, array &$totals): string {
		$csv = '';
		foreach ($accounts as $account) {
			if (!$account->isResultRelevant() || $account->isCreditNature() !== $creditSide) {
				continue;
			}
			$cells = [trim($account->getNumber() . ' ' . $account->getName())];
			$any = false;
			foreach ($years as $y) {
				// Die Ausgabenseite wird negativ dargestellt; nur das Vorzeichen
				// ist Darstellung, der Betrag kommt aus LedgerAggregator::net().
				$value = LedgerAggregator::net($account, $movByYear[$y]);
				if (!$creditSide) {
					$value = -$value;
				}
				if ($value !== 0) {
					$any = true;
				}
				$totals[$y] += $value;
				$cells[] = ReportFormat::money($value / 100);
			}
			if ($any) {
				$csv .= self::line($cells);
			}
		}
		return $csv;
	}

	/**
	 * @param int[] $years
	 * @param array<int,int> $totalsCents
	 */
	private function totalsLine(string $label, array $years, array $totalsCents): string {
		$cells = [$label];
		foreach ($years as $y) {
			$cells[] = ReportFormat::money(($totalsCents[$y] ?? 0) / 100);
		}
		return self::line($cells);
	}

	/**
	 * @param int[] $years
	 * @param array<int, string> $header
	 */
	private function costCenterMatrix(string $userId, array $years, array $header): string {
		$csv = self::line(array_fill(0, count($years) + 1, ''));
		$csv .= self::line(['Ergebnis je Auswertungsgruppe (Abteilung, Projekt, Veranstaltung)']);
		$csv .= self::line($header);

		$resultByKey = [];
		$nameByKey = [];
		$totals = array_fill_keys($years, 0.0);
		foreach ($years as $y) {
			$report = $this->reportService->costCenterReport($userId, $y);
			foreach ($report['costCenters'] as $cc) {
				$key = ($cc['code'] ?? '') . '|' . $cc['name'];
				$nameByKey[$key] = trim(($cc['code'] ? $cc['code'] . ' ' : '') . $cc['name']);
				$resultByKey[$key][$y] = $cc['result'];
				$totals[$y] += $cc['result'];
			}
		}
		ksort($resultByKey);
		foreach ($resultByKey as $key => $byYear) {
			$cells = [$nameByKey[$key]];
			foreach ($years as $y) {
				$cells[] = ReportFormat::money((float)($byYear[$y] ?? 0));
			}
			$csv .= self::line($cells);
		}
		$sumCells = ['Summe Auswertungsgruppen'];
		foreach ($years as $y) {
			$sumCells[] = ReportFormat::money($totals[$y]);
		}
		return $csv . self::line($sumCells);
	}

	/**
	 * @param int[] $years
	 * @param array<int, string> $header
	 */
	private function sphereMatrix(string $userId, array $years, array $header): string {
		$csv = self::line(array_fill(0, count($years) + 1, ''));
		$csv .= self::line(['Auswertung nach steuerlichen Sphären (Ergebnis) — ersetzt keine steuerliche Beratung']);
		$csv .= self::line($header);

		$resultByCode = [];
		$nameByCode = [];
		$totals = array_fill_keys($years, 0.0);
		foreach ($years as $y) {
			$report = $this->reportService->sphereReport($userId, $y);
			foreach ($report['spheres'] as $s) {
				$code = $s['code'] ?? '';
				$nameByCode[$code] = $s['name'];
				$resultByCode[$code][$y] = $s['result'];
				$totals[$y] += $s['result'];
			}
		}
		foreach ($nameByCode as $code => $name) {
			$cells = [$name];
			foreach ($years as $y) {
				$cells[] = ReportFormat::money((float)($resultByCode[$code][$y] ?? 0));
			}
			$csv .= self::line($cells);
		}
		$sumCells = ['Summe Sphären'];
		foreach ($years as $y) {
			$sumCells[] = ReportFormat::money($totals[$y]);
		}
		return $csv . self::line($sumCells);
	}
}
