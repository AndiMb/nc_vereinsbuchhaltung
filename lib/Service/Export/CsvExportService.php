<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\AccountStatementService;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCA\Vereinsbuchhaltung\Service\CsvFormatter;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ReportService;

/**
 * Die CSV-Exporte: Journal, Kontenblatt, Saldenliste, Einnahmen-/Ausgaben-
 * Übersicht, Finanzplan und Mehrjahresübersicht.
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
		private ReportService $reportService,
		private PeriodService $periods,
		private AccountStatementService $statements,
		private BudgetOverviewService $budgetOverview,
	) {
	}

	/**
	 * @param array<int, string> $fields
	 */
	private static function line(array $fields): string {
		return CsvFormatter::line($fields);
	}

	/**
	 * Namensbestandteil für das gewählte Geschäftsjahr, oder „alle_zeitraeume".
	 *
	 * Dieselbe Regel wie beim Beleg-Archiv, siehe {@see AttachmentArchive::slug()}:
	 * die Bezeichnung ist freier Text und darf im Dateinamen keine Pfadtrenner
	 * hinterlassen – aus „2025/26" wird „2025-26".
	 */
	private function periodLabel(string $userId, ?int $periodId): string {
		if (!PeriodService::isSelected($periodId)) {
			return 'alle_zeitraeume';
		}
		return AttachmentArchive::slug($this->periods->find($userId, (int)$periodId)->getLabel());
	}

	/**
	 * Journal aller Buchungssätze.
	 * Format: Nr.;Datum;Beschreibung;Belegnr.;Soll-Nr.;Soll-Konto;Haben-Nr.;Haben-Konto;Betrag (EUR)
	 *
	 * Eine Splittbuchung belegt mehrere Zeilen mit derselben Nummer, siehe
	 * {@see JournalService::pairLines()}.
	 */
	public function journal(string $userId, ?int $periodId = null): CsvFile {
		[$from, $to] = $this->periods->range($userId, $periodId);

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

		return new CsvFile($csv, 'journal_' . $this->periodLabel($userId, $periodId) . '.csv');
	}

	/**
	 * Kontenblatt: die Buchungen eines Kontos (optional mit Unterkonten) samt
	 * Saldovortrag, laufendem Saldo und Summenzeile – dieselben Zahlen wie der
	 * Kontoauszug im Reiter Konten, immer chronologisch.
	 * Format: Nr.;Datum;Beschreibung;Belegnr.;Konto;Gegenkonto;Soll (EUR);Haben (EUR);Saldo (EUR)
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn das Konto fehlt
	 */
	public function accountStatement(string $userId, int $accountId, bool $includeChildren, ?int $periodId = null): CsvFile {
		$statement = $this->statements->build($userId, $accountId, $includeChildren, $periodId);

		$csv = self::BOM;
		$csv .= self::line(['Nr.', 'Datum', 'Beschreibung', 'Belegnr.', 'Konto', 'Gegenkonto', 'Soll (EUR)', 'Haben (EUR)', 'Saldo (EUR)']);
		if ($statement['carryDate'] !== null) {
			$csv .= self::line(['', ReportFormat::date($statement['carryDate']), 'Saldovortrag', '', '', '', '', '', ReportFormat::money((float)$statement['carry'])]);
		}
		foreach ($statement['rows'] as $row) {
			$csv .= self::line([
				(string)($row['entryNo'] ?? ''),
				ReportFormat::date((string)$row['date']),
				(string)($row['description'] ?? ''),
				(string)($row['documentRef'] ?? ''),
				(string)$row['account'],
				(string)$row['contra'],
				$row['debit'] ? ReportFormat::money((float)$row['debit']) : '',
				$row['credit'] ? ReportFormat::money((float)$row['credit']) : '',
				ReportFormat::money((float)$row['saldo']),
			]);
		}
		$totals = $statement['totals'];
		$csv .= self::line(['', '', 'Summe', '', '', '', ReportFormat::money((float)$totals['debit']), ReportFormat::money((float)$totals['credit']), ReportFormat::money((float)$totals['balance'])]);

		$number = AttachmentArchive::slug((string)$statement['account']->getNumber());
		return new CsvFile($csv, 'kontenblatt_' . $number . '_' . $this->periodLabel($userId, $periodId) . '.csv');
	}

	/**
	 * Saldenliste aller Konten.
	 * Format: Nr.;Konto;Typ;Kategorie;Soll (EUR);Haben (EUR);Saldo (EUR)
	 */
	public function balances(string $userId, ?int $periodId = null): CsvFile {
		[$from, $to] = $this->periods->range($userId, $periodId);
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

		return new CsvFile($csv, 'saldenliste_' . $this->periodLabel($userId, $periodId) . '.csv');
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
	public function report(string $userId, ?int $periodId = null): CsvFile {
		[$from, $to] = $this->periods->range($userId, $periodId);
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

		return new CsvFile($csv, 'einnahmen_ausgaben_' . $this->periodLabel($userId, $periodId) . '.csv');
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
	 * Finanzplan / Soll-Ist-Vergleich eines Geschäftsjahres als Planbaum.
	 * Format: Typ;Ebene;Nr.;Konto;Kategorie;Plan (EUR);Plan Σ (EUR);Ist (EUR);
	 * Ist Σ (EUR);Differenz Σ (EUR);Status;Notiz
	 *
	 * „Ebene“ (1 = Wurzel) erlaubt in Excel die Gliederung nach Kontenebenen.
	 * Plan/Ist sind die Werte direkt auf dem Konto, die Σ-Spalten schließen
	 * alle Unterkonten ein (siehe BudgetTree).
	 */
	public function budget(string $userId, ?int $periodId = null): CsvFile {
		$data = $this->budgetOverview->build($userId, $periodId);
		$period = $data['period'];

		$csv = self::BOM;
		$csv .= self::line(['Typ', 'Ebene', 'Nr.', 'Konto', 'Kategorie', 'Plan (EUR)', 'Plan Σ (EUR)', 'Ist (EUR)', 'Ist Σ (EUR)', 'Differenz Σ (EUR)', 'Status', 'Notiz']);
		foreach ($data['nodes'] as $node) {
			$account = $data['accounts'][$node['id']];
			$csv .= self::line([
				$account->getType() === 'income' ? 'Einnahmen' : 'Ausgaben',
				(string)($node['depth'] + 1),
				(string)$account->getNumber(),
				(string)$account->getName(),
				(string)$account->getCategory(),
				ReportFormat::money($node['own']['plan'] / 100),
				ReportFormat::money($node['sum']['plan'] / 100),
				ReportFormat::money($node['own']['actual'] / 100),
				ReportFormat::money($node['sum']['actual'] / 100),
				$node['comparable'] ? ReportFormat::money(($node['sum']['actual'] - $node['sum']['plan']) / 100) : '',
				self::budgetStatus($node['flags']),
				$data['notes'][$node['id']] ?? '',
			]);
		}

		$t = $data['totals'];
		$csv .= self::line(array_fill(0, 12, ''));
		$csv .= self::planTotalLine('Einnahmen (Plan/Ist)', $t['planIncome'], $t['actualIncome']);
		$csv .= self::planTotalLine('Ausgaben (Plan/Ist)', $t['planExpense'], $t['actualExpense']);
		$csv .= self::planTotalLine('Ergebnis (Plan/Ist)', $t['planIncome'] - $t['planExpense'], $t['actualIncome'] - $t['actualExpense']);

		return new CsvFile($csv, 'finanzplan_soll_ist_' . AttachmentArchive::slug($period->getLabel()) . '.csv');
	}

	/**
	 * Auffälligkeiten einer Zeile im Klartext, wie sie der Bildschirm zeigt.
	 *
	 * @param list<string> $flags
	 */
	private static function budgetStatus(array $flags): string {
		$labels = [
			BudgetTree::FLAG_CONFLICT => 'Plankonflikt',
			BudgetTree::FLAG_OVERRUN => 'überschritten',
			BudgetTree::FLAG_UNPLANNED => 'ohne Plan',
			BudgetTree::FLAG_UNPLANNED_BELOW => 'ungeplant darunter',
		];
		$out = [];
		foreach ($flags as $flag) {
			if (isset($labels[$flag])) {
				$out[] = $labels[$flag];
			}
		}
		return implode(', ', $out);
	}

	private static function planTotalLine(string $label, int $plan, int $actual): string {
		return self::line([
			$label, '', '', '', '', '',
			ReportFormat::money($plan / 100),
			'',
			ReportFormat::money($actual / 100),
			ReportFormat::money(($actual - $plan) / 100),
			'', '',
		]);
	}

	/**
	 * Mehrjahresübersicht als Matrix (Spalten = Geschäftsjahre):
	 *  1. Erfolgsrechnung nach Konten je Zeitraum, plus Vermögen zum Zeitraumende.
	 *  2. Auswertung nach Kostenstellen/Projekten je Zeitraum.
	 *  3. Auswertung nach steuerlichen Sphären je Zeitraum.
	 *
	 * Die Spalten kommen aus den angelegten Zeiträumen, nicht aus den Jahreszahlen
	 * der Buchungen: seit Issue #8 kann ein Geschäftsjahr über den Jahreswechsel
	 * laufen, und nur der Zeitraum weiß, welche Buchungen in eine Spalte gehören.
	 *
	 * Ausgaben stehen mit negativem Vorzeichen, sodass sich das Ergebnis je
	 * Spalte als schlichte Summe der Zellen ergibt.
	 */
	public function multiyear(string $userId): CsvFile {
		$accounts = $this->accountMapper->findAll($userId);
		$periods = $this->periods->all($userId);
		usort($periods, static fn (Period $a, Period $b): int => strcmp($a->getStartDate(), $b->getStartDate()));

		// Die Perioden-ID ist der Spaltenschlüssel; die Bezeichnung beschriftet nur.
		$ids = [];
		$movById = [];
		$cumById = [];
		foreach ($periods as $period) {
			$id = (int)$period->getId();
			$ids[] = $id;
			$movById[$id] = $this->lineMapper->sumByAccount($userId, $period->getStartDate(), $period->getEndDate());
			$cumById[$id] = $this->lineMapper->sumByAccount($userId, null, $period->getEndDate());
		}

		usort($accounts, static fn ($a, $b) => strcmp((string)$a->getNumber(), (string)$b->getNumber()));

		$header = array_merge([''], array_map(static fn (Period $p): string => $p->getLabel(), $periods));

		$csv = self::BOM;
		$csv .= self::line(['Mehrjahresübersicht — Erfolgsrechnung nach Konten']);
		$csv .= self::line($header);

		// --- Einnahmen (Haben-Natur) ---
		$incomeTotals = array_fill_keys($ids, 0);
		$csv .= self::line(array_merge(['EINNAHMEN'], array_fill(0, count($ids), '')));
		$csv .= $this->accountMatrix($accounts, $ids, $movById, true, $incomeTotals);
		$csv .= $this->totalsLine('Summe Einnahmen', $ids, $incomeTotals);

		// --- Ausgaben (Soll-Natur, negativ dargestellt) ---
		$expenseTotals = array_fill_keys($ids, 0);
		$csv .= self::line(array_merge(['AUSGABEN'], array_fill(0, count($ids), '')));
		$csv .= $this->accountMatrix($accounts, $ids, $movById, false, $expenseTotals);
		$csv .= $this->totalsLine('Summe Ausgaben', $ids, $expenseTotals);

		// --- Ergebnis + Vermögen ---
		$resultCells = ['Ergebnis'];
		$wealthCells = ['Vermögen (Ende des Zeitraums)'];
		foreach ($ids as $id) {
			$resultCells[] = ReportFormat::money(($incomeTotals[$id] + $expenseTotals[$id]) / 100);
			// Da alle Konten außer Geldkonten und Eigenkapital erfolgswirksam sind,
			// gilt per doppelter Buchführung: Vermögen(J) = Vermögen(J−1) +
			// Ergebnis(J) – abweichend nur in Zeiträumen mit Eröffnungsbuchungen.
			$wealthCells[] = ReportFormat::money(LedgerAggregator::wealth($accounts, $cumById[$id]) / 100);
		}
		$csv .= self::line($resultCells);
		$csv .= self::line(array_fill(0, count($ids) + 1, ''));
		$csv .= self::line($wealthCells);

		$csv .= $this->costCenterMatrix($userId, $ids, $header);
		$csv .= $this->sphereMatrix($userId, $ids, $header);

		return new CsvFile($csv, 'mehrjahresuebersicht.csv');
	}

	/**
	 * Kontozeilen einer Seite der Mehrjahresmatrix. Konten ohne jeglichen Wert
	 * bleiben weg, damit die Matrix nicht von Nullzeilen überwuchert wird.
	 *
	 * @param Account[] $accounts
	 * @param int[] $ids Perioden-IDs in Spaltenreihenfolge
	 * @param array<int, array<int, array{debit:int, credit:int}>> $movById
	 * @param array<int,int> $totals
	 */
	private function accountMatrix(array $accounts, array $ids, array $movById, bool $creditSide, array &$totals): string {
		$csv = '';
		foreach ($accounts as $account) {
			if (!$account->isResultRelevant() || $account->isCreditNature() !== $creditSide) {
				continue;
			}
			$cells = [trim($account->getNumber() . ' ' . $account->getName())];
			$any = false;
			foreach ($ids as $id) {
				// Die Ausgabenseite wird negativ dargestellt; nur das Vorzeichen
				// ist Darstellung, der Betrag kommt aus LedgerAggregator::net().
				$value = LedgerAggregator::net($account, $movById[$id]);
				if (!$creditSide) {
					$value = -$value;
				}
				if ($value !== 0) {
					$any = true;
				}
				$totals[$id] += $value;
				$cells[] = ReportFormat::money($value / 100);
			}
			if ($any) {
				$csv .= self::line($cells);
			}
		}
		return $csv;
	}

	/**
	 * @param int[] $ids Perioden-IDs in Spaltenreihenfolge
	 * @param array<int,int> $totalsCents
	 */
	private function totalsLine(string $label, array $ids, array $totalsCents): string {
		$cells = [$label];
		foreach ($ids as $id) {
			$cells[] = ReportFormat::money(($totalsCents[$id] ?? 0) / 100);
		}
		return self::line($cells);
	}

	/**
	 * @param int[] $ids Perioden-IDs in Spaltenreihenfolge
	 * @param array<int, string> $header
	 */
	private function costCenterMatrix(string $userId, array $ids, array $header): string {
		$csv = self::line(array_fill(0, count($ids) + 1, ''));
		$csv .= self::line(['Ergebnis je Auswertungsgruppe (Abteilung, Projekt, Veranstaltung)']);
		$csv .= self::line($header);

		$resultByKey = [];
		$nameByKey = [];
		$totals = array_fill_keys($ids, 0.0);
		foreach ($ids as $id) {
			$report = $this->reportService->costCenterReport($userId, $id);
			foreach ($report['costCenters'] as $cc) {
				$key = ($cc['code'] ?? '') . '|' . $cc['name'];
				$nameByKey[$key] = trim(($cc['code'] ? $cc['code'] . ' ' : '') . $cc['name']);
				$resultByKey[$key][$id] = $cc['result'];
				$totals[$id] += $cc['result'];
			}
		}
		ksort($resultByKey);
		foreach ($resultByKey as $key => $byPeriod) {
			$cells = [$nameByKey[$key]];
			foreach ($ids as $id) {
				$cells[] = ReportFormat::money((float)($byPeriod[$id] ?? 0));
			}
			$csv .= self::line($cells);
		}
		$sumCells = ['Summe Auswertungsgruppen'];
		foreach ($ids as $id) {
			$sumCells[] = ReportFormat::money($totals[$id]);
		}
		return $csv . self::line($sumCells);
	}

	/**
	 * @param int[] $ids Perioden-IDs in Spaltenreihenfolge
	 * @param array<int, string> $header
	 */
	private function sphereMatrix(string $userId, array $ids, array $header): string {
		$csv = self::line(array_fill(0, count($ids) + 1, ''));
		$csv .= self::line(['Auswertung nach steuerlichen Sphären (Ergebnis) — ersetzt keine steuerliche Beratung']);
		$csv .= self::line($header);

		$resultByCode = [];
		$nameByCode = [];
		$totals = array_fill_keys($ids, 0.0);
		foreach ($ids as $id) {
			$report = $this->reportService->sphereReport($userId, $id);
			foreach ($report['spheres'] as $s) {
				$code = $s['code'] ?? '';
				$nameByCode[$code] = $s['name'];
				$resultByCode[$code][$id] = $s['result'];
				$totals[$id] += $s['result'];
			}
		}
		foreach ($nameByCode as $code => $name) {
			$cells = [$name];
			foreach ($ids as $id) {
				$cells[] = ReportFormat::money((float)($resultByCode[$code][$id] ?? 0));
			}
			$csv .= self::line($cells);
		}
		$sumCells = ['Summe Sphären'];
		foreach ($ids as $id) {
			$sumCells[] = ReportFormat::money($totals[$id]);
		}
		return $csv . self::line($sumCells);
	}
}
