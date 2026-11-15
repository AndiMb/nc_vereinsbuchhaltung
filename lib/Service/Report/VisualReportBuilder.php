<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

/**
 * Daten der visuellen Gliederungsansicht (Plan gegen Ist): Zeilen mit Ampel,
 * Kennzahlen, Ampelverteilung und größte Abweichungen.
 *
 * Alle Ampelentscheidungen laufen über {@see BudgetStatus::of()}. Beträge sind
 * Cent. Statisch und ohne Datenbank.
 */
final class VisualReportBuilder {

	private const BASES = ['year', 'time'];
	private const LEVELS = [BudgetStatus::GOOD, BudgetStatus::WARN, BudgetStatus::CRIT, BudgetStatus::UNPLANNED];
	private const VALUE_KINDS = ['line', 'account', 'footer', 'total'];
	private const MAX_DEVIATIONS = 7;
	private const MIN_DEVIATION = 50;

	/**
	 * @param list<array<string, mixed>> $rows Zeilen aus build() mit values = [plan, actual]
	 * @param list<array{id:int, type:string, number:string, name:string, plan:int, actual:int}> $planLevel
	 * @param array{income: array{plan:int, actual:int}, expense: array{plan:int, actual:int}} $totals
	 * @param array<string, int> $thresholds
	 * @param list<int> $coveredIds Konten ohne eigenen Plan, deren Plan auf einem Vorfahren liegt
	 * @param list<int> $parentIds Konten mit Unterkonten (ihre Zahlen schließen die Unterkonten ein)
	 * @param array<int, array{own:int, subtree:int}> $parentActual Ist je Oberkonto: nur das Konto
	 *                                                              und samt aller Unterkonten
	 * @return array{rows: list<array<string, mixed>>, kpis: array<string, mixed>,
	 *               distribution: array<string, mixed>, deviations: array<string, mixed>}
	 */
	public static function build(array $rows, array $planLevel, array $totals, int $elapsed, int $total, array $thresholds, array $coveredIds = [], array $parentIds = [], array $parentActual = []): array {
		// Fälliger Anteil des Plans als Bruch [Zähler, Nenner], damit
		// BudgetStatus ganzzahlig vergleichen kann.
		$factors = [
			'year' => [1, 1],
			'time' => [$elapsed, $total],
		];

		$parents = array_fill_keys($parentIds, true);
		return [
			'rows' => self::buildRows($rows, $factors, $thresholds, array_fill_keys($coveredIds, true), $parents, $parentActual),
			'kpis' => self::buildKpis($totals, $factors, $elapsed, $total, $thresholds),
			'distribution' => self::buildDistribution($planLevel, $factors, $thresholds),
			'deviations' => self::buildDeviations($planLevel, $factors, $thresholds, $parents),
		];
	}

	/**
	 * Verlauf des kumulierten Ist über die Monate: Monate ohne Buchung
	 * übernehmen den Wert des Vormonats.
	 *
	 * @param list<string> $months `YYYY-MM`, aufsteigend
	 * @param array<string, int> $incomeByMonth Einnahmen je Monat (nicht kumuliert)
	 * @param array<string, int> $expenseByMonth Ausgaben je Monat (nicht kumuliert)
	 * @return array{months: list<string>, income: list<int>, expense: list<int>}
	 */
	public static function series(array $months, array $incomeByMonth, array $expenseByMonth): array {
		$income = [];
		$expense = [];
		$incomeSum = 0;
		$expenseSum = 0;
		foreach ($months as $month) {
			$incomeSum += $incomeByMonth[$month] ?? 0;
			$expenseSum += $expenseByMonth[$month] ?? 0;
			$income[] = $incomeSum;
			$expense[] = $expenseSum;
		}
		return ['months' => array_values($months), 'income' => $income, 'expense' => $expense];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param array<string, array{int, int}> $factors
	 * @param array<string, int> $thresholds
	 * @param array<int, true> $covered Konto-Ids, deren Plan auf einem Vorfahren liegt
	 * @param array<int, true> $parents Konto-Ids mit Unterkonten
	 * @param array<int, array{own:int, subtree:int}> $parentActual
	 * @return list<array<string, mixed>>
	 */
	private static function buildRows(array $rows, array $factors, array $thresholds, array $covered, array $parents, array $parentActual): array {
		$out = [];
		foreach ($rows as $row) {
			$kind = $row['kind'] ?? '';
			if ($kind === 'note') {
				continue;
			}
			if (!in_array($kind, self::VALUE_KINDS, true)) {
				$out[] = $row;
				continue;
			}
			$values = is_array($row['values'] ?? null) ? $row['values'] : [];
			$plan = (int)($values[0] ?? 0);
			$actual = (int)($values[1] ?? 0);
			unset($row['values']);
			$row['plan'] = $plan;
			$row['actual'] = $actual;
			// Ein Unterkonto unter einem geplanten Oberkonto hat keinen eigenen
			// Plan, ist aber nicht „ungeplant“: seine Buchungen zählen dort mit.
			if ($kind === 'account' && $plan === 0 && isset($covered[(int)($row['accountId'] ?? 0)])) {
				$none = ['level' => BudgetStatus::NONE, 'ratio' => null];
				$row['status'] = array_fill_keys(self::BASES, $none);
				$row['flags'] = [...(is_array($row['flags'] ?? null) ? $row['flags'] : []), 'covered'];
			} else {
				$row['status'] = self::status((string)($row['nature'] ?? 'expense'), $plan, $actual, $factors, $thresholds);
			}
			if ($kind === 'account' && isset($parents[(int)($row['accountId'] ?? 0)])) {
				$flag = self::parentFlag($actual, $parentActual[(int)$row['accountId']] ?? null);
				if ($flag !== null) {
					$row['flags'] = [...(is_array($row['flags'] ?? null) ? $row['flags'] : []), $flag];
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Was zählt die Zeile eines Oberkontos? Danach richtet sich der Sprung in
	 * die Buchungsliste (Issue #326), denn die Liste soll sich zum Ist der
	 * Zeile summieren:
	 *
	 * - `parent`: das Konto samt allen Unterkonten – die Liste filtert ebenso.
	 * - kein Kennzeichen: nur das Konto selbst (seine Unterkonten stehen in
	 *   einem anderen Abschnitt der Vorlage) – die Liste filtert genau dieses Konto.
	 * - `mixed`: nur ein Teil der Unterkonten, oder die Zeile ist eine reine
	 *   Zwischenüberschrift – kein Filter der Liste trifft die Zahl, kein Sprung.
	 *
	 * @param array{own:int, subtree:int}|null $known null: nichts bekannt, wie bisher `parent`
	 */
	private static function parentFlag(int $actual, ?array $known): ?string {
		if ($known === null || $actual === $known['subtree']) {
			return 'parent';
		}
		return $actual === $known['own'] ? null : 'mixed';
	}

	/**
	 * @param array<string, array{int, int}> $factors
	 * @param array<string, int> $thresholds
	 * @return array<string, array{level:string, ratio:?float}>
	 */
	private static function status(string $nature, int $plan, int $actual, array $factors, array $thresholds): array {
		$status = [];
		foreach (self::BASES as $basis) {
			$status[$basis] = BudgetStatus::of($nature, $plan, $actual, $factors[$basis][0], $factors[$basis][1], $thresholds);
		}
		return $status;
	}

	/**
	 * @param array<string, array{plan:int, actual:int}> $totals
	 * @param array<string, array{int, int}> $factors
	 * @param array<string, int> $thresholds
	 * @return array<string, array<string, mixed>>
	 */
	private static function buildKpis(array $totals, array $factors, int $elapsed, int $total, array $thresholds): array {
		$income = ['plan' => (int)$totals['income']['plan'], 'actual' => (int)$totals['income']['actual']];
		$expense = ['plan' => (int)$totals['expense']['plan'], 'actual' => (int)$totals['expense']['actual']];
		$result = [
			'plan' => $income['plan'] - $expense['plan'],
			'actual' => $income['actual'] - $expense['actual'],
		];

		$kpis = [];
		foreach (['income' => $income, 'expense' => $expense, 'result' => $result] as $nature => $kpi) {
			$kpis[$nature] = [
				'plan' => $kpi['plan'],
				'actual' => $kpi['actual'],
				'forecast' => BudgetStatus::forecast($kpi['actual'], $elapsed, $total),
				'status' => self::status($nature, $kpi['plan'], $kpi['actual'], $factors, $thresholds),
			];
		}
		return $kpis;
	}

	/**
	 * Eindeutige Konten je Id (ein Konto kann mehrfach im Bericht stehen).
	 *
	 * @param list<array{id:int, type:string, number:string, name:string, plan:int, actual:int}> $planLevel
	 * @return list<array{id:int, type:string, number:string, name:string, plan:int, actual:int}>
	 */
	private static function uniqueAccounts(array $planLevel): array {
		$unique = [];
		foreach ($planLevel as $account) {
			$unique[$account['id']] = $account;
		}
		return array_values($unique);
	}

	/**
	 * @param list<array{id:int, type:string, number:string, name:string, plan:int, actual:int}> $planLevel
	 * @param array<string, array{int, int}> $factors
	 * @param array<string, int> $thresholds
	 * @return array<string, array<string, array<string, int>>>
	 */
	private static function buildDistribution(array $planLevel, array $factors, array $thresholds): array {
		$dist = [];
		foreach (self::BASES as $basis) {
			foreach (['income', 'expense'] as $nature) {
				$dist[$basis][$nature] = array_fill_keys(self::LEVELS, 0);
			}
		}
		foreach (self::uniqueAccounts($planLevel) as $account) {
			$nature = $account['type'];
			if ($nature !== 'income' && $nature !== 'expense') {
				continue;
			}
			foreach (self::BASES as $basis) {
				$level = BudgetStatus::of($nature, $account['plan'], $account['actual'], $factors[$basis][0], $factors[$basis][1], $thresholds)['level'];
				if (isset($dist[$basis][$nature][$level])) {
					$dist[$basis][$nature][$level]++;
				}
			}
		}
		return $dist;
	}

	/**
	 * @param list<array{id:int, type:string, number:string, name:string, plan:int, actual:int}> $planLevel
	 * @param array<string, array{int, int}> $factors
	 * @param array<string, int> $thresholds
	 * @param array<int, true> $parents Konto-Ids mit Unterkonten
	 * @return array<string, array<string, list<array<string, mixed>>>>
	 */
	private static function buildDeviations(array $planLevel, array $factors, array $thresholds, array $parents): array {
		$accounts = self::uniqueAccounts($planLevel);
		$dev = [];
		foreach (self::BASES as $basis) {
			foreach (['income', 'expense'] as $nature) {
				$dev[$basis][$nature] = [];
			}
			[$e, $t] = $factors[$basis];
			if ($e <= 0 || $t <= 0) {
				continue;
			}
			foreach ($accounts as $account) {
				$nature = $account['type'];
				if ($nature !== 'income' && $nature !== 'expense') {
					continue;
				}
				// Ein negativer Plan hat keinen Status (none): keine Abweichung.
				if ($account['plan'] < 0) {
					continue;
				}
				$reference = $account['plan'] * $e / $t;
				$amount = $nature === 'expense' ? $account['actual'] - $reference : $reference - $account['actual'];
				if ($amount <= self::MIN_DEVIATION) {
					continue;
				}
				$dev[$basis][$nature][] = [
					'accountId' => $account['id'],
					'number' => $account['number'],
					'label' => $account['name'],
					'amount' => (int)round($amount),
					'level' => BudgetStatus::of($nature, $account['plan'], $account['actual'], $e, $t, $thresholds)['level'],
					'parent' => isset($parents[$account['id']]),
				];
			}
			foreach (['income', 'expense'] as $nature) {
				usort($dev[$basis][$nature], static fn (array $a, array $b): int => [$b['amount'], $a['number']] <=> [$a['amount'], $b['number']]);
				$dev[$basis][$nature] = array_slice($dev[$basis][$nature], 0, self::MAX_DEVIATIONS);
			}
		}
		return $dev;
	}
}
