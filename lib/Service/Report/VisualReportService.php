<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\BudgetTree;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
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
		private ClubClock $clock,
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

		$thresholds = BudgetStatus::DEFAULTS;
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
			'warnings' => $report['warnings'],
			'hasSpheres' => $report['hasSpheres'],
		];
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
