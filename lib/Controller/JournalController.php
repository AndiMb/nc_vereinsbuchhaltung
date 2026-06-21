<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCP\AppFramework\Controller;
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
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return $this->userSession->getUser()->getUID();
	}

	#[NoAdminRequired]
	public function index(int $limit = 500, int $offset = 0): DataResponse {
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

		return new DataResponse([
			'accounts' => $rows,
			'totals' => [
				'income' => $income,
				'expense' => $expense,
				'result' => $income - $expense,
			],
		]);
	}
}
