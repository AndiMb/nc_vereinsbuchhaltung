<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\IL10N;

/**
 * Der Gliederungsbericht einer Vorlage für eine Periode.
 *
 * Holt Konten, Plan- und Istwerte aller Perioden, die die Spalten brauchen,
 * ordnet jedes Konto seiner Berichtsgruppe zu und überlässt das Gliedern dem
 * {@see StructuredReportBuilder}. Bildschirm, Druck und CSV nutzen dieses
 * Ergebnis gemeinsam.
 */
class StructuredReportService {

	public function __construct(
		private AccountMapper $accountMapper,
		private BudgetMapper $budgetMapper,
		private JournalLineMapper $lineMapper,
		private PeriodService $periods,
		private ReportService $reports,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param array{id:string, name:string, builtin:bool, definition:array<string, mixed>} $template
	 * @return array{
	 *     template: array{id:string, name:string, builtin:bool},
	 *     period: Period,
	 *     columns: list<array{source:string, periodOffset:int, periodId:?int, label:string}>,
	 *     rows: list<array<string, mixed>>,
	 *     warnings: list<array{code:string, accounts:list<string>}>,
	 *     hasSpheres: bool,
	 * }
	 */
	public function build(string $userId, array $template, ?int $periodId): array {
		$def = $template['definition'];
		$period = $this->periods->selectedOrCurrent($userId, $periodId);
		$accounts = $this->accountMapper->findAll($userId);

		// Je Periode genau einmal laden, auch wenn mehrere Spalten sie nutzen.
		$byOffset = [];
		$plans = [];
		$actuals = [];
		$columns = [];
		$values = [];
		$planColumn = null;
		foreach ($def['columns'] as $i => $c) {
			$offset = $c['periodOffset'];
			if (!array_key_exists($offset, $byOffset)) {
				$byOffset[$offset] = $this->periodAt($userId, $period, $offset);
			}
			$p = $byOffset[$offset];
			$columns[] = [
				'source' => $c['source'],
				'periodOffset' => $offset,
				'periodId' => $p !== null ? (int)$p->getId() : null,
				'label' => $this->columnLabel($c['source'], $p),
			];
			if ($p === null) {
				$values[] = null;
				continue;
			}
			$pid = (int)$p->getId();
			$plans[$pid] ??= $this->planCents($userId, $pid);
			$actuals[$pid] ??= $this->actualCents($userId, $p, $accounts);
			$values[] = match ($c['source']) {
				'plan' => $plans[$pid],
				'actual' => $actuals[$pid],
				default => self::difference($actuals[$pid], $plans[$pid]),
			};
			if ($planColumn === null && $c['source'] === 'plan' && $offset === 0) {
				$planColumn = $i;
			}
		}

		$notes = [];
		if ($def['showPlanNotes']) {
			foreach ($this->budgetMapper->findByPeriod($userId, (int)$period->getId()) as $id => $entry) {
				if ($entry['note'] !== '') {
					$notes[(int)$id] = $entry['note'];
				}
			}
		}

		[$groupOf, $groups] = $this->groups($userId, $accounts, $def['groups']);
		$described = [];
		$hasSpheres = false;
		foreach ($accounts as $a) {
			$id = (int)$a->getId();
			$hasSpheres = $hasSpheres || ($a->isBudgetable() && $a->getSphere() !== null);
			$pid = $a->getParentId();
			$described[] = [
				'id' => $id,
				'parentId' => $pid !== null && $pid > 0 ? (int)$pid : null,
				'type' => (string)$a->getType(),
				'number' => (string)$a->getNumber(),
				'name' => (string)$a->getName(),
				'sphere' => $a->getSphere() ?? ReportDefinition::SPHERE_NONE,
				'group' => $groupOf[$id] ?? '',
				'active' => (bool)$a->getActive(),
			];
		}

		$result = StructuredReportBuilder::rows($def, [
			'accounts' => $described,
			'columns' => $values,
			'planColumn' => $planColumn,
			'notes' => $notes,
			'groups' => $groups,
			'labels' => [
				'types' => [
					'income' => $this->l10n->t('Einnahmen'),
					'expense' => $this->l10n->t('Ausgaben'),
				],
				'spheres' => [
					'ideell' => $this->l10n->t('Ideeller Bereich'),
					'vermoegensverwaltung' => $this->l10n->t('Vermögensverwaltung'),
					'zweckbetrieb' => $this->l10n->t('Zweckbetrieb'),
					'wirtschaftlich' => $this->l10n->t('Wirtschaftlicher Geschäftsbetrieb'),
					ReportDefinition::SPHERE_NONE => $this->l10n->t('Ohne Sphäre'),
				],
				'uncovered' => $this->l10n->t('Nicht erfasste Konten'),
				'uncoveredFooter' => $this->l10n->t('Ergebnis nicht erfasster Konten'),
			],
		]);

		return [
			'template' => ['id' => $template['id'], 'name' => $template['name'], 'builtin' => $template['builtin']],
			'period' => $period,
			'columns' => $columns,
			'rows' => $result['rows'],
			'warnings' => $result['warnings'],
			'hasSpheres' => $hasSpheres,
		];
	}

	/**
	 * Die Gruppen, die sich ohne Umgruppierung aus den Kostenstellen der
	 * Einnahmen- und Ausgabenkonten ergeben – für den Vorlagen-Editor.
	 *
	 * @return list<array{key:string, code:?string, label:string}>
	 */
	public function costCenterGroups(string $userId): array {
		$accounts = array_values(array_filter(
			$this->accountMapper->findAll($userId),
			static fn (Account $a): bool => $a->isBudgetable(),
		));
		[, $order] = $this->groups($userId, $accounts, []);
		$resolver = $this->reports->costCenterResolver($userId);
		$codes = [];
		foreach ($accounts as $a) {
			$cc = $resolver->resolve($a);
			$codes[ReportDefinition::GROUP_COST_CENTER . $cc['key']] = $cc['code'];
		}
		$out = [];
		foreach ($order as $key => $label) {
			$out[] = ['key' => $key, 'code' => $codes[$key] ?? null, 'label' => $label];
		}
		return $out;
	}

	/**
	 * Berichtsgruppe je Konto und alle Gruppen in Anzeigereihenfolge.
	 *
	 * Vorrang hat ein ausdrücklich zugeordnetes Konto, und zwar das nächste
	 * in der Kontohierarchie (ein Unterkonto kann so in eine andere Gruppe
	 * als sein Oberkonto). Dann zählt die Kostenstelle: entweder umgehängt in
	 * eine Gruppe der Vorlage oder als eigene Gruppe.
	 *
	 * @param Account[] $accounts
	 * @param list<array{key:string, label:string, costCenters:list<string>, accounts:list<int>}> $templateGroups
	 * @return array{0: array<int, string>, 1: array<string, string>}
	 */
	public function groups(string $userId, array $accounts, array $templateGroups): array {
		$resolver = $this->reports->costCenterResolver($userId);

		$byAccount = [];
		$byCostCenter = [];
		$order = [];
		foreach ($templateGroups as $g) {
			$key = ReportDefinition::GROUP_TEMPLATE . $g['key'];
			$order[$key] = $g['label'];
			foreach ($g['accounts'] as $id) {
				$byAccount[$id] = $key;
			}
			foreach ($g['costCenters'] as $code) {
				$byCostCenter[$code] = $key;
			}
		}

		$parent = [];
		foreach ($accounts as $a) {
			$pid = $a->getParentId();
			$parent[(int)$a->getId()] = $pid !== null && $pid > 0 ? (int)$pid : null;
		}

		$groupOf = [];
		$costCenterGroups = [];
		foreach ($accounts as $a) {
			$id = (int)$a->getId();
			$key = null;
			$seen = [];
			for ($p = $id; $p !== null && !isset($seen[$p]); $p = $parent[$p] ?? null) {
				$seen[$p] = true;
				if (isset($byAccount[$p])) {
					$key = $byAccount[$p];
					break;
				}
			}
			if ($key === null) {
				$cc = $resolver->resolve($a);
				$key = $cc['code'] !== null && isset($byCostCenter[$cc['code']])
					? $byCostCenter[$cc['code']]
					: ReportDefinition::GROUP_COST_CENTER . $cc['key'];
				if (!isset($order[$key])) {
					$costCenterGroups[$key] = $cc;
				}
			}
			$groupOf[$id] = $key;
		}

		uasort($costCenterGroups, static fn (array $x, array $y): int => strcmp($x['code'] ?? "\u{10FFFF}", $y['code'] ?? "\u{10FFFF}"));
		foreach ($costCenterGroups as $key => $cc) {
			$order[$key] = $cc['code'] !== null && $resolver->mode() !== 'account'
				? $cc['code'] . ' ' . $cc['name']
				: $cc['name'];
		}
		return [$groupOf, $order];
	}

	/** Die Periode $offset Schritte vor $period (0 = sie selbst), oder null. */
	private function periodAt(string $userId, Period $period, int $offset): ?Period {
		$p = $period;
		for ($i = 0; $i > $offset && $p !== null; $i--) {
			$p = $this->periods->previous($userId, $p);
		}
		return $p;
	}

	private function columnLabel(string $source, ?Period $p): string {
		if ($p === null) {
			return match ($source) {
				'plan' => $this->l10n->t('Plan (keine Periode)'),
				'actual' => $this->l10n->t('Ist (keine Periode)'),
				default => $this->l10n->t('Differenz (keine Periode)'),
			};
		}
		return match ($source) {
			'plan' => $this->l10n->t('Plan %s', [$p->getLabel()]),
			'actual' => $this->l10n->t('Ist %s', [$p->getLabel()]),
			default => $this->l10n->t('Ist − Plan %s', [$p->getLabel()]),
		};
	}

	/** @return array<int, int> */
	private function planCents(string $userId, int $periodId): array {
		$out = [];
		foreach ($this->budgetMapper->findByPeriod($userId, $periodId) as $id => $entry) {
			$out[(int)$id] = (int)$entry['amount'];
		}
		return $out;
	}

	/**
	 * @param Account[] $accounts
	 * @return array<int, int>
	 */
	private function actualCents(string $userId, Period $p, array $accounts): array {
		$sums = $this->lineMapper->sumByAccount($userId, $p->getStartDate(), $p->getEndDate());
		$out = [];
		foreach ($accounts as $a) {
			if ($a->isBudgetable()) {
				$out[(int)$a->getId()] = LedgerAggregator::net($a, $sums);
			}
		}
		return $out;
	}

	/**
	 * @param array<int, int> $actual
	 * @param array<int, int> $plan
	 * @return array<int, int>
	 */
	private static function difference(array $actual, array $plan): array {
		$out = [];
		foreach ($actual + $plan as $id => $_) {
			$out[$id] = ($actual[$id] ?? 0) - ($plan[$id] ?? 0);
		}
		return $out;
	}
}
