<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class BudgetController extends Controller {

	public function __construct(
		IRequest $request,
		private AccountMapper $accountMapper,
		private BudgetMapper $budgetMapper,
		private JournalLineMapper $lineMapper,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
	}

	/**
	 * Finanzplan eines Jahres: je Erfolgskonto Plan (Soll) und Ist sowie Differenz.
	 */
	#[NoAdminRequired]
	public function index(?int $year = null): DataResponse {
		$year = ($year === null || $year <= 0) ? (int)date('Y') : $year;
		$userId = $this->userId();
		$from = sprintf('%04d-01-01', $year);
		$to = sprintf('%04d-12-31', $year);

		$accounts = $this->accountMapper->findAll($userId);
		$plan = $this->budgetMapper->findByYear($userId, $year);
		$actualSums = $this->lineMapper->sumByAccount($userId, $from, $to);

		$rows = [];
		$totals = [
			'planIncome' => 0, 'actualIncome' => 0,
			'planExpense' => 0, 'actualExpense' => 0,
		];
		foreach ($accounts as $account) {
			$type = $account->getType();
			if ($type !== 'income' && $type !== 'expense') {
				continue;
			}
			$id = $account->getId();
			$debit = $actualSums[$id]['debit'] ?? 0;
			$credit = $actualSums[$id]['credit'] ?? 0;
			$actualCents = $type === 'income' ? ($credit - $debit) : ($debit - $credit);
			$planCents = $plan[$id] ?? 0;
			$rows[] = [
				'accountId' => $id,
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'type' => $type,
				'category' => $account->getCategory(),
				'plan' => $planCents / 100,
				'actual' => $actualCents / 100,
				'diff' => ($actualCents - $planCents) / 100,
			];
			if ($type === 'income') {
				$totals['planIncome'] += $planCents;
				$totals['actualIncome'] += $actualCents;
			} else {
				$totals['planExpense'] += $planCents;
				$totals['actualExpense'] += $actualCents;
			}
		}

		usort($rows, static fn ($a, $b) => strcmp((string)$a['number'], (string)$b['number']));

		return new DataResponse([
			'year' => $year,
			'rows' => $rows,
			'totals' => [
				'planIncome' => $totals['planIncome'] / 100,
				'actualIncome' => $totals['actualIncome'] / 100,
				'planExpense' => $totals['planExpense'] / 100,
				'actualExpense' => $totals['actualExpense'] / 100,
				'planResult' => ($totals['planIncome'] - $totals['planExpense']) / 100,
				'actualResult' => ($totals['actualIncome'] - $totals['actualExpense']) / 100,
			],
		]);
	}

	/**
	 * Planwert eines Kontos für ein Jahr setzen (Betrag in Euro; 0 entfernt ihn).
	 */
	#[NoAdminRequired]
	public function set(int $accountId, int $year, float $amount = 0): DataResponse {
		$cents = (int)round($amount * 100);
		$this->budgetMapper->upsert($this->userId(), $accountId, $year, $cents);
		return new DataResponse(['accountId' => $accountId, 'year' => $year, 'amount' => $cents / 100]);
	}
}
