<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\Period;

/**
 * Der Soll-Ist-Vergleich eines Geschäftsjahres als Planbaum.
 *
 * Bildschirm, CSV-Export und Druckbericht zeigen dieselben Zahlen; deshalb
 * entstehen sie an einer Stelle. Die Rechenregeln selbst stehen in
 * {@see BudgetTree} und {@see LedgerAggregator}.
 */
class BudgetOverviewService {

	public function __construct(
		private AccountMapper $accountMapper,
		private BudgetMapper $budgetMapper,
		private JournalLineMapper $lineMapper,
		private PeriodService $periods,
	) {
	}

	/**
	 * Mit Vergleich (Issue #114) kommt die Spalte `compare` hinzu: je Konto das
	 * Ist oder der Plan der Vergleichsperiode, wie Plan und Ist über die
	 * Unterkonten summiert.
	 *
	 * @param ?int $compareId null = die Periode davor, 0 = kein Vergleich,
	 *                        sonst die ID der Vergleichsperiode
	 * @param string $basis {@see BudgetComparison::BASIS_ACTUAL} oder BASIS_PLAN
	 * @return array{
	 *     period: Period,
	 *     accounts: array<int, Account>,
	 *     nodes: list<array<string, mixed>>,
	 *     notes: array<int, string>,
	 *     totals: array{planIncome:int, actualIncome:int, planExpense:int, actualExpense:int, compareIncome:int, compareExpense:int},
	 *     conflictCount: int,
	 *     comparison: ?array{period: Period, basis: string},
	 * }
	 */
	public function build(string $userId, ?int $periodId, ?int $compareId = 0, string $basis = BudgetComparison::BASIS_ACTUAL): array {
		$period = $this->periods->selectedOrCurrent($userId, $periodId);
		$compare = $this->comparePeriod($userId, $period, $compareId);
		$basis = BudgetComparison::basis($basis);
		$accounts = $this->accountMapper->findAll($userId);
		$plan = $this->budgetMapper->findByPeriod($userId, (int)$period->getId());
		$sums = $this->lineMapper->sumByAccount($userId, $period->getStartDate(), $period->getEndDate());
		$comparePlan = $compare !== null && $basis === BudgetComparison::BASIS_PLAN
			? $this->budgetMapper->findByPeriod($userId, (int)$compare->getId()) : [];
		$compareSums = $compare !== null && $basis === BudgetComparison::BASIS_ACTUAL
			? $this->lineMapper->sumByAccount($userId, $compare->getStartDate(), $compare->getEndDate()) : [];

		$byId = [];
		$planCents = [];
		$actualCents = [];
		$compareCents = [];
		$notes = [];
		foreach ($accounts as $account) {
			$id = (int)$account->getId();
			$byId[$id] = $account;
			if (!$account->isBudgetable()) {
				continue;
			}
			$planCents[$id] = (int)($plan[$id]['amount'] ?? 0);
			$actualCents[$id] = LedgerAggregator::net($account, $sums);
			$notes[$id] = (string)($plan[$id]['note'] ?? '');
			$compareCents[$id] = $basis === BudgetComparison::BASIS_PLAN
				? (int)($comparePlan[$id]['amount'] ?? 0)
				: LedgerAggregator::net($account, $compareSums);
		}

		$columns = ['plan' => $planCents, 'actual' => $actualCents];
		if ($compare !== null) {
			$columns['compare'] = $compareCents;
		}
		$nodes = BudgetTree::annotate(
			BudgetTree::build(BudgetTree::describe($accounts), $columns),
			'plan',
			'actual',
		);
		$planTotals = BudgetTree::totals($nodes, 'plan');
		$actualTotals = BudgetTree::totals($nodes, 'actual');
		$compareTotals = $compare !== null ? BudgetTree::totals($nodes, 'compare') : ['income' => 0, 'expense' => 0];

		$conflicts = 0;
		foreach ($nodes as $n) {
			if (in_array(BudgetTree::FLAG_CONFLICT, $n['flags'], true)) {
				$conflicts++;
			}
		}

		return [
			'period' => $period,
			'accounts' => $byId,
			'nodes' => $nodes,
			'notes' => $notes,
			'totals' => [
				'planIncome' => $planTotals['income'],
				'actualIncome' => $actualTotals['income'],
				'planExpense' => $planTotals['expense'],
				'actualExpense' => $actualTotals['expense'],
				'compareIncome' => $compareTotals['income'],
				'compareExpense' => $compareTotals['expense'],
			],
			'conflictCount' => $conflicts,
			'comparison' => $compare === null ? null : ['period' => $compare, 'basis' => $basis],
		];
	}

	/**
	 * Die Vergleichsperiode – oder null, wenn nicht verglichen wird. Die
	 * gewählte Periode selbst ergibt keinen Vergleich.
	 *
	 * @throws \OCA\Vereinsbuchhaltung\Exception\PeriodNotFoundException bei unbekannter ID
	 */
	private function comparePeriod(string $userId, Period $period, ?int $compareId): ?Period {
		if ($compareId === null) {
			return $this->periods->previous($userId, $period);
		}
		if ($compareId <= 0 || $compareId === (int)$period->getId()) {
			return null;
		}
		return $this->periods->find($userId, $compareId);
	}

	/**
	 * Konto, dessen Plan eine Planeingabe ≠ 0 auf $accountId im Zeitraum
	 * sperrt – oder null.
	 */
	public function blockingPlan(string $userId, int $periodId, int $accountId): ?Account {
		$accounts = $this->accountMapper->findAll($userId);
		$plan = [];
		foreach ($this->budgetMapper->findByPeriod($userId, $periodId) as $id => $entry) {
			$plan[(int)$id] = (int)$entry['amount'];
		}
		$blocking = BudgetTree::blockingPlan(BudgetTree::describe($accounts), $plan, $accountId);
		if ($blocking === null) {
			return null;
		}
		foreach ($accounts as $account) {
			if ((int)$account->getId() === $blocking) {
				return $account;
			}
		}
		return null;
	}

	/**
	 * Knoten, die ein Bericht mit der gewählten Tiefe zeigt.
	 *
	 * `plan`: wie die Startansicht – bis zur Planungsebene aufgeklappt.
	 * Zahl n: alle Knoten bis Tiefe n. `all`: alles.
	 *
	 * @param list<array<string, mixed>> $nodes
	 * @return list<array<string, mixed>>
	 */
	public static function visibleAtDepth(array $nodes, string $depth): array {
		if ($depth === 'all') {
			return $nodes;
		}
		if ($depth !== 'plan' && ctype_digit($depth)) {
			$max = (int)$depth;
			return array_values(array_filter($nodes, static fn (array $n): bool => $n['depth'] <= $max));
		}
		$open = [];
		$out = [];
		foreach ($nodes as $n) {
			$pid = $n['treeParentId'];
			if ($pid === null || ($open[$pid] ?? false)) {
				$out[] = $n;
				$open[$n['id']] = (bool)$n['expanded'];
			}
		}
		return $out;
	}
}
