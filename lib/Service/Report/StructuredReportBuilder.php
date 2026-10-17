<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Service\BudgetOverviewService;
use OCA\Vereinsbuchhaltung\Service\BudgetTree;

/**
 * Rechnet eine Gliederungsbericht-Vorlage zu einer Zeilenliste aus – ohne
 * Datenbank, wie {@see BudgetTree}. Bildschirm, Druck und CSV lesen dieselbe
 * Liste.
 *
 * Grundregeln (docs/gliederungsbericht-design.md, Abschnitt 4):
 *  - Jeder Abschnitt und jede Gruppe einer Gliederungsebene schränkt die
 *    Konten weiter ein (Kontoart, Sphäre, Berichtsgruppe).
 *  - Ob eine Summenzeile eine Summe oder ein Ergebnis (Einnahmen − Ausgaben)
 *    ist, folgt allein aus den Kontoarten, die an ihrer Stelle erlaubt sind –
 *    nicht aus den Daten.
 *  - Ein Konto mit Werten, das kein Abschnitt erfasst, landet in einem
 *    automatischen Abschnitt am Ende; die Gesamtzeile umfasst darum immer alle
 *    Einnahmen und Ausgaben und stimmt mit dem Finanzplan überein.
 *
 * Werte sind Cent; Einnahmen und Ausgaben sind beide positiv. Eine Spalte
 * ohne Periode hat durchgehend null.
 */
final class StructuredReportBuilder {

	public const FLAG_STRUCTURAL = 'structural';
	public const FLAG_INACTIVE = 'inactive';
	public const FLAG_DUPLICATE = 'duplicate';

	public const WARN_UNCOVERED = 'uncovered';
	public const WARN_DUPLICATE = 'duplicate';

	/** @var array<int, array<string, mixed>> */
	private array $accounts = [];
	/** @var array<int, ?int> Elternteil im Planbaum über alle Konten */
	private array $treeParent = [];
	/** @var list<?array<int, int>> */
	private array $columns = [];
	private ?int $planColumn = null;
	/** @var array<int, string> */
	private array $notes = [];
	private bool $showNotes = false;
	/** @var array<string, string> Berichtsgruppen in Anzeigereihenfolge: Schlüssel → Name */
	private array $groups = [];
	/** @var array<string, mixed> */
	private array $labels = [];
	/** @var array<int, int> wie oft ein Konto mit Werten gezählt wird */
	private array $coverage = [];

	/**
	 * @param array<string, mixed> $def normalisierte Definition
	 * @param array{
	 *     accounts: list<array{id:int, parentId:?int, type:string, number:string, name:string, sphere:string, group:string, active:bool}>,
	 *     columns: list<?array<int, int>>,
	 *     planColumn: ?int,
	 *     notes: array<int, string>,
	 *     groups: array<string, string>,
	 *     labels: array{types: array<string, string>, spheres: array<string, string>, uncovered: string, uncoveredFooter: string},
	 * } $input
	 * @return array{rows: list<array<string, mixed>>, warnings: list<array{code:string, accounts:list<string>}>}
	 */
	public static function rows(array $def, array $input): array {
		$b = new self();
		return $b->run($def, $input);
	}

	/**
	 * @param array<string, mixed> $def
	 * @param array<string, mixed> $input
	 * @return array{rows: list<array<string, mixed>>, warnings: list<array{code:string, accounts:list<string>}>}
	 */
	private function run(array $def, array $input): array {
		foreach ($input['accounts'] as $a) {
			$this->accounts[$a['id']] = $a;
		}
		$this->columns = $input['columns'];
		$this->planColumn = $input['planColumn'];
		$this->notes = $input['notes'];
		$this->showNotes = (bool)$def['showPlanNotes'];
		$this->groups = $input['groups'];
		$this->labels = $input['labels'];

		foreach (BudgetTree::build($input['accounts'], []) as $node) {
			$this->treeParent[$node['id']] = $node['treeParentId'];
		}

		$all = self::ctx();
		foreach ($def['sections'] as $section) {
			$this->count($section, $all);
		}

		$rows = [];
		foreach ($def['sections'] as $section) {
			array_push($rows, ...$this->section($section, $all, 0));
		}

		$warnings = [];
		$uncovered = [];
		$duplicate = [];
		foreach ($this->accounts as $id => $a) {
			if (!self::budgetable($a) || !$this->hasValue($id)) {
				continue;
			}
			$n = $this->coverage[$id] ?? 0;
			if ($n === 0) {
				$uncovered[] = $id;
			} elseif ($n > 1) {
				$duplicate[] = $a['number'];
			}
		}
		if ($uncovered !== []) {
			$auto = [
				'title' => $this->labels['uncovered'],
				'filter' => ['types' => null, 'spheres' => null, 'groups' => null],
				'summary' => false,
				'hideEmpty' => false,
				'sections' => null,
				'levels' => [['by' => 'account', 'footer' => null, 'hideEmpty' => true, 'depth' => 'all', 'ancestors' => false]],
				'footer' => ['label' => $this->labels['uncoveredFooter']],
			];
			array_push($rows, ...$this->section($auto, self::ctx(), 0, $uncovered));
			$warnings[] = ['code' => self::WARN_UNCOVERED, 'accounts' => $this->numbers($uncovered)];
		}
		if ($duplicate !== []) {
			sort($duplicate);
			$warnings[] = ['code' => self::WARN_DUPLICATE, 'accounts' => $duplicate];
		}

		if ($def['grandTotal'] !== null) {
			$rows[] = [
				'kind' => 'total',
				'depth' => 0,
				'label' => $def['grandTotal']['label'],
				'nature' => 'result',
				'values' => $this->values($this->match(self::ctx()), 'result'),
			];
		}

		return ['rows' => $rows, 'warnings' => $warnings];
	}

	// --- Abschnitte -------------------------------------------------------

	/**
	 * Zeilen eines Abschnitts samt Unterabschnitten.
	 *
	 * @param array<string, mixed> $section
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $parent
	 * @param list<int>|null $only feste Kontomenge (automatischer Abschnitt)
	 * @return list<array<string, mixed>>
	 */
	private function section(array $section, array $parent, int $depth, ?array $only = null): array {
		$ctx = self::narrow($parent, $section['filter']);
		$ids = $only ?? $this->match($ctx);
		if ($section['hideEmpty'] && !$this->anyValue($ids)) {
			return [];
		}

		$rows = [];
		$title = $section['title'];
		$inner = $title !== '' ? $depth + 1 : $depth;
		if ($title !== '') {
			$rows[] = ['kind' => 'heading', 'depth' => $depth, 'label' => $title];
		}
		if ($section['sections'] !== null) {
			foreach ($section['sections'] as $child) {
				array_push($rows, ...$this->section($child, $ctx, $inner));
			}
		} else {
			array_push($rows, ...$this->levels($section['levels'], 0, $ids, $ctx, $inner, (bool)$section['summary']));
		}
		if ($section['footer'] !== null) {
			$rows[] = $this->footer($section['footer']['label'], $ids, $ctx, $depth);
		}
		return $rows;
	}

	/**
	 * Gliederungsebene $i über die Konten $ids.
	 *
	 * @param list<array<string, mixed>> $levels
	 * @param list<int> $ids
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $ctx
	 * @return list<array<string, mixed>>
	 */
	private function levels(array $levels, int $i, array $ids, array $ctx, int $depth, bool $summary): array {
		$level = $levels[$i];
		if ($level['by'] === 'account') {
			return $this->accountRows($ids, $level, $depth, $summary);
		}

		$last = $i === count($levels) - 1;
		$rows = [];
		foreach ($this->partition($level['by'], $ids, $ctx) as [$key, $label, $subset]) {
			if ($level['hideEmpty'] && !$this->anyValue($subset)) {
				continue;
			}
			$sub = self::narrow($ctx, [self::dimension($level['by']) => [$key]]);
			if ($last) {
				$nature = self::nature($sub);
				$rows[] = [
					'kind' => 'line',
					'depth' => $depth,
					'label' => $label,
					'nature' => $nature,
					'values' => $this->values($subset, $nature),
				];
				continue;
			}
			$rows[] = ['kind' => 'heading', 'depth' => $depth, 'label' => $label];
			array_push($rows, ...$this->levels($levels, $i + 1, $subset, $sub, $depth + 1, $summary));
			if ($level['footer'] !== null) {
				$rows[] = $this->footer($level['footer']['label'], $subset, $sub, $depth);
			}
		}
		return $rows;
	}

	/**
	 * Konten als Baum, wie im Finanzplan aufgeklappt.
	 *
	 * Ohne `ancestors` hängt ein Konto am nächsten Vorfahren **innerhalb** der
	 * Menge, sonst wird es Wurzel. Mit `ancestors` kommen die Vorfahren
	 * außerhalb der Menge als Zwischenüberschriften dazu; sie tragen keine
	 * eigenen Werte, nur die Summe der Konten darunter.
	 *
	 * @param list<int> $ids
	 * @param array<string, mixed> $level
	 * @return list<array<string, mixed>>
	 */
	private function accountRows(array $ids, array $level, int $depth, bool $summary): array {
		$inSet = array_fill_keys($ids, true);
		$included = $inSet;
		if ($level['ancestors']) {
			foreach ($ids as $id) {
				for ($p = $this->treeParent[$id] ?? null; $p !== null && !isset($included[$p]); $p = $this->treeParent[$p] ?? null) {
					$included[$p] = true;
				}
			}
		}

		$described = [];
		foreach (array_keys($included) as $id) {
			$a = $this->accounts[$id];
			$parent = $this->treeParent[$id] ?? null;
			while ($parent !== null && !isset($included[$parent])) {
				$parent = $this->treeParent[$parent] ?? null;
			}
			$described[] = ['id' => $id, 'parentId' => $parent, 'type' => $a['type'], 'number' => $a['number']];
		}

		$cols = [];
		foreach ($this->columns as $c => $values) {
			$own = [];
			foreach ($ids as $id) {
				$own[$id] = $values === null ? 0 : ($values[$id] ?? 0);
			}
			$cols['c' . $c] = $own;
		}
		$planCol = $this->planColumn !== null ? 'c' . $this->planColumn : null;

		$nodes = BudgetTree::build($described, $cols);
		if ($planCol !== null) {
			$nodes = BudgetTree::annotate($nodes, $planCol);
		}
		if ($level['hideEmpty']) {
			$nodes = BudgetTree::prune($nodes, array_keys($cols));
		}
		$depthMode = $level['depth'] === 'plan' && $planCol === null ? 'all' : $level['depth'];
		$nodes = BudgetOverviewService::visibleAtDepth($nodes, $depthMode);

		$rows = [];
		foreach ($nodes as $n) {
			$id = $n['id'];
			$a = $this->accounts[$id];
			$flags = [];
			if (!isset($inSet[$id])) {
				$flags[] = self::FLAG_STRUCTURAL;
			}
			if (!$a['active']) {
				$flags[] = self::FLAG_INACTIVE;
			}
			if (!$summary && ($this->coverage[$id] ?? 0) > 1) {
				$flags[] = self::FLAG_DUPLICATE;
			}
			$values = [];
			foreach ($this->columns as $c => $columnValues) {
				$values[] = $columnValues === null ? null : $n['sum']['c' . $c];
			}
			$rows[] = [
				'kind' => 'account',
				'depth' => $depth + $n['depth'],
				'accountId' => $id,
				'number' => $a['number'],
				'label' => $a['name'],
				'nature' => $a['type'],
				'values' => $values,
				'flags' => $flags,
			];
			$note = $this->notes[$id] ?? '';
			if ($this->showNotes && $note !== '' && isset($inSet[$id])) {
				$rows[] = ['kind' => 'note', 'depth' => $depth + $n['depth'] + 1, 'label' => $note];
			}
		}
		return $rows;
	}

	/**
	 * @param list<int> $ids
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $ctx
	 * @return array<string, mixed>
	 */
	private function footer(string $label, array $ids, array $ctx, int $depth): array {
		$nature = self::nature($ctx);
		return [
			'kind' => 'footer',
			'depth' => $depth,
			'label' => $label,
			'nature' => $nature,
			'values' => $this->values($ids, $nature),
		];
	}

	// --- Mengen und Werte -------------------------------------------------

	/**
	 * Zählt, in wie vielen gliedernden Abschnitten ein Konto vorkommt.
	 * Zusammenfassungen zählen nicht – sie zeigen bewusst noch einmal alles.
	 *
	 * @param array<string, mixed> $section
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $parent
	 */
	private function count(array $section, array $parent): void {
		if ($section['summary']) {
			return;
		}
		$ctx = self::narrow($parent, $section['filter']);
		if ($section['sections'] !== null) {
			foreach ($section['sections'] as $child) {
				$this->count($child, $ctx);
			}
			return;
		}
		foreach ($this->match($ctx) as $id) {
			if ($this->hasValue($id)) {
				$this->coverage[$id] = ($this->coverage[$id] ?? 0) + 1;
			}
		}
	}

	/**
	 * Gruppen einer Gliederungsebene in Anzeigereihenfolge.
	 *
	 * Kontoart und Sphäre haben einen festen, kleinen Wertebereich: ihre
	 * Gruppen erscheinen auch ohne Konto, damit etwa „Einnahmen 0“ im Projekt
	 * sichtbar bleibt (sonst entscheidet `hideEmpty`). Berichtsgruppen
	 * erscheinen nur, wenn ihnen ein Konto angehört.
	 *
	 * @param list<int> $ids
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $ctx
	 * @return list<array{0:string, 1:string, 2:list<int>}>
	 */
	private function partition(string $by, array $ids, array $ctx): array {
		$field = $by === 'type' ? 'type' : ($by === 'sphere' ? 'sphere' : 'group');
		$buckets = [];
		foreach ($ids as $id) {
			$buckets[$this->accounts[$id][$field]][] = $id;
		}

		if ($by === 'group') {
			$out = [];
			foreach ($this->groups as $key => $label) {
				if (isset($buckets[$key])) {
					$out[] = [$key, $label, $buckets[$key]];
				}
			}
			return $out;
		}

		$domain = $by === 'type' ? ReportDefinition::TYPES : array_merge(Account::SPHERES, [ReportDefinition::SPHERE_NONE]);
		$allowed = $ctx[self::dimension($by)];
		$labels = $by === 'type' ? $this->labels['types'] : $this->labels['spheres'];
		$out = [];
		foreach ($domain as $key) {
			if ($allowed === null || in_array($key, $allowed, true)) {
				$out[] = [$key, $labels[$key] ?? $key, $buckets[$key] ?? []];
			}
		}
		return $out;
	}

	/**
	 * Einnahmen- oder Ausgabenkonten, die den Filter erfüllen.
	 *
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $ctx
	 * @return list<int>
	 */
	private function match(array $ctx): array {
		$out = [];
		foreach ($this->accounts as $id => $a) {
			if (!self::budgetable($a)
				|| ($ctx['types'] !== null && !in_array($a['type'], $ctx['types'], true))
				|| ($ctx['spheres'] !== null && !in_array($a['sphere'], $ctx['spheres'], true))
				|| ($ctx['groups'] !== null && !in_array($a['group'], $ctx['groups'], true))) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * Summe (eine Kontoart) oder Ergebnis (Einnahmen − Ausgaben) je Spalte.
	 *
	 * @param list<int> $ids
	 * @return list<?int>
	 */
	private function values(array $ids, string $nature): array {
		$out = [];
		foreach ($this->columns as $values) {
			if ($values === null) {
				$out[] = null;
				continue;
			}
			$sum = 0;
			foreach ($ids as $id) {
				$v = $values[$id] ?? 0;
				$sum += $nature === 'result' && $this->accounts[$id]['type'] === 'expense' ? -$v : $v;
			}
			$out[] = $sum;
		}
		return $out;
	}

	private function hasValue(int $id): bool {
		foreach ($this->columns as $values) {
			if ($values !== null && ($values[$id] ?? 0) !== 0) {
				return true;
			}
		}
		return false;
	}

	/** @param list<int> $ids */
	private function anyValue(array $ids): bool {
		foreach ($ids as $id) {
			if ($this->hasValue($id)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<int> $ids
	 * @return list<string>
	 */
	private function numbers(array $ids): array {
		$out = array_map(fn (int $id): string => $this->accounts[$id]['number'], $ids);
		sort($out);
		return $out;
	}

	// --- Filter -----------------------------------------------------------

	/** @return array{types:?list<string>, spheres:?list<string>, groups:?list<string>} */
	private static function ctx(): array {
		return ['types' => null, 'spheres' => null, 'groups' => null];
	}

	/**
	 * Schnittmenge zweier Filter; null heißt „keine Einschränkung".
	 *
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $ctx
	 * @param array<string, ?list<string>> $filter
	 * @return array{types:?list<string>, spheres:?list<string>, groups:?list<string>}
	 */
	private static function narrow(array $ctx, array $filter): array {
		foreach (['types', 'spheres', 'groups'] as $dim) {
			$add = $filter[$dim] ?? null;
			if ($add === null) {
				continue;
			}
			$ctx[$dim] = $ctx[$dim] === null ? array_values($add) : array_values(array_intersect($ctx[$dim], $add));
		}
		return $ctx;
	}

	private static function dimension(string $by): string {
		return match ($by) {
			'type' => 'types',
			'sphere' => 'spheres',
			default => 'groups',
		};
	}

	/**
	 * `income`/`expense`, wenn an dieser Stelle nur eine Kontoart erlaubt ist,
	 * sonst `result`.
	 *
	 * @param array{types:?list<string>, spheres:?list<string>, groups:?list<string>} $ctx
	 */
	private static function nature(array $ctx): string {
		if (ReportDefinition::isResult($ctx['types'])) {
			return 'result';
		}
		return $ctx['types'][0] ?? 'result';
	}

	/** @param array<string, mixed> $a */
	private static function budgetable(array $a): bool {
		return $a['type'] === 'income' || $a['type'] === 'expense';
	}
}
