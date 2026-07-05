<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
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
		// Wie JournalController::balances(): Eigenkapital jahresbezogen, nicht kumulativ.
		$isStock        = static fn (string $t): bool => in_array($t, ['asset', 'liability'], true);
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
			if ($isStock($type)) {
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

		foreach ($accounts as $account) {
			$id = $account->getId();
			$d  = $moveSums[$id]['debit'] ?? 0;
			$c  = $moveSums[$id]['credit'] ?? 0;
			if ($account->getType() === 'income') {
				$amount = $c - $d;
				if ($amount !== 0) {
					$income[]     = [$account->getNumber(), $account->getName(), (string)$account->getCategory(), $amount];
					$totalIncome += $amount;
				}
			} elseif ($account->getType() === 'expense') {
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
}
