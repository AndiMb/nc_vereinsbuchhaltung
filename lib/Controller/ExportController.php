<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

/**
 * CSV-Exporte für Journal, Saldenliste und Einnahmen-/Ausgaben-Übersicht.
 *
 * Alle Endpunkte sind #[NoCSRFRequired], damit der Browser die Datei direkt
 * per Link-Navigation herunterladen kann (kein AJAX nötig).
 * Die Session-Authentifizierung bleibt aktiv.
 */
class ExportController extends Controller {

	public function __construct(
		IRequest $request,
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountMapper $accountMapper,
		private BudgetMapper $budgetMapper,
		private ReportService $reportService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
	}

	/** @return array{0: ?string, 1: ?string} */
	private function yearRange(?int $year): array {
		if ($year === null || $year <= 0) {
			return [null, null];
		}
		return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
	}

	private function fmtMoney(float $eur): string {
		return number_format($eur, 2, ',', '.');
	}

	private function fmtDate(?string $iso): string {
		if (!$iso) {
			return '';
		}
		$parts = explode('-', $iso);
		return count($parts) === 3
			? $parts[2] . '.' . $parts[1] . '.' . $parts[0]
			: $iso;
	}

	/** Formatiert eine CSV-Zeile mit Semikolon und doppelt-quoted Feldern. */
	private function csvLine(array $fields): string {
		$escaped = array_map(
			static fn (string $f): string => '"' . str_replace('"', '""', $f) . '"',
			$fields,
		);
		return implode(';', $escaped) . "\r\n";
	}

	/**
	 * Journal aller Buchungssätze als CSV.
	 * Format: Nr.;Datum;Beschreibung;Belegnr.;Soll-Nr.;Soll-Konto;Haben-Nr.;Haben-Konto;Betrag (EUR)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function journal(?int $year = null): DataDownloadResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);

		$accountMap = [];
		foreach ($this->accountMapper->findAll($userId) as $a) {
			$accountMap[$a->getId()] = ['number' => $a->getNumber(), 'name' => $a->getName()];
		}

		$rows = [];
		foreach ($this->journalMapper->findAll($userId, 100000, 0, $from, $to) as $journal) {
			$lines = $this->lineMapper->findByJournal($journal->getId());
			$debitAcc = null;
			$creditAcc = null;
			$amountCents = 0;
			foreach ($lines as $line) {
				if ($line->getDebitCents() > 0) {
					$debitAcc = $accountMap[$line->getAccountId()] ?? null;
					$amountCents = $line->getDebitCents();
				}
				if ($line->getCreditCents() > 0) {
					$creditAcc = $accountMap[$line->getAccountId()] ?? null;
				}
			}
			$rows[] = [
				'sortDate'  => (string)$journal->getDate(),
				'sortEntry' => (int)($journal->getEntryNo() ?? 0),
				'entryNo'   => (string)($journal->getEntryNo() ?? ''),
				'date'      => $this->fmtDate((string)$journal->getDate()),
				'desc'      => (string)$journal->getDescription(),
				'docRef'    => (string)($journal->getDocumentRef() ?? ''),
				'debitNr'   => $debitAcc['number'] ?? '',
				'debitName' => $debitAcc['name'] ?? '',
				'creditNr'  => $creditAcc['number'] ?? '',
				'creditName'=> $creditAcc['name'] ?? '',
				'amount'    => $this->fmtMoney($amountCents / 100),
			];
		}

		usort($rows, static fn ($a, $b) => [$a['sortDate'], $a['sortEntry']] <=> [$b['sortDate'], $b['sortEntry']]);

		$yearLabel = $year ? (string)$year : 'alle_jahre';
		$csv = "\xEF\xBB\xBF"; // UTF-8 BOM für Excel
		$csv .= $this->csvLine(['Nr.', 'Datum', 'Beschreibung', 'Belegnr.', 'Soll-Nr.', 'Soll-Konto', 'Haben-Nr.', 'Haben-Konto', 'Betrag (EUR)']);
		foreach ($rows as $r) {
			$csv .= $this->csvLine([$r['entryNo'], $r['date'], $r['desc'], $r['docRef'], $r['debitNr'], $r['debitName'], $r['creditNr'], $r['creditName'], $r['amount']]);
		}

		return new DataDownloadResponse($csv, "journal_{$yearLabel}.csv", 'text/csv; charset=utf-8');
	}

	/**
	 * Saldenliste aller Konten als CSV.
	 * Format: Nr.;Konto;Typ;Kategorie;Soll (EUR);Haben (EUR);Saldo (EUR)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function balances(?int $year = null): DataDownloadResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		$balSums  = $from !== null ? $this->lineMapper->sumByAccount($userId, null, $to) : $moveSums;

		$isCreditNature = static fn (string $t): bool => in_array($t, ['income', 'liability', 'equity'], true);
		// Wie JournalController::balances(): kumulativ nur Geldkonten (Bank/Kasse,
		// siehe Account::isStockAccount()), alle anderen Konten jahresbezogen.
		$typeLabel      = static fn (string $t): string => match ($t) {
			'income'    => 'Einnahmen',
			'expense'   => 'Ausgaben',
			'asset'     => 'Anlage/Umlauf',
			'liability' => 'Verbindlichkeit',
			'equity'    => 'Eigenkapital',
			default     => $t,
		};

		$yearLabel = $year ? (string)$year : 'alle_jahre';
		$csv = "\xEF\xBB\xBF";
		$csv .= $this->csvLine(['Nr.', 'Konto', 'Typ', 'Kategorie', 'Soll (EUR)', 'Haben (EUR)', 'Saldo (EUR)']);

		foreach ($accounts as $account) {
			$id     = $account->getId();
			$type   = $account->getType();
			$debit  = $moveSums[$id]['debit'] ?? 0;
			$credit = $moveSums[$id]['credit'] ?? 0;
			if ($account->isStockAccount()) {
				$bd = $balSums[$id]['debit'] ?? 0;
				$bc = $balSums[$id]['credit'] ?? 0;
				$balance = $isCreditNature($type) ? $bc - $bd : $bd - $bc;
			} else {
				$balance = $isCreditNature($type) ? $credit - $debit : $debit - $credit;
			}
			$csv .= $this->csvLine([
				$account->getNumber(),
				$account->getName(),
				$typeLabel($type),
				(string)$account->getCategory(),
				$this->fmtMoney($debit / 100),
				$this->fmtMoney($credit / 100),
				$this->fmtMoney($balance / 100),
			]);
		}

		return new DataDownloadResponse($csv, "saldenliste_{$yearLabel}.csv", 'text/csv; charset=utf-8');
	}

	/**
	 * Einnahmen-/Ausgaben-Übersicht als CSV.
	 * Format: Typ;Nr.;Konto;Kategorie;Betrag (EUR)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function report(?int $year = null): DataDownloadResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);

		$yearLabel = $year ? (string)$year : 'alle_jahre';
		$csv = "\xEF\xBB\xBF";
		$csv .= $this->csvLine(['Typ', 'Nr.', 'Konto', 'Kategorie', 'Betrag (EUR)']);

		$income = [];
		$expense = [];
		$totalIncome = 0;
		$totalExpense = 0;

		// Erfolgswirksam sind alle Nicht-Geldkonten außer Eigenkapital; die Seite
		// ergibt sich aus der Kontonatur (siehe Account::isResultRelevant()).
		foreach ($accounts as $account) {
			if (!$account->isResultRelevant()) {
				continue;
			}
			$id = $account->getId();
			$d  = $moveSums[$id]['debit'] ?? 0;
			$c  = $moveSums[$id]['credit'] ?? 0;
			if ($account->isCreditNature()) {
				$amount = $c - $d;
				if ($amount !== 0) {
					$income[]     = [$account->getNumber(), $account->getName(), (string)$account->getCategory(), $amount];
					$totalIncome += $amount;
				}
			} else {
				$amount = $d - $c;
				if ($amount !== 0) {
					$expense[]     = [$account->getNumber(), $account->getName(), (string)$account->getCategory(), $amount];
					$totalExpense += $amount;
				}
			}
		}

		foreach ($income as [$nr, $name, $cat, $amount]) {
			$csv .= $this->csvLine(['Einnahmen', $nr, $name, $cat, $this->fmtMoney($amount / 100)]);
		}
		$csv .= $this->csvLine(['Einnahmen gesamt', '', '', '', $this->fmtMoney($totalIncome / 100)]);
		$csv .= $this->csvLine(['', '', '', '', '']);

		foreach ($expense as [$nr, $name, $cat, $amount]) {
			$csv .= $this->csvLine(['Ausgaben', $nr, $name, $cat, $this->fmtMoney($amount / 100)]);
		}
		$csv .= $this->csvLine(['Ausgaben gesamt', '', '', '', $this->fmtMoney($totalExpense / 100)]);
		$csv .= $this->csvLine(['', '', '', '', '']);

		$result = $totalIncome - $totalExpense;
		$csv .= $this->csvLine(['Ergebnis', '', '', '', $this->fmtMoney($result / 100)]);

		return new DataDownloadResponse($csv, "einnahmen_ausgaben_{$yearLabel}.csv", 'text/csv; charset=utf-8');
	}

	/**
	 * Finanzplan / Soll-Ist-Vergleich eines Jahres als CSV.
	 * Format: Typ;Nr.;Konto;Kategorie;Plan (EUR);Ist (EUR);Differenz (EUR)
	 *
	 * Spiegelt die Berechnung aus BudgetController::index() (Plan aus der
	 * Budget-Tabelle, Ist aus den Journalzeilen des Jahres).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function budget(?int $year = null): DataDownloadResponse {
		$userId = $this->userId();
		$year = ($year === null || $year <= 0) ? (int)date('Y') : $year;
		$from = sprintf('%04d-01-01', $year);
		$to   = sprintf('%04d-12-31', $year);

		$accounts   = $this->accountMapper->findAll($userId);
		$plan       = $this->budgetMapper->findByYear($userId, $year);
		$actualSums = $this->lineMapper->sumByAccount($userId, $from, $to);

		$typeLabel = static fn (string $t): string => $t === 'income' ? 'Einnahmen' : 'Ausgaben';

		$rows = [];
		$planIncome = 0; $actualIncome = 0;
		$planExpense = 0; $actualExpense = 0;
		foreach ($accounts as $account) {
			$type = $account->getType();
			if ($type !== 'income' && $type !== 'expense') {
				continue;
			}
			$id     = $account->getId();
			$debit  = $actualSums[$id]['debit'] ?? 0;
			$credit = $actualSums[$id]['credit'] ?? 0;
			$actualCents = $type === 'income' ? ($credit - $debit) : ($debit - $credit);
			$planCents   = $plan[$id]['amount'] ?? 0;
			$rows[] = [
				'number'   => (string)$account->getNumber(),
				'label'    => $typeLabel($type),
				'name'     => (string)$account->getName(),
				'category' => (string)$account->getCategory(),
				'plan'     => $planCents,
				'actual'   => $actualCents,
				'diff'     => $actualCents - $planCents,
				'note'     => $plan[$id]['note'] ?? '',
			];
			if ($type === 'income') {
				$planIncome += $planCents; $actualIncome += $actualCents;
			} else {
				$planExpense += $planCents; $actualExpense += $actualCents;
			}
		}

		usort($rows, static fn ($a, $b) => strcmp($a['number'], $b['number']));

		$csv = "\xEF\xBB\xBF";
		$csv .= $this->csvLine(['Typ', 'Nr.', 'Konto', 'Kategorie', 'Plan (EUR)', 'Ist (EUR)', 'Differenz (EUR)', 'Notiz']);
		foreach ($rows as $r) {
			$csv .= $this->csvLine([
				$r['label'], $r['number'], $r['name'], $r['category'],
				$this->fmtMoney($r['plan'] / 100),
				$this->fmtMoney($r['actual'] / 100),
				$this->fmtMoney($r['diff'] / 100),
				$r['note'],
			]);
		}

		$csv .= $this->csvLine(['', '', '', '', '', '', '', '']);
		$csv .= $this->csvLine(['Einnahmen (Plan/Ist)', '', '', '', $this->fmtMoney($planIncome / 100), $this->fmtMoney($actualIncome / 100), $this->fmtMoney(($actualIncome - $planIncome) / 100), '']);
		$csv .= $this->csvLine(['Ausgaben (Plan/Ist)', '', '', '', $this->fmtMoney($planExpense / 100), $this->fmtMoney($actualExpense / 100), $this->fmtMoney(($actualExpense - $planExpense) / 100), '']);
		$planResult   = $planIncome - $planExpense;
		$actualResult = $actualIncome - $actualExpense;
		$csv .= $this->csvLine(['Ergebnis (Plan/Ist)', '', '', '', $this->fmtMoney($planResult / 100), $this->fmtMoney($actualResult / 100), $this->fmtMoney(($actualResult - $planResult) / 100), '']);

		return new DataDownloadResponse($csv, "finanzplan_soll_ist_{$year}.csv", 'text/csv; charset=utf-8');
	}

	/**
	 * Mehrjahresübersicht als CSV-Matrix (Spalten = Geschäftsjahre):
	 *  1. Erfolgsrechnung nach Konten (Einnahmen, Ausgaben, Ergebnis) je Jahr,
	 *     plus Vermögen (kumulierter Bestand asset − liability zum 31.12.).
	 *  2. Auswertung nach Kostenstellen/Projekten (Ergebnis je Kostenstelle) je Jahr.
	 *
	 * Ausgaben werden mit negativem Vorzeichen dargestellt, sodass sich das
	 * Ergebnis je Spalte als Summe von Einnahmen + Ausgaben ergibt.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function multiyear(): DataDownloadResponse {
		$userId = $this->userId();
		$accounts = $this->accountMapper->findAll($userId);

		$years = $this->journalMapper->distinctYears($userId);
		sort($years); // aufsteigend für die Spaltenreihenfolge

		// Bewegungen je Jahr und kumulierter Bestand bis Jahresende je Konto.
		$movByYear = [];
		$cumByYear = [];
		foreach ($years as $y) {
			$movByYear[$y] = $this->lineMapper->sumByAccount($userId, sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y));
			$cumByYear[$y] = $this->lineMapper->sumByAccount($userId, null, sprintf('%04d-12-31', $y));
		}

		$byNumberSort = static fn ($a, $b) => strcmp((string)$a->getNumber(), (string)$b->getNumber());
		usort($accounts, $byNumberSort);

		// Vorzeichenbehafteter Jahreswert nach Kontonatur (Einnahme +, Ausgabe −).
		// Erfolgswirksam sind ALLE Nicht-Geldkonten außer Eigenkapital (siehe
		// Account::isResultRelevant()) – auch z.B. Durchlauf-/Übertragskonten
		// erscheinen mit ihrer Netto-Jahresbewegung wie jedes andere Konto.
		$yearValueCents = static function (array $sums, int $accId, bool $creditNature): int {
			$debit = $sums[$accId]['debit'] ?? 0;
			$credit = $sums[$accId]['credit'] ?? 0;
			return $creditNature ? ($credit - $debit) : -($debit - $credit);
		};

		$header = array_merge([''], array_map(static fn ($y) => (string)$y, $years));

		$csv = "\xEF\xBB\xBF";
		$csv .= $this->csvLine(['Mehrjahresübersicht — Erfolgsrechnung nach Konten']);
		$csv .= $this->csvLine($header);

		// --- Einnahmen (Haben-Natur: income + liability) ---
		$incomeTotals = array_fill_keys($years, 0);
		$csv .= $this->csvLine(array_merge(['EINNAHMEN'], array_fill(0, count($years), '')));
		foreach ($accounts as $a) {
			if (!$a->isResultRelevant() || !$a->isCreditNature()) {
				continue;
			}
			[$row, $any] = $this->accountYearRow($a, $years, $movByYear, $yearValueCents, $incomeTotals);
			if ($any) {
				$csv .= $row;
			}
		}
		$csv .= $this->totalsLine('Summe Einnahmen', $years, $incomeTotals);

		// --- Ausgaben (Soll-Natur: expense + asset ohne Geldkonten, negativ dargestellt) ---
		$expenseTotals = array_fill_keys($years, 0);
		$csv .= $this->csvLine(array_merge(['AUSGABEN'], array_fill(0, count($years), '')));
		foreach ($accounts as $a) {
			if (!$a->isResultRelevant() || $a->isCreditNature()) {
				continue;
			}
			[$row, $any] = $this->accountYearRow($a, $years, $movByYear, $yearValueCents, $expenseTotals);
			if ($any) {
				$csv .= $row;
			}
		}
		$csv .= $this->totalsLine('Summe Ausgaben', $years, $expenseTotals);

		// --- Ergebnis + Vermögen ---
		$resultCells = ['Ergebnis'];
		$wealthCells = ['Vermögen (31.12.)'];
		foreach ($years as $y) {
			$resultCells[] = $this->fmtMoney(($incomeTotals[$y] + $expenseTotals[$y]) / 100);
			// Vermögen = kumulierte Bestände der Geldkonten (Bank/Kasse, debit-Natur).
			// Da alle übrigen Konten außer Eigenkapital erfolgswirksam sind, gilt
			// per doppelter Buchführung: Vermögen(J) = Vermögen(J−1) + Ergebnis(J)
			// (abweichend nur in Jahren mit Eröffnungsbuchungen gegen Eigenkapital).
			$wealthCents = 0;
			foreach ($accounts as $a) {
				if (!$a->isStockAccount()) {
					continue;
				}
				$id = $a->getId();
				$debit = $cumByYear[$y][$id]['debit'] ?? 0;
				$credit = $cumByYear[$y][$id]['credit'] ?? 0;
				$wealthCents += $debit - $credit;
			}
			$wealthCells[] = $this->fmtMoney($wealthCents / 100);
		}
		$csv .= $this->csvLine($resultCells);
		$csv .= $this->csvLine(array_fill(0, count($years) + 1, ''));
		$csv .= $this->csvLine($wealthCells);

		// --- Kostenstellen / Projekte ---
		$csv .= $this->csvLine(array_fill(0, count($years) + 1, ''));
		$csv .= $this->csvLine(['Auswertung nach Kostenstellen / Projekten (Ergebnis)']);
		$csv .= $this->csvLine($header);

		$ccResultByKey = [];
		$ccNameByKey = [];
		$ccTotals = array_fill_keys($years, 0.0);
		foreach ($years as $y) {
			$report = $this->reportService->costCenterReport($userId, $y);
			foreach ($report['costCenters'] as $cc) {
				$key = ($cc['code'] ?? '') . '|' . $cc['name'];
				$ccNameByKey[$key] = trim(($cc['code'] ? $cc['code'] . ' ' : '') . $cc['name']);
				$ccResultByKey[$key][$y] = $cc['result'];
				$ccTotals[$y] += $cc['result'];
			}
		}
		ksort($ccResultByKey);
		foreach ($ccResultByKey as $key => $byYear) {
			$cells = [$ccNameByKey[$key]];
			foreach ($years as $y) {
				$cells[] = $this->fmtMoney((float)($byYear[$y] ?? 0));
			}
			$csv .= $this->csvLine($cells);
		}
		$sumCells = ['Summe Kostenstellen'];
		foreach ($years as $y) {
			$sumCells[] = $this->fmtMoney($ccTotals[$y]);
		}
		$csv .= $this->csvLine($sumCells);

		return new DataDownloadResponse($csv, 'mehrjahresuebersicht.csv', 'text/csv; charset=utf-8');
	}

	/**
	 * Baut eine Kontozeile (Nr. Name + Jahreswerte) und summiert die Werte in
	 * $totals auf. Gibt [csvZeile, hatWerte] zurück – Konten ohne jeglichen Wert
	 * werden vom Aufrufer ausgelassen.
	 *
	 * @param int[] $years
	 * @param array<int, array<int, array{debit:int, credit:int}>> $movByYear
	 * @param callable(array,int,bool):int $yearValueCents
	 * @param array<int,int> $totals
	 * @return array{0:string, 1:bool}
	 */
	private function accountYearRow($account, array $years, array $movByYear, callable $yearValueCents, array &$totals): array {
		$id = $account->getId();
		$creditNature = $account->isCreditNature();
		$cells = [trim($account->getNumber() . ' ' . $account->getName())];
		$any = false;
		foreach ($years as $y) {
			$v = $yearValueCents($movByYear[$y], $id, $creditNature);
			if ($v !== 0) {
				$any = true;
			}
			$totals[$y] += $v;
			$cells[] = $this->fmtMoney($v / 100);
		}
		return [$this->csvLine($cells), $any];
	}

	/**
	 * @param int[] $years
	 * @param array<int,int> $totalsCents
	 */
	private function totalsLine(string $label, array $years, array $totalsCents): string {
		$cells = [$label];
		foreach ($years as $y) {
			$cells[] = $this->fmtMoney(($totalsCents[$y] ?? 0) / 100);
		}
		return $this->csvLine($cells);
	}
}
