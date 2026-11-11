<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\BudgetStatusSettings;
use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\PeriodService;

/**
 * Die Daten der visuellen Gliederungsansicht: der Gliederungsbericht einer
 * Vorlage mit den festen Spalten Plan und Ist (bis zum gewählten Stand),
 * dazu Kennzahlen, Ampelverteilung und größte Abweichungen aus
 * {@see VisualReportBuilder}.
 */
class VisualReportService {

	private const COLUMNS = [
		['source' => 'plan', 'periodOffset' => 0],
		['source' => 'actual', 'periodOffset' => 0],
	];

	public function __construct(
		private StructuredReportService $structured,
		private PeriodService $periods,
		private JournalLineMapper $lines,
		private ClubClock $clock,
		private BudgetStatusSettings $statusSettings,
	) {
	}

	/**
	 * @param array{id:string, name:string, definition:array<string, mixed>} $template
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException bei ungültigem Stand
	 */
	public function build(string $userId, array $template, ?int $periodId, ?string $asOf): array {
		$period = $this->periods->selectedOrCurrent($userId, $periodId);
		$resolved = ReportAsOf::resolve($period->getStartDate(), $period->getEndDate(), $this->clock->today(), $asOf);

		$report = $this->structured->build($userId, $template, $periodId, self::COLUMNS, $resolved['until']);
		$data = $this->structured->planActual($userId, $period, $resolved['until']);
		[$planLevel, $totals, $coveredIds] = $this->planLevel($data['accounts'], $data['plan'], $data['actual']);

		$thresholds = $this->statusSettings->thresholds();
		$visual = VisualReportBuilder::build($report['rows'], $planLevel, $totals, $resolved['elapsed'], $resolved['total'], $thresholds, $coveredIds);

		return [
			'template' => $report['template'],
			'period' => $report['period'],
			'asOf' => $resolved,
			'thresholds' => $thresholds,
			'kpis' => $visual['kpis'],
			'distribution' => $visual['distribution'],
			'deviations' => $visual['deviations'],
			'rows' => $visual['rows'],
			'series' => $this->series($userId, $period->getStartDate(), $resolved, $data['accounts']),
			'warnings' => $report['warnings'],
			'hasSpheres' => $report['hasSpheres'],
		];
	}

	/**
	 * Monatsreihe des kumulierten Ist: vom ersten Monat der Periode bis zum
	 * Stand. Gerechnet bis zum selben Enddatum wie das Ist der Kennzahlen,
	 * sodass der letzte Wert mit `kpis.*.actual` übereinstimmt.
	 *
	 * @param array{until:string, elapsed:int, total:int} $resolved
	 * @param Account[] $accounts
	 * @return array{months: list<string>, income: list<int>, expense: list<int>}
	 */
	private function series(string $userId, string $periodStart, array $resolved, array $accounts): array {
		$budgetable = array_values(array_filter($accounts, static fn (Account $a): bool => $a->isBudgetable()));
		$income = [];
		$expense = [];
		$lastBooked = null;
		foreach ($this->lines->sumByAccountAndMonth($userId, $periodStart, $resolved['until']) as $month => $sums) {
			foreach ($budgetable as $account) {
				if (!isset($sums[(int)$account->getId()])) {
					continue;
				}
				$net = LedgerAggregator::net($account, $sums);
				if ($account->getType() === 'income') {
					$income[$month] = ($income[$month] ?? 0) + $net;
				} else {
					$expense[$month] = ($expense[$month] ?? 0) + $net;
				}
				if ($lastBooked === null || $month > $lastBooked) {
					$lastBooked = $month;
				}
			}
		}

		// Die Reihe deckt jeden gebuchten Monat ab (bei „Aktuell“ reicht das
		// Ist bis zum Periodenende), höchstens aber die Periode.
		$first = new \DateTimeImmutable(substr($periodStart, 0, 7) . '-01');
		$count = $resolved['elapsed'];
		if ($lastBooked !== null) {
			$last = new \DateTimeImmutable($lastBooked . '-01');
			$count = max($count, ((int)$last->format('Y') - (int)$first->format('Y')) * 12 + (int)$last->format('n') - (int)$first->format('n') + 1);
		}
		$count = min($count, $resolved['total']);
		$months = [];
		for ($i = 0; $i < $count; $i++) {
			$months[] = $first->modify('+' . $i . ' months')->format('Y-m');
		}
		return VisualReportBuilder::series($months, $income, $expense);
	}

	/**
	 * Konten der Planungsebene (sichtbare Knoten ohne sichtbares Kind) und die
	 * Gesamtsummen – wie die Soll-Ist-Ansicht sie aufbaut. Dazu die Konten,
	 * die selbst kein Planposten sind, deren Plan aber auf einem Vorfahren
	 * liegt (`coveredBy`): Sie bekommen im Bericht keine Ampel.
	 *
	 * @param Account[] $accounts
	 * @param array<int, int> $plan
	 * @param array<int, int> $actual
	 * @return array{0: list<array{id:int, type:string, number:string, name:string, plan:int, actual:int}>, 1: array{income: array{plan:int, actual:int}, expense: array{plan:int, actual:int}}, 2: list<int>}
	 */
	private function planLevel(array $accounts, array $plan, array $actual): array {
		$names = [];
		$planCents = [];
		$actualCents = [];
		foreach ($accounts as $account) {
			$id = (int)$account->getId();
			$names[$id] = (string)$account->getName();
			if (!$account->isBudgetable()) {
				continue;
			}
			$planCents[$id] = (int)($plan[$id] ?? 0);
			$actualCents[$id] = (int)($actual[$id] ?? 0);
		}

		$nodes = BudgetTree::annotate(
			BudgetTree::build(BudgetTree::describe($accounts), ['plan' => $planCents, 'actual' => $actualCents]),
			'plan',
			'actual',
		);
		$coveredIds = [];
		foreach ($nodes as $node) {
			if ($node['coveredBy'] !== null && !$node['isPlanItem']) {
				$coveredIds[] = (int)$node['id'];
			}
		}
		$planTotals = BudgetTree::totals($nodes, 'plan');
		$actualTotals = BudgetTree::totals($nodes, 'actual');

		$visible = BudgetOverviewService::visibleAtDepth($nodes, 'plan');
		$parents = [];
		foreach ($visible as $node) {
			if ($node['treeParentId'] !== null) {
				$parents[$node['treeParentId']] = true;
			}
		}
		$planLevel = [];
		foreach ($visible as $node) {
			if (isset($parents[$node['id']]) || ($node['type'] !== 'income' && $node['type'] !== 'expense')) {
				continue;
			}
			$planLevel[] = [
				'id' => $node['id'],
				'type' => $node['type'],
				'number' => $node['number'],
				'name' => $names[$node['id']] ?? '',
				'plan' => $node['sum']['plan'],
				'actual' => $node['sum']['actual'],
			];
		}

		return [$planLevel, [
			'income' => ['plan' => $planTotals['income'], 'actual' => $actualTotals['income']],
			'expense' => ['plan' => $planTotals['expense'], 'actual' => $actualTotals['expense']],
		], $coveredIds];
	}
}
