<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class JournalController extends Controller {

	public function __construct(
		IRequest $request,
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountMapper $accountMapper,
		private BankTransactionMapper $txMapper,
		private BudgetMapper $budgetMapper,
		private JournalService $journalService,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
	}

	/**
	 * Datumsgrenzen für ein Geschäftsjahr (= Kalenderjahr) oder [null, null]
	 * für „alle Jahre".
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function yearRange(?int $year): array {
		if ($year === null || $year <= 0) {
			return [null, null];
		}
		return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
	}

	/** Geschäftsjahre mit Buchungen oder Planwerten (+ laufendes Jahr). */
	#[NoAdminRequired]
	public function years(): DataResponse {
		$userId = $this->userId();
		$all = array_merge(
			$this->journalMapper->distinctYears($userId),
			$this->budgetMapper->distinctYears($userId),
			[(int)date('Y')],
		);
		$all = array_values(array_unique($all));
		rsort($all);
		return new DataResponse($all);
	}

	#[NoAdminRequired]
	public function index(int $limit = 10000, int $offset = 0, ?int $year = null): DataResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$journals = $this->journalMapper->findAll($userId, $limit, $offset, $from, $to);
		// Zeilen aller Buchungen in einem Rutsch statt einer Abfrage je Buchung –
		// bei mehreren tausend Buchungen ist das der Unterschied zwischen zwei
		// und zweitausend Datenbankabfragen pro Aufruf.
		$linesByJournal = $this->lineMapper->findByJournals(array_map(
			static fn ($journal): int => $journal->getId(),
			$journals,
		));
		$out = [];
		foreach ($journals as $journal) {
			$out[] = [
				'journal' => $journal,
				'lines' => $linesByJournal[$journal->getId()] ?? [],
			];
		}
		return new DataResponse($out);
	}

	/**
	 * Saldenliste je Konto, gruppierbar nach Kategorie.
	 */
	#[NoAdminRequired]
	public function balances(?int $year = null): DataResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$accounts = $this->accountMapper->findAll($userId);

		// Bewegungssummen (Erfolgskonten = Jahr; ohne Jahr = alles).
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		// Bestandssummen (Bestandskonten = kumulativ bis Jahresende = Kontostand).
		$balSums = $from !== null ? $this->lineMapper->sumByAccount($userId, null, $to) : $moveSums;

		$isCreditNature = static fn (string $t): bool => in_array($t, ['income', 'liability', 'equity'], true);
		// Kumulativ (Kontostand) ausschließlich Geldkonten (Bank/Kasse, siehe
		// Account::isStockAccount()); alle anderen Konten – auch sonstige Aktiv-/
		// Passivkonten und Eigenkapital – werden jahresbezogen gezeigt.

		$rows = [];
		foreach ($accounts as $account) {
			$id = $account->getId();
			$type = $account->getType();
			// Bewegung im Zeitraum (Spalten Soll/Haben).
			$debit = $moveSums[$id]['debit'] ?? 0;
			$credit = $moveSums[$id]['credit'] ?? 0;
			// Saldo: Bestandskonten kumulativ (Kontostand), sonst = Bewegung.
			if ($account->isStockAccount()) {
				$bd = $balSums[$id]['debit'] ?? 0;
				$bc = $balSums[$id]['credit'] ?? 0;
				$balance = $isCreditNature($type) ? $bc - $bd : $bd - $bc;
			} else {
				$balance = $isCreditNature($type) ? $credit - $debit : $debit - $credit;
			}
			$rows[] = [
				'accountId' => $id,
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'type' => $type,
				'category' => $account->getCategory(),
				'debit' => $debit / 100,
				'credit' => $credit / 100,
				'balance' => $balance / 100,
			];
		}

		// Ergebnis: Einnahmen/Ausgaben aus den Bewegungen des Zeitraums.
		// Erfolgswirksam sind alle Nicht-Geldkonten außer Eigenkapital (siehe
		// Account::isResultRelevant()); die Seite ergibt sich aus der Kontonatur.
		// Damit gilt: Änderung des Vermögens (Bank/Kasse) = Ergebnis.
		$income = 0;
		$expense = 0;
		foreach ($accounts as $account) {
			if (!$account->isResultRelevant()) {
				continue;
			}
			$id = $account->getId();
			$d = $moveSums[$id]['debit'] ?? 0;
			$c = $moveSums[$id]['credit'] ?? 0;
			if ($account->isCreditNature()) {
				$income += ($c - $d);
			} else {
				$expense += ($d - $c);
			}
		}

		// --- Bank-Abstimmung ---------------------------------------------
		// Kontostand = kumulativer Saldo (inkl. Eröffnung) bis Jahresende.
		// "Offen" = Summe noch nicht zugeordneter CSV-Bankumsätze (nur Standard-Bankkonto).
		$openUnassigned = $this->txMapper->sumAmount($userId, 'unassigned');
		$defaultBankId = null;
		foreach ($accounts as $account) {
			if ($account->getIsBank()) {
				$defaultBankId = $account->getId();
				break;
			}
		}

		$bankReconciliation = [];
		foreach ($accounts as $account) {
			if (!$account->getIsBank()) {
				continue;
			}
			$id = $account->getId();
			$balance = ($balSums[$id]['debit'] ?? 0) - ($balSums[$id]['credit'] ?? 0);
			$open = ($id === $defaultBankId) ? $openUnassigned : 0;
			$bankReconciliation[] = [
				'accountId' => $id,
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'balance' => $balance / 100,
				'open' => $open / 100,
			];
		}

		return new DataResponse([
			'year' => $year,
			'accounts' => $rows,
			'totals' => [
				'income' => $income / 100,
				'expense' => $expense / 100,
				'result' => ($income - $expense) / 100,
			],
			'bankReconciliation' => $bankReconciliation,
		]);
	}

	/**
	 * Prüft die gemeinsamen Eingaben von {@see create()} und {@see update()}.
	 *
	 * Wichtig sind vor allem Datum und Kontoexistenz: ohne diese Prüfung landen
	 * Buchungszeilen mit einer account_id, zu der es kein Konto gibt (die Zeile
	 * taucht dann in keiner Auswertung mehr auf), bzw. Buchungen mit einem
	 * Datum, aus dem sich kein Geschäftsjahr ableiten lässt – dann greift auch
	 * die Festschreibungsprüfung nicht mehr.
	 *
	 * @return string|null Fehlermeldung oder null, wenn alles in Ordnung ist
	 */
	private function validateBooking(string $date, int $cents, int $debitAccountId, int $creditAccountId): ?string {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return 'Ungültiges Datum (erwartet wird JJJJ-MM-TT).';
		}
		[$y, $m, $d] = array_map('intval', explode('-', $date));
		if (!checkdate($m, $d, $y)) {
			return 'Dieses Datum gibt es nicht.';
		}
		if ($y < 2000 || $y > 2099) {
			return 'Das Buchungsdatum muss zwischen 2000 und 2099 liegen.';
		}
		if ($cents <= 0) {
			return 'Betrag muss größer als 0 sein';
		}
		if ($debitAccountId === $creditAccountId) {
			return 'Soll- und Habenkonto müssen unterschiedlich sein';
		}
		$userId = $this->userId();
		foreach (['Sollkonto' => $debitAccountId, 'Habenkonto' => $creditAccountId] as $label => $accountId) {
			try {
				$this->accountMapper->find($accountId, $userId);
			} catch (DoesNotExistException) {
				return $label . ' nicht gefunden.';
			}
		}
		return null;
	}

	/**
	 * Buchungssatz "Soll an Haben" anlegen.
	 */
	#[NoAdminRequired]
	public function create(string $date, string $description, int $debitAccountId, int $creditAccountId, float $amount, ?string $documentRef = null): DataResponse {
		$cents = (int)round($amount * 100);
		$error = $this->validateBooking($date, $cents, $debitAccountId, $creditAccountId);
		if ($error !== null) {
			return new DataResponse(['message' => $error], Http::STATUS_BAD_REQUEST);
		}
		$journal = $this->journalService->createBooking($this->userId(), $date, $description, $documentRef, $debitAccountId, $creditAccountId, $cents);
		return new DataResponse($journal, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, string $date, string $description, int $debitAccountId, int $creditAccountId, float $amount, ?string $documentRef = null, ?string $updatedAt = null): DataResponse {
		$cents = (int)round($amount * 100);
		$error = $this->validateBooking($date, $cents, $debitAccountId, $creditAccountId);
		if ($error !== null) {
			return new DataResponse(['message' => $error], Http::STATUS_BAD_REQUEST);
		}
		try {
			$journal = $this->journalService->updateBooking($id, $this->userId(), $date, $description, $documentRef, $debitAccountId, $creditAccountId, $cents, $updatedAt);
			return new DataResponse($journal);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung nicht gefunden'], Http::STATUS_NOT_FOUND);
		} catch (ConflictException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * Eine Seite einer Buchung auf ein anderes Konto umbuchen.
	 *
	 * Der Weg dorthin ist der Kontoauszug (Tab Konten): eine dort als falsch
	 * zugeordnet erkannte Buchung lässt sich an Ort und Stelle korrigieren,
	 * statt sie im Journal zu suchen und komplett neu zu erfassen.
	 */
	#[NoAdminRequired]
	public function reassign(int $id, int $fromAccountId, int $toAccountId, ?string $updatedAt = null): DataResponse {
		try {
			$journal = $this->journalService->reassignLine($id, $this->userId(), $fromAccountId, $toAccountId, $updatedAt);
			return new DataResponse($journal);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung oder Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
		} catch (ConflictException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->journalService->deleteBooking($id, $this->userId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Kontoauszug eines Kontos – optional inklusive aller Unterkonten.
	 */
	#[NoAdminRequired]
	public function byAccount(int $id, int $includeChildren = 1, ?int $year = null): DataResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$accounts = $this->accountMapper->findAll($userId);
		$labels = [];
		$childrenByParent = [];
		foreach ($accounts as $a) {
			$labels[$a->getId()] = $a->getNumber() . ' ' . $a->getName();
			$pid = $a->getParentId();
			if ($pid !== null) {
				$childrenByParent[$pid][] = $a->getId();
			}
		}

		$ids = [$id];
		if ($includeChildren) {
			$ids = $this->collectDescendants($id, $childrenByParent);
		}
		$idSet = array_flip($ids);

		// Saldovortrag je Zeile anhand des Zeilen-Kontos: nur Geldkonten tragen
		// einen Bestand über die Jahresgrenze. So verfälscht bei "inkl. Unterkonten"
		// weder ein jahresbezogenes Unterkonto den Vortrag des Überkontos, noch
		// verliert ein Geldkonto seinen Vortrag unter einem jahresbezogenen Parent.
		$stockIdSet = [];
		foreach ($accounts as $a) {
			if ($a->isStockAccount()) {
				$stockIdSet[$a->getId()] = true;
			}
		}

		$journalIds = $this->lineMapper->findJournalIdsForAccounts($userId, $ids);
		// Buchungsköpfe und -zeilen gebündelt laden statt je Buchung einzeln.
		$journalsById = $this->journalMapper->findByIds($userId, $journalIds);
		$linesByJournal = $this->lineMapper->findByJournals($journalIds);

		$rows = [];
		$sumDebit = 0;
		$sumCredit = 0;
		// Saldovortrag: Bewegung auf diesen Konten vor Beginn des Geschäftsjahres.
		$carryDebit = 0;
		$carryCredit = 0;
		foreach ($journalIds as $jid) {
			$journal = $journalsById[$jid] ?? null;
			if ($journal === null) {
				continue;
			}
			$date = (string)$journal->getDate();
			$beforePeriod = $from !== null && $date < $from;
			$afterPeriod = $to !== null && $date > $to;
			if ($afterPeriod) {
				continue;
			}
			$lines = $linesByJournal[$jid] ?? [];
			$contra = [];
			// Die Gegenkonten einzeln (nicht nur als Text): der Kontoauszug
			// bietet das Umbuchen für beide Seiten an, siehe reassign().
			$contraAccounts = [];
			foreach ($lines as $line) {
				if (!isset($idSet[$line->getAccountId()])) {
					$label = $labels[$line->getAccountId()] ?? ('#' . $line->getAccountId());
					$contra[] = $label;
					$contraAccounts[] = ['accountId' => $line->getAccountId(), 'label' => $label];
				}
			}
			foreach ($lines as $line) {
				if (!isset($idSet[$line->getAccountId()])) {
					continue;
				}
				if ($beforePeriod) {
					if (isset($stockIdSet[$line->getAccountId()])) {
						$carryDebit += $line->getDebitCents();
						$carryCredit += $line->getCreditCents();
					}
					continue;
				}
				$sumDebit += $line->getDebitCents();
				$sumCredit += $line->getCreditCents();
				$rows[] = [
					'journalId' => $jid,
					'entryNo' => $journal->getEntryNo(),
					'date' => $journal->getDate(),
					'description' => $journal->getDescription(),
					'documentRef' => $journal->getDocumentRef(),
					'accountId' => $line->getAccountId(),
					'account' => $labels[$line->getAccountId()] ?? ('#' . $line->getAccountId()),
					'contra' => implode(', ', $contra),
					'contraAccounts' => $contraAccounts,
					// Für das optimistische Locking beim Umbuchen (siehe reassign()).
					'updatedAt' => $journal->getUpdatedAt(),
					'debit' => $line->getDebitCents() / 100,
					'credit' => $line->getCreditCents() / 100,
				];
			}
		}

		usort($rows, static function ($a, $b) {
			return [$a['date'], $a['entryNo']] <=> [$b['date'], $b['entryNo']];
		});

		$account = $this->accountMapper->find($id, $userId);
		$isCreditNature = in_array($account->getType(), ['income', 'liability', 'equity'], true);

		// Saldovortrag nur bei aktivem Jahresfilter; beigetragen haben oben ohnehin
		// nur Zeilen von Geldkonten (debit-Natur), daher Soll − Haben.
		$carryCents = 0;
		if ($from !== null) {
			$carryCents = $carryDebit - $carryCredit;
		}
		$periodNet = $isCreditNature ? ($sumCredit - $sumDebit) : ($sumDebit - $sumCredit);
		$balanceCents = $carryCents + $periodNet;

		return new DataResponse([
			'account' => $account,
			'includeChildren' => (bool)$includeChildren,
			'year' => $year,
			'carry' => $carryCents / 100,
			'rows' => $rows,
			'totals' => [
				'debit' => $sumDebit / 100,
				'credit' => $sumCredit / 100,
				'balance' => $balanceCents / 100,
				'count' => count($rows),
			],
		]);
	}

	/**
	 * @param array<int, int[]> $childrenByParent
	 * @return int[] das Konto selbst plus alle Nachfahren
	 */
	private function collectDescendants(int $id, array $childrenByParent): array {
		$result = [$id];
		$stack = [$id];
		while ($stack) {
			$current = array_pop($stack);
			foreach ($childrenByParent[$current] ?? [] as $child) {
				$result[] = $child;
				$stack[] = $child;
			}
		}
		return array_values(array_unique($result));
	}
}
