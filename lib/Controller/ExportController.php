<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\YearCloseMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\BrandingService;
use OCA\Vereinsbuchhaltung\Service\CsvFormatter;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;

/**
 * CSV-Exporte für Journal, Saldenliste und Einnahmen-/Ausgaben-Übersicht.
 *
 * Alle Endpunkte sind #[NoCSRFRequired], damit der Browser die Datei direkt
 * per Link-Navigation herunterladen kann (kein AJAX nötig).
 * Die Session-Authentifizierung bleibt aktiv.
 */
class ExportController extends Controller {

	use BookContext;

	/**
	 * Antwort für die druckfertigen HTML-Ansichten.
	 *
	 * Diese Seiten bringen ihr Stylesheet inline mit. Ohne eigene Richtlinie
	 * gilt Nextclouds Vorgabe `default-src 'none'`; der Browser verwirft das
	 * <style>-Element dann stillschweigend und der Bericht erscheint völlig
	 * unformatiert – ohne A4-Breite, Tabellenlinien und Unterschriftszeilen.
	 *
	 * Bewusst von EmptyContentSecurityPolicy aus aufgebaut: erlaubt wird nur
	 * das Nötigste, Skripte und fremde Quellen bleiben gesperrt.
	 */
	private function printableResponse(string $html, bool $withImages = false): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$html,
			Http::STATUS_OK,
			['Content-Type' => 'text/html; charset=utf-8'],
		);
		$policy = new EmptyContentSecurityPolicy();
		$policy->allowInlineStyle(true);
		if ($withImages) {
			// Kurzbericht: Vereinslogo aus der eigenen Instanz.
			$policy->addAllowedImageDomain("'self'");
		}
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	public function __construct(
		IRequest $request,
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountMapper $accountMapper,
		private BudgetMapper $budgetMapper,
		private ReportService $reportService,
		private YearCloseMapper $yearCloseMapper,
		private IConfig $config,
		private AttachmentMapper $attachmentMapper,
		private AttachmentStorageService $storageService,
		private ITempManager $tempManager,
		private BrandingService $branding,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
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

	/**
	 * Formatiert eine CSV-Zeile (siehe {@see CsvFormatter} – dort steckt auch
	 * die Absicherung gegen Felder, die eine Tabellenkalkulation als Formel
	 * auffassen würde).
	 *
	 * @param array<int, string> $fields
	 */
	private function csvLine(array $fields): string {
		return CsvFormatter::line($fields);
	}

	/**
	 * Journal aller Buchungssätze als CSV.
	 * Format: Nr.;Datum;Beschreibung;Belegnr.;Soll-Nr.;Soll-Konto;Haben-Nr.;Haben-Konto;Betrag (EUR)
	 *
	 * Eine Splittbuchung belegt mehrere Zeilen mit derselben Nummer, siehe
	 * {@see JournalService::pairLines()}.
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
					'amount'    => $this->fmtMoney($pair['amountCents'] / 100),
				];
			}
		}

		// Die Ausgabezeilen einer Splittbuchung tragen dieselbe Nummer und
		// dasselbe Datum; ihre Reihenfolge untereinander bleibt damit die aus
		// pairLines() (usort in PHP 8 ist stabil).
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
				$balance = $account->isCreditNature() ? $bc - $bd : $bd - $bc;
			} else {
				$balance = $account->isCreditNature() ? $credit - $debit : $debit - $credit;
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
		$year = FiscalYear::orCurrent($year);
		$from = FiscalYear::start($year);
		$to   = FiscalYear::end($year);

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
			$movByYear[$y] = $this->lineMapper->sumByAccount($userId, FiscalYear::start($y), FiscalYear::end($y));
			$cumByYear[$y] = $this->lineMapper->sumByAccount($userId, null, FiscalYear::end($y));
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

		// --- Steuerliche Sphären (Ergebnis) ---
		$csv .= $this->csvLine(array_fill(0, count($years) + 1, ''));
		$csv .= $this->csvLine(['Auswertung nach steuerlichen Sphären (Ergebnis) — ersetzt keine steuerliche Beratung']);
		$csv .= $this->csvLine($header);

		$sphereResultByCode = [];
		$sphereNameByCode = [];
		$sphereTotals = array_fill_keys($years, 0.0);
		foreach ($years as $y) {
			$report = $this->reportService->sphereReport($userId, $y);
			foreach ($report['spheres'] as $s) {
				$code = $s['code'] ?? '';
				$sphereNameByCode[$code] = $s['name'];
				$sphereResultByCode[$code][$y] = $s['result'];
				$sphereTotals[$y] += $s['result'];
			}
		}
		foreach ($sphereNameByCode as $code => $name) {
			$cells = [$name];
			foreach ($years as $y) {
				$cells[] = $this->fmtMoney((float)($sphereResultByCode[$code][$y] ?? 0));
			}
			$csv .= $this->csvLine($cells);
		}
		$sphereSumCells = ['Summe Sphären'];
		foreach ($years as $y) {
			$sphereSumCells[] = $this->fmtMoney($sphereTotals[$y]);
		}
		$csv .= $this->csvLine($sphereSumCells);

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

	private function esc(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES);
	}

	/** Ist das Geschäftsjahr bereits festgeschrieben? */
	private function isYearClosed(int $year): bool {
		try {
			$this->yearCloseMapper->findByYear($year);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/** Dateisystem-tauglicher Name für ZIP-Einträge (Umlaute bleiben erhalten). */
	private function zipName(string $s, int $maxLen = 48): string {
		$s = preg_replace('/[\\\\\/:*?"<>|[:cntrl:]]/u', '_', $s) ?? '_';
		$s = trim(preg_replace('/\s+/u', ' ', $s) ?? '', ' ._');
		if ($s === '') {
			$s = '_';
		}
		return mb_substr($s, 0, $maxLen);
	}

	/**
	 * Schreibt einen Beleg in eine lokale Temp-Datei und gibt deren Pfad zurück.
	 *
	 * Der Umweg über die Platte ist Absicht. Der Beleg liegt je nach
	 * Einstellung im Nextcloud-Dateibaum eines Nutzers und ist damit nicht
	 * zwingend eine lokale Datei, die ZipArchive::addFile() öffnen könnte.
	 * Ihn stattdessen mit getContent() in eine Variable zu holen, brächte
	 * genau das memory_limit-Problem zurück, das der Streaming-Ansatz
	 * vermeiden sollte: ein Jahr voller PDFs (bis 20 MB je Beleg) sprengt den
	 * Speicher – ausgerechnet in der Funktion, die die Kassenprüfung braucht.
	 * stream_copy_to_stream() kopiert blockweise und hält nie mehr als einen
	 * Puffer im Speicher.
	 *
	 * Die Temp-Dateien müssen bis zum ZipArchive::close() bestehen bleiben –
	 * erst dort liest ZipArchive die per addFile() angemeldeten Dateien. Der
	 * ITempManager räumt sie am Ende der Anfrage ab.
	 *
	 * @throws \RuntimeException wenn der Beleg nicht lesbar ist
	 */
	private function spoolToTempFile(int $id, int $journalId, string $fileName): string {
		$source = $this->storageService->getFileStream($id, $journalId, $fileName);
		try {
			$target = $this->tempManager->getTemporaryFile('.beleg');
			$sink = fopen($target, 'wb');
			if ($sink === false) {
				throw new \RuntimeException('Zwischendatei für den Beleg konnte nicht angelegt werden.');
			}
			try {
				if (stream_copy_to_stream($source, $sink) === false) {
					throw new \RuntimeException('Beleg konnte nicht gelesen werden.');
				}
			} finally {
				fclose($sink);
			}
		} finally {
			if (is_resource($source)) {
				fclose($source);
			}
		}
		return $target;
	}

	/**
	 * Alle Belege eines Geschäftsjahres als ZIP – für die Kassenprüfung.
	 * Ordner je Buchung: "NNNN_Datum_Beschreibung/<BelegID>_<Dateiname>".
	 * Nicht auffindbare Dateien brechen den Export nicht ab, sondern werden
	 * in fehlende_dateien.txt aufgelistet.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attachments(?int $year = null): StreamResponse|DataDownloadResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$yearLabel = $year ? (string)$year : 'alle_jahre';

		$zipPath = $this->tempManager->getTemporaryFile('.zip');
		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('ZIP-Datei konnte nicht erstellt werden.');
		}

		$count = 0;
		$problems = [];
		$journals = $this->journalMapper->findAll($userId, 100000, 0, $from, $to);
		// Belegliste gebündelt laden statt je Buchung.
		$attsByJournal = $this->attachmentMapper->findByJournals(array_map(
			static fn ($j): int => $j->getId(),
			$journals,
		));
		foreach ($journals as $journal) {
			$atts = $attsByJournal[$journal->getId()] ?? [];
			if ($atts === []) {
				continue;
			}
			$folder = $this->zipName(sprintf(
				'%04d_%s_%s',
				(int)($journal->getEntryNo() ?? 0),
				(string)$journal->getDate(),
				(string)$journal->getDescription(),
			), 80);
			foreach ($atts as $att) {
				$entryName = $folder . '/' . $att->getId() . '_' . $this->zipName($att->getFileName(), 100);
				try {
					$localPath = $this->spoolToTempFile($att->getId(), $att->getJournalId(), $att->getFileName());
				} catch (\Throwable) {
					$problems[] = sprintf('Buchung #%s (%s): Datei "%s" (Beleg %d) nicht gefunden.',
						(string)($journal->getEntryNo() ?? '?'), (string)$journal->getDate(), $att->getFileName(), $att->getId());
					continue;
				}
				$zip->addFile($localPath, $entryName);
				$count++;
			}
		}

		if ($count === 0) {
			$zip->addFromString('hinweis.txt', "Keine Belege im gewählten Zeitraum gefunden.\n");
		}
		if ($problems !== []) {
			$zip->addFromString('fehlende_dateien.txt', implode("\n", $problems) . "\n");
		}
		$zip->close();

		// Die fertige Datei ausliefern, ohne sie noch einmal komplett in den
		// Speicher zu lesen. Der Temp-Ordner wird von Nextcloud aufgeräumt.
		$response = new StreamResponse($zipPath);
		$response->addHeader('Content-Type', 'application/zip');
		$response->addHeader('Content-Length', (string)(filesize($zipPath) ?: 0));
		$response->addHeader('Content-Disposition', 'attachment; filename="belege_' . $yearLabel . '.zip"');
		return $response;
	}

	/**
	 * Druckfertiger Kassenbericht für die Mitgliederversammlung als
	 * eigenständige HTML-Seite (Drucken/Als-PDF-speichern über den Browser).
	 * Kein Server-PDF nötig, kein JavaScript (Nextcloud-CSP), Print-CSS inline.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kassenbericht(?int $year = null): DataDisplayResponse {
		$userId = $this->userId();
		$year = FiscalYear::orCurrent($year);
		$from = FiscalYear::start($year);
		$to = FiscalYear::end($year);
		$prevTo = FiscalYear::end($year - 1);

		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		$cumEnd = $this->lineMapper->sumByAccount($userId, null, $to);
		$cumStart = $this->lineMapper->sumByAccount($userId, null, $prevTo);

		// --- Einnahmen / Ausgaben (wie report()) ---
		$income = [];
		$expense = [];
		$totalIncome = 0;
		$totalExpense = 0;
		foreach ($accounts as $account) {
			if (!$account->isResultRelevant()) {
				continue;
			}
			$id = $account->getId();
			$d = $moveSums[$id]['debit'] ?? 0;
			$c = $moveSums[$id]['credit'] ?? 0;
			if ($account->isCreditNature()) {
				$amount = $c - $d;
				if ($amount !== 0) {
					$income[] = [$account->getNumber(), $account->getName(), $amount];
					$totalIncome += $amount;
				}
			} else {
				$amount = $d - $c;
				if ($amount !== 0) {
					$expense[] = [$account->getNumber(), $account->getName(), $amount];
					$totalExpense += $amount;
				}
			}
		}
		$result = $totalIncome - $totalExpense;

		// --- Vermögensübersicht: Geldkonten (Bank/Kasse), Bestand kumulativ ---
		$wealthRows = [];
		$wealthStart = 0;
		$wealthEnd = 0;
		foreach ($accounts as $account) {
			if (!$account->isStockAccount()) {
				continue;
			}
			$id = $account->getId();
			$start = ($cumStart[$id]['debit'] ?? 0) - ($cumStart[$id]['credit'] ?? 0);
			$end = ($cumEnd[$id]['debit'] ?? 0) - ($cumEnd[$id]['credit'] ?? 0);
			if ($start === 0 && $end === 0) {
				continue;
			}
			$wealthRows[] = [$account->getNumber(), $account->getName(), $start, $end];
			$wealthStart += $start;
			$wealthEnd += $end;
		}

		// --- Soll-Ist (nur wenn Planwerte existieren, wie budget()) ---
		$plan = $this->budgetMapper->findByYear($userId, $year);
		$planRows = [];
		$planIncome = 0; $actualIncome = 0;
		$planExpense = 0; $actualExpense = 0;
		if ($plan !== []) {
			foreach ($accounts as $account) {
				$type = $account->getType();
				if ($type !== 'income' && $type !== 'expense') {
					continue;
				}
				$id = $account->getId();
				$d = $moveSums[$id]['debit'] ?? 0;
				$c = $moveSums[$id]['credit'] ?? 0;
				$actualCents = $type === 'income' ? ($c - $d) : ($d - $c);
				$planCents = $plan[$id]['amount'] ?? 0;
				if ($planCents === 0 && $actualCents === 0) {
					continue;
				}
				$planRows[] = [$account->getNumber(), $account->getName(), $type, $planCents, $actualCents];
				if ($type === 'income') {
					$planIncome += $planCents; $actualIncome += $actualCents;
				} else {
					$planExpense += $planCents; $actualExpense += $actualCents;
				}
			}
			usort($planRows, static fn ($a, $b) => strcmp((string)$a[0], (string)$b[0]));
		}

		// --- Buchungszahl + Lückenprüfung der Buchungsnummern ---
		$entryNos = [];
		foreach ($this->journalMapper->findAll($userId, 100000, 0, $from, $to) as $journal) {
			$no = $journal->getEntryNo();
			if ($no !== null) {
				$entryNos[] = (int)$no;
			}
		}
		sort($entryNos);
		$bookingCount = count($entryNos);
		$missing = [];
		$duplicates = [];
		if ($bookingCount > 0) {
			$prev = null;
			foreach ($entryNos as $no) {
				if ($prev !== null) {
					if ($no === $prev) {
						$duplicates[] = $no;
					}
					for ($i = $prev + 1; $i < $no && count($missing) <= 20; $i++) {
						$missing[] = $i;
					}
				}
				$prev = $no;
			}
		}
		$numbering = $bookingCount === 0
			? 'Keine Buchungen im Geschäftsjahr.'
			: sprintf('%d Buchungen (Nr. %d–%d)', $bookingCount, $entryNos[0], $entryNos[$bookingCount - 1]);
		if ($bookingCount > 0 && !$missing && !$duplicates) {
			$numbering .= ', Buchungsnummern lückenlos.';
		} elseif ($missing || $duplicates) {
			$hints = [];
			if ($missing) {
				$hints[] = 'fehlende Nummern: ' . implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ' …' : '');
			}
			if ($duplicates) {
				$hints[] = 'doppelte Nummern: ' . implode(', ', array_unique($duplicates));
			}
			$numbering .= ' – ⚠ ' . implode('; ', $hints);
			// Seit der Nachnummerierung (EntryNumberService) schließt die App
			// Lücken beim Löschen selbst; in einem offenen Jahr kann hier also
			// eigentlich nichts mehr stehen. Bleibt eine Lücke in einem bereits
			// festgeschriebenen Jahr, stammt sie aus einer älteren Version –
			// dann hilft nur Wiedereröffnen und erneut Abschließen.
			if ($missing && $this->isYearClosed($year)) {
				$numbering .= ' (Lücken aus einer früheren Programmversion; sie verschwinden, '
					. 'wenn das Jahr einmal wiedereröffnet und erneut abgeschlossen wird)';
			}
		}

		// --- Abschlussvermerk ---
		try {
			$close = $this->yearCloseMapper->findByYear($year);
			$closeNote = sprintf(
				'Das Geschäftsjahr %d wurde am %s von %s abgeschlossen (festgeschrieben).',
				$year,
				$this->fmtDate(substr((string)$close->getClosedAt(), 0, 10)),
				$close->getClosedBy(),
			);
		} catch (DoesNotExistException) {
			$closeNote = sprintf('Das Geschäftsjahr %d ist noch nicht abgeschlossen.', $year);
		}

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$title = ($clubName !== '' ? $clubName . ' – ' : '') . 'Kassenbericht ' . $year;

		$m = fn (int $cents): string => $this->fmtMoney($cents / 100) . ' €';
		$h = '';

		$h .= '<div class="noprint">Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.</div>';
		$h .= '<header>';
		if ($clubName !== '') {
			$h .= '<div class="club">' . $this->esc($clubName) . '</div>';
		}
		$h .= '<h1>Kassenbericht für das Geschäftsjahr ' . $year . '</h1>';
		$h .= '<div class="meta">Erstellt am ' . $this->fmtDate(date('Y-m-d')) . ' · ' . $this->esc($closeNote) . '</div>';
		$h .= '</header>';

		// Vermögensübersicht
		$h .= '<section><h2>Vermögensübersicht (Geldkonten)</h2><table>';
		$h .= '<tr><th>Konto</th><th class="num">Bestand 01.01.</th><th class="num">Bestand 31.12.</th><th class="num">Veränderung</th></tr>';
		foreach ($wealthRows as [$nr, $name, $start, $end]) {
			$h .= '<tr><td>' . $this->esc(trim($nr . ' ' . $name)) . '</td><td class="num">' . $m($start) . '</td><td class="num">' . $m($end) . '</td><td class="num">' . $m($end - $start) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td>Gesamtvermögen</td><td class="num">' . $m($wealthStart) . '</td><td class="num">' . $m($wealthEnd) . '</td><td class="num">' . $m($wealthEnd - $wealthStart) . '</td></tr>';
		$h .= '</table></section>';

		// Einnahmen / Ausgaben
		$h .= '<section><h2>Einnahmen-/Ausgaben-Rechnung</h2><table>';
		$h .= '<tr><th colspan="2">Einnahmen</th><th class="num">Betrag</th></tr>';
		foreach ($income as [$nr, $name, $amount]) {
			$h .= '<tr><td class="nr">' . $this->esc((string)$nr) . '</td><td>' . $this->esc((string)$name) . '</td><td class="num">' . $m($amount) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td colspan="2">Summe Einnahmen</td><td class="num">' . $m($totalIncome) . '</td></tr>';
		$h .= '<tr><th colspan="2">Ausgaben</th><th class="num">Betrag</th></tr>';
		foreach ($expense as [$nr, $name, $amount]) {
			$h .= '<tr><td class="nr">' . $this->esc((string)$nr) . '</td><td>' . $this->esc((string)$name) . '</td><td class="num">' . $m($amount) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td colspan="2">Summe Ausgaben</td><td class="num">' . $m($totalExpense) . '</td></tr>';
		$h .= '<tr class="result"><td colspan="2">Jahresergebnis</td><td class="num">' . $m($result) . '</td></tr>';
		$h .= '</table></section>';

		// Sphärenübersicht (steuerlich)
		$sphereReport = $this->reportService->sphereReport($userId, $year);
		$h .= '<section><h2>Sphärenübersicht (steuerlich)</h2><table>';
		$h .= '<tr><th>Sphäre</th><th class="num">Einnahmen</th><th class="num">Ausgaben</th><th class="num">Ergebnis</th></tr>';
		foreach ($sphereReport['spheres'] as $s) {
			$h .= '<tr><td>' . $this->esc((string)$s['name']) . '</td><td class="num">' . $this->fmtMoney((float)$s['income']) . ' €</td><td class="num">'
				. $this->fmtMoney((float)$s['expense']) . ' €</td><td class="num">' . $this->fmtMoney((float)$s['result']) . ' €</td></tr>';
		}
		$h .= '</table>';
		$fg = $sphereReport['freigrenze'];
		if ($fg['incomeCents'] > 0) {
			$levelText = $fg['level'] === 'over' ? 'überschritten' : ($fg['level'] === 'warn' ? 'nähert sich der Grenze' : 'im grünen Bereich');
			$h .= '<p>Wirtschaftlicher Geschäftsbetrieb: ' . $this->fmtMoney((float)$fg['income']) . ' € von ' . $this->fmtMoney((float)$fg['threshold'])
				. ' € Freigrenze (' . round(((float)$fg['ratio']) * 100) . ' % – ' . $levelText . ').</p>';
		}
		$h .= '<p class="meta">Ersetzt keine steuerliche Beratung.</p>';
		$h .= '</section>';

		// Soll-Ist
		if ($planRows !== []) {
			$h .= '<section><h2>Soll-Ist-Vergleich (Finanzplan)</h2><table>';
			$h .= '<tr><th colspan="2">Konto</th><th class="num">Plan</th><th class="num">Ist</th><th class="num">Differenz</th></tr>';
			foreach ($planRows as [$nr, $name, $type, $planCents, $actualCents]) {
				$h .= '<tr><td class="nr">' . $this->esc((string)$nr) . '</td><td>' . $this->esc((string)$name)
					. '</td><td class="num">' . $m($planCents) . '</td><td class="num">' . $m($actualCents)
					. '</td><td class="num">' . $m($actualCents - $planCents) . '</td></tr>';
			}
			$h .= '<tr class="sum"><td colspan="2">Einnahmen</td><td class="num">' . $m($planIncome) . '</td><td class="num">' . $m($actualIncome) . '</td><td class="num">' . $m($actualIncome - $planIncome) . '</td></tr>';
			$h .= '<tr class="sum"><td colspan="2">Ausgaben</td><td class="num">' . $m($planExpense) . '</td><td class="num">' . $m($actualExpense) . '</td><td class="num">' . $m($actualExpense - $planExpense) . '</td></tr>';
			$h .= '<tr class="result"><td colspan="2">Ergebnis</td><td class="num">' . $m($planIncome - $planExpense) . '</td><td class="num">' . $m($actualIncome - $actualExpense) . '</td><td class="num">' . $m(($actualIncome - $actualExpense) - ($planIncome - $planExpense)) . '</td></tr>';
			$h .= '</table></section>';
		}

		// Vollständigkeit
		$h .= '<section><h2>Vollständigkeit</h2><p>' . $this->esc($numbering) . '</p></section>';

		// Unterschriften
		$h .= '<section class="signatures">';
		$h .= '<div><div class="line"></div>Ort, Datum · Schatzmeister/in</div>';
		$h .= '<div><div class="line"></div>Ort, Datum · Kassenprüfer/in</div>';
		$h .= '<div><div class="line"></div>Ort, Datum · Kassenprüfer/in</div>';
		$h .= '</section>';

		$css = '
			* { box-sizing: border-box; }
			body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #222; margin: 0 auto; padding: 24px; max-width: 210mm; font-size: 11pt; }
			header { border-bottom: 2px solid #222; margin-bottom: 18px; padding-bottom: 10px; }
			.club { font-size: 13pt; font-weight: 600; }
			h1 { font-size: 16pt; margin: 4px 0; }
			h2 { font-size: 12pt; margin: 0 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
			.meta { color: #555; font-size: 9pt; }
			section { margin-bottom: 20px; page-break-inside: avoid; }
			table { width: 100%; border-collapse: collapse; }
			th, td { text-align: left; padding: 3px 6px; border-bottom: 1px solid #ddd; vertical-align: top; }
			th { border-bottom: 1px solid #999; padding-top: 8px; }
			td.nr, th.nr { width: 60px; color: #555; }
			.num { text-align: right; white-space: nowrap; }
			tr.sum td { font-weight: 600; border-top: 1px solid #999; }
			tr.result td { font-weight: 700; border-top: 2px solid #222; border-bottom: 2px solid #222; }
			.signatures { display: flex; gap: 24px; margin-top: 60px; page-break-inside: avoid; }
			.signatures > div { flex: 1; font-size: 9pt; color: #555; }
			.signatures .line { border-bottom: 1px solid #222; height: 40px; margin-bottom: 4px; }
			.noprint { background: #fffbe6; border: 1px solid #e0d8a0; padding: 8px 12px; margin-bottom: 16px; font-size: 10pt; }
			@media print { .noprint { display: none; } body { padding: 0; } }
			@page { margin: 18mm 15mm; }
		';

		$html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<title>' . $this->esc($title) . '</title>'
			. '<style>' . $css . '</style></head><body>' . $h . '</body></html>';

		return $this->printableResponse($html);
	}

	/**
	 * Kurzbericht für die nächste Vorstandssitzung: Kontostände + Bewegungen
	 * seit einem wählbaren Stichtag, optional im Corporate Design (Logo +
	 * Akzentfarbe, siehe BrandingService). Bewusst eine eigenständige Methode
	 * statt Verzweigung in kassenbericht() – gleiche Risikominimierung wie
	 * bei den anderen neuen Berichten dieser Session.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kurzbericht(?string $since = null): DataDisplayResponse {
		$userId = $this->userId();
		$today = date('Y-m-d');
		if ($since === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) || $since >= $today) {
			$since = FiscalYear::start((int)date('Y'));
		}
		$beforeSince = date('Y-m-d', strtotime($since . ' -1 day'));

		$accounts = $this->accountMapper->findAll($userId);
		$moveSums = $this->lineMapper->sumByAccount($userId, $since, $today);
		$cumStart = $this->lineMapper->sumByAccount($userId, null, $beforeSince);
		$cumEnd = $this->lineMapper->sumByAccount($userId, null, $today);

		// --- Kontostände (Geldkonten) ---
		$wealthRows = [];
		$wealthStart = 0;
		$wealthEnd = 0;
		foreach ($accounts as $account) {
			if (!$account->isStockAccount()) {
				continue;
			}
			$id = $account->getId();
			$start = ($cumStart[$id]['debit'] ?? 0) - ($cumStart[$id]['credit'] ?? 0);
			$end = ($cumEnd[$id]['debit'] ?? 0) - ($cumEnd[$id]['credit'] ?? 0);
			if ($start === 0 && $end === 0) {
				continue;
			}
			$wealthRows[] = [$account->getNumber(), $account->getName(), $start, $end];
			$wealthStart += $start;
			$wealthEnd += $end;
		}

		// --- Bewegungen seit dem Stichtag ---
		$income = [];
		$expense = [];
		$totalIncome = 0;
		$totalExpense = 0;
		foreach ($accounts as $account) {
			if (!$account->isResultRelevant()) {
				continue;
			}
			$id = $account->getId();
			$d = $moveSums[$id]['debit'] ?? 0;
			$c = $moveSums[$id]['credit'] ?? 0;
			if ($account->isCreditNature()) {
				$amount = $c - $d;
				if ($amount !== 0) {
					$income[] = [$account->getNumber(), $account->getName(), $amount];
					$totalIncome += $amount;
				}
			} else {
				$amount = $d - $c;
				if ($amount !== 0) {
					$expense[] = [$account->getNumber(), $account->getName(), $amount];
					$totalExpense += $amount;
				}
			}
		}
		$result = $totalIncome - $totalExpense;

		// --- Finanzplan des laufenden Jahres, nur die Summenzeile (kein Kurzbericht mehr, wenn hier die volle Tabelle steht) ---
		$currentYear = (int)date('Y');
		$plan = $this->budgetMapper->findByYear($userId, $currentYear);
		$planIncome = 0; $planExpense = 0; $actualIncome = 0; $actualExpense = 0;
		if ($plan !== []) {
			$yearMoveSums = $this->lineMapper->sumByAccount($userId, FiscalYear::start($currentYear), $today);
			foreach ($accounts as $account) {
				$type = $account->getType();
				if ($type !== 'income' && $type !== 'expense') {
					continue;
				}
				$id = $account->getId();
				$d = $yearMoveSums[$id]['debit'] ?? 0;
				$c = $yearMoveSums[$id]['credit'] ?? 0;
				$actualCents = $type === 'income' ? ($c - $d) : ($d - $c);
				$planCents = $plan[$id]['amount'] ?? 0;
				if ($type === 'income') {
					$planIncome += $planCents; $actualIncome += $actualCents;
				} else {
					$planExpense += $planCents; $actualExpense += $actualCents;
				}
			}
		}

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$brandColorRaw = $this->config->getAppValue(Application::APP_ID, 'brand_color', '');
		$brandColor = preg_match('/^#[0-9a-fA-F]{6}$/', $brandColorRaw) ? $brandColorRaw : '#2d7d46';
		$logoUrl = $this->branding->hasLogo() ? $this->urlGenerator->linkToRoute('vereinsbuchhaltung.branding.view') : null;
		$title = ($clubName !== '' ? $clubName . ' – ' : '') . 'Kurzbericht zur Vorstandssitzung';

		$m = fn (int $cents): string => $this->fmtMoney($cents / 100) . ' €';
		$h = '';

		$h .= '<div class="noprint">Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.</div>';
		$h .= '<header>';
		if ($logoUrl !== null) {
			$h .= '<img class="logo" src="' . $this->esc($logoUrl) . '" alt="Logo">';
		}
		if ($clubName !== '') {
			$h .= '<div class="club">' . $this->esc($clubName) . '</div>';
		}
		$h .= '<h1>Kurzbericht zur Vorstandssitzung</h1>';
		$h .= '<div class="meta">Zeitraum seit ' . $this->fmtDate($since) . ' · Erstellt am ' . $this->fmtDate($today) . '</div>';
		$h .= '</header>';

		$h .= '<section><h2>Kontostände (Geldkonten)</h2><table>';
		$h .= '<tr><th>Konto</th><th class="num">Bestand ' . $this->fmtDate($beforeSince) . '</th><th class="num">Bestand heute</th><th class="num">Veränderung</th></tr>';
		foreach ($wealthRows as [$nr, $name, $start, $end]) {
			$h .= '<tr><td>' . $this->esc(trim($nr . ' ' . $name)) . '</td><td class="num">' . $m($start) . '</td><td class="num">' . $m($end) . '</td><td class="num">' . $m($end - $start) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td>Gesamt</td><td class="num">' . $m($wealthStart) . '</td><td class="num">' . $m($wealthEnd) . '</td><td class="num">' . $m($wealthEnd - $wealthStart) . '</td></tr>';
		$h .= '</table></section>';

		$h .= '<section><h2>Bewegungen seit ' . $this->fmtDate($since) . '</h2><table>';
		$h .= '<tr><th colspan="2">Einnahmen</th><th class="num">Betrag</th></tr>';
		foreach ($income as [$nr, $name, $amount]) {
			$h .= '<tr><td class="nr">' . $this->esc((string)$nr) . '</td><td>' . $this->esc((string)$name) . '</td><td class="num">' . $m($amount) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td colspan="2">Summe Einnahmen</td><td class="num">' . $m($totalIncome) . '</td></tr>';
		$h .= '<tr><th colspan="2">Ausgaben</th><th class="num">Betrag</th></tr>';
		foreach ($expense as [$nr, $name, $amount]) {
			$h .= '<tr><td class="nr">' . $this->esc((string)$nr) . '</td><td>' . $this->esc((string)$name) . '</td><td class="num">' . $m($amount) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td colspan="2">Summe Ausgaben</td><td class="num">' . $m($totalExpense) . '</td></tr>';
		$h .= '<tr class="result"><td colspan="2">Ergebnis seit Stichtag</td><td class="num">' . $m($result) . '</td></tr>';
		$h .= '</table></section>';

		if ($plan !== []) {
			$h .= '<section><h2>Finanzplan ' . $currentYear . ' (Kurzfassung)</h2><table>';
			$h .= '<tr><th></th><th class="num">Plan</th><th class="num">Ist (bisher)</th></tr>';
			$h .= '<tr><td>Einnahmen</td><td class="num">' . $m($planIncome) . '</td><td class="num">' . $m($actualIncome) . '</td></tr>';
			$h .= '<tr><td>Ausgaben</td><td class="num">' . $m($planExpense) . '</td><td class="num">' . $m($actualExpense) . '</td></tr>';
			$h .= '<tr class="result"><td>Ergebnis</td><td class="num">' . $m($planIncome - $planExpense) . '</td><td class="num">' . $m($actualIncome - $actualExpense) . '</td></tr>';
			$h .= '</table></section>';
		}

		$css = '
			* { box-sizing: border-box; }
			body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #222; margin: 0 auto; padding: 24px; max-width: 210mm; font-size: 11pt; }
			header { border-bottom: 2px solid ' . $brandColor . '; margin-bottom: 18px; padding-bottom: 10px; }
			.logo { max-height: 48px; max-width: 240px; display: block; margin-bottom: 8px; }
			.club { font-size: 13pt; font-weight: 600; }
			h1 { font-size: 16pt; margin: 4px 0; color: ' . $brandColor . '; }
			h2 { font-size: 12pt; margin: 0 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
			.meta { color: #555; font-size: 9pt; }
			section { margin-bottom: 20px; page-break-inside: avoid; }
			table { width: 100%; border-collapse: collapse; }
			th, td { text-align: left; padding: 3px 6px; border-bottom: 1px solid #ddd; vertical-align: top; }
			th { border-bottom: 1px solid #999; padding-top: 8px; }
			td.nr, th.nr { width: 60px; color: #555; }
			.num { text-align: right; white-space: nowrap; }
			tr.sum td { font-weight: 600; border-top: 1px solid #999; }
			tr.result td { font-weight: 700; border-top: 2px solid ' . $brandColor . '; border-bottom: 2px solid ' . $brandColor . '; }
			.noprint { background: #fffbe6; border: 1px solid #e0d8a0; padding: 8px 12px; margin-bottom: 16px; font-size: 10pt; }
			@media print { .noprint { display: none; } body { padding: 0; } }
			@page { margin: 18mm 15mm; }
			@media (prefers-color-scheme: dark) {
				body { background: #1b1b1b; color: #ddd; }
				h2 { border-color: #444; }
				th, td { border-color: #3a3a3a; }
				th { border-color: #555; }
				tr.sum td { border-color: #555; }
				.meta { color: #aaa; }
				.noprint { background: #3a341a; border-color: #6b6130; color: #eee; }
			}
		';

		$html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<title>' . $this->esc($title) . '</title>'
			. '<style>' . $css . '</style></head><body>' . $h . '</body></html>';

		return $this->printableResponse($html, true);
	}
}
