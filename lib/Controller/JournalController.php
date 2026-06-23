<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
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
		private JournalService $journalService,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
	}

	#[NoAdminRequired]
	public function index(int $limit = 10000, int $offset = 0): DataResponse {
		$userId = $this->userId();
		$journals = $this->journalMapper->findAll($userId, $limit, $offset);
		$out = [];
		foreach ($journals as $journal) {
			$lines = $this->lineMapper->findByJournal($journal->getId());
			$out[] = [
				'journal' => $journal,
				'lines' => $lines,
			];
		}
		return new DataResponse($out);
	}

	/**
	 * Saldenliste je Konto, gruppierbar nach Kategorie.
	 */
	#[NoAdminRequired]
	public function balances(): DataResponse {
		$userId = $this->userId();
		$sums = $this->lineMapper->sumByAccount($userId);
		$accounts = $this->accountMapper->findAll($userId);

		$rows = [];
		foreach ($accounts as $account) {
			$id = $account->getId();
			$debit = $sums[$id]['debit'] ?? 0;
			$credit = $sums[$id]['credit'] ?? 0;
			// Saldo nach Kontonatur
			$balance = match ($account->getType()) {
				'income', 'liability', 'equity' => $credit - $debit,
				default => $debit - $credit, // asset, expense
			};
			$rows[] = [
				'accountId' => $id,
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'type' => $account->getType(),
				'category' => $account->getCategory(),
				'debit' => $debit / 100,
				'credit' => $credit / 100,
				'balance' => $balance / 100,
			];
		}

		$income = array_sum(array_map(static fn ($r) => $r['type'] === 'income' ? $r['balance'] : 0, $rows));
		$expense = array_sum(array_map(static fn ($r) => $r['type'] === 'expense' ? $r['balance'] : 0, $rows));

		// --- Bank-Abstimmung ---------------------------------------------
		// Kontostand = Saldo aus dem Journal (inkl. Eröffnung + aller Buchungen).
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
			$balance = ($sums[$id]['debit'] ?? 0) - ($sums[$id]['credit'] ?? 0);
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
			'accounts' => $rows,
			'totals' => [
				'income' => $income,
				'expense' => $expense,
				'result' => $income - $expense,
			],
			'bankReconciliation' => $bankReconciliation,
		]);
	}

	/**
	 * Buchungssatz "Soll an Haben" anlegen.
	 */
	#[NoAdminRequired]
	public function create(string $date, string $description, int $debitAccountId, int $creditAccountId, float $amount, ?string $documentRef = null): DataResponse {
		$cents = (int)round($amount * 100);
		if ($cents <= 0) {
			return new DataResponse(['message' => 'Betrag muss größer als 0 sein'], Http::STATUS_BAD_REQUEST);
		}
		if ($debitAccountId === $creditAccountId) {
			return new DataResponse(['message' => 'Soll- und Habenkonto müssen unterschiedlich sein'], Http::STATUS_BAD_REQUEST);
		}
		$journal = $this->journalService->createBooking($this->userId(), $date, $description, $documentRef, $debitAccountId, $creditAccountId, $cents);
		return new DataResponse($journal, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, string $date, string $description, int $debitAccountId, int $creditAccountId, float $amount, ?string $documentRef = null): DataResponse {
		$cents = (int)round($amount * 100);
		if ($cents <= 0 || $debitAccountId === $creditAccountId) {
			return new DataResponse(['message' => 'Ungültige Buchung'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$journal = $this->journalService->updateBooking($id, $this->userId(), $date, $description, $documentRef, $debitAccountId, $creditAccountId, $cents);
			return new DataResponse($journal);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung nicht gefunden'], Http::STATUS_NOT_FOUND);
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
	public function byAccount(int $id, int $includeChildren = 1): DataResponse {
		$userId = $this->userId();
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

		$journalIds = $this->lineMapper->findJournalIdsForAccounts($userId, $ids);

		$rows = [];
		$sumDebit = 0;
		$sumCredit = 0;
		foreach ($journalIds as $jid) {
			try {
				$journal = $this->journalMapper->find($jid, $userId);
			} catch (DoesNotExistException) {
				continue;
			}
			$lines = $this->lineMapper->findByJournal($jid);
			$contra = [];
			foreach ($lines as $line) {
				if (!isset($idSet[$line->getAccountId()])) {
					$contra[] = $labels[$line->getAccountId()] ?? ('#' . $line->getAccountId());
				}
			}
			foreach ($lines as $line) {
				if (!isset($idSet[$line->getAccountId()])) {
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
					'account' => $labels[$line->getAccountId()] ?? ('#' . $line->getAccountId()),
					'contra' => implode(', ', $contra),
					'debit' => $line->getDebitCents() / 100,
					'credit' => $line->getCreditCents() / 100,
				];
			}
		}

		usort($rows, static function ($a, $b) {
			return [$a['date'], $a['entryNo']] <=> [$b['date'], $b['entryNo']];
		});

		$account = $this->accountMapper->find($id, $userId);
		$balanceCents = $sumDebit - $sumCredit;
		if (in_array($account->getType(), ['income', 'liability', 'equity'], true)) {
			$balanceCents = $sumCredit - $sumDebit;
		}

		return new DataResponse([
			'account' => $account,
			'includeChildren' => (bool)$includeChildren,
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
