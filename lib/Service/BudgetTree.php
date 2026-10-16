<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Der Finanzplan als Baum: Plan und Ist über die Kontenhierarchie summiert.
 *
 * Planzahlen stehen oft auf einem Konto („544 01 Mitgliedsbeiträge“), gebucht
 * wird aber auf dessen Unterkonten („544 01 02 GEMA“). Flach verglichen stand
 * dann 50.000 € Plan gegen 0 € Ist auf dem einen und 0 € Plan gegen 62.070 €
 * Ist auf den anderen Konten – eine Überschreitung war so nicht zu erkennen.
 * Hier wird jeder Wert zusätzlich über alle Nachfahren aufsummiert (Σ).
 *
 * Im Planbaum hängt ein Konto am nächsten Vorfahren, der selbst budgetierbar
 * und vom selben Typ ist. Fremde Zwischenkonten werden übersprungen; ein
 * Ausgabenkonto unter einem Einnahmenkonto wird eigene Wurzel, statt dessen
 * Summe zu verfälschen.
 *
 * Für jeden Zweig gilt eine Planungsebene: Ein Konto mit Plan ≠ 0 sperrt die
 * Planeingabe auf allen Vor- und Nachfahren ({@see blockingPlan()}). Ältere
 * Daten können das noch verletzen; sie werden als Konflikt markiert, gezählt
 * wird dann additiv.
 *
 * Wie {@see LedgerAggregator} statisch und ohne Datenbank. Die Konten kommen
 * als schlichte Beschreibungen herein ({@see describe()}), damit die Regeln
 * ohne Nextcloud-Entity prüfbar bleiben. Alle Beträge sind Cent.
 *
 * @psalm-type AccountInfo = array{id:int, parentId:?int, type:string, number:string}
 */
final class BudgetTree {

	public const FLAG_CONFLICT = 'conflict';
	public const FLAG_UNPLANNED = 'unplanned';
	public const FLAG_UNPLANNED_BELOW = 'unplannedBelow';
	public const FLAG_OVERRUN = 'overrun';

	/**
	 * Konten in die Form bringen, die der Baum braucht.
	 *
	 * @param iterable<\OCA\Vereinsbuchhaltung\Db\Account> $accounts
	 * @return list<array{id:int, parentId:?int, type:string, number:string}>
	 */
	public static function describe(iterable $accounts): array {
		$out = [];
		foreach ($accounts as $a) {
			$pid = $a->getParentId();
			$out[] = [
				'id' => (int)$a->getId(),
				'parentId' => $pid !== null && $pid > 0 ? (int)$pid : null,
				'type' => (string)$a->getType(),
				'number' => (string)$a->getNumber(),
			];
		}
		return $out;
	}

	/**
	 * Baut den Planbaum und summiert jede Wertspalte über die Nachfahren.
	 *
	 * Ergebnis in Vorordnung (Eltern vor Kindern, Geschwister nach Nummer).
	 * `below[col]` sagt, ob ein Nachfahr in dieser Spalte einen eigenen Wert
	 * ≠ 0 hat.
	 *
	 * @param list<array{id:int, parentId:?int, type:string, number:string}> $accounts
	 * @param array<string, array<int, int>> $columns Cent je Konto-ID, je Spalte
	 * @return list<array<string, mixed>>
	 */
	public static function build(array $accounts, array $columns): array {
		$byId = [];
		foreach ($accounts as $a) {
			$byId[$a['id']] = $a;
		}

		$children = [];
		$roots = [];
		foreach ($accounts as $a) {
			if (!self::budgetable($a['type'])) {
				continue;
			}
			$parent = self::treeParent($a, $byId);
			if ($parent === null) {
				$roots[] = $a;
			} else {
				$children[$parent][] = $a;
			}
		}

		$byNumber = static fn (array $x, array $y): int => strcmp($x['number'], $y['number']);
		usort($roots, $byNumber);
		foreach ($children as &$list) {
			usort($list, $byNumber);
		}
		unset($list);

		// Ein Zyklus in Altdaten hätte keine Wurzel; seine Konten kommen dann
		// als eigene Wurzeln hinterher, statt still zu verschwinden.
		$budgetable = array_values(array_filter($accounts, static fn (array $a): bool => self::budgetable($a['type'])));
		usort($budgetable, $byNumber);
		$starts = array_merge($roots, $budgetable);

		$nodes = [];
		$seen = [];
		foreach ($starts as $start) {
			if (isset($seen[$start['id']])) {
				continue;
			}
			self::walk($start, $children, $columns, $seen, $nodes);
		}
		// Rückwärts durch die Vorordnung: jedes Kind ist fertig, bevor sein
		// Elternteil an der Reihe ist.
		$index = [];
		foreach ($nodes as $i => $n) {
			$index[$n['id']] = $i;
		}
		for ($i = count($nodes) - 1; $i >= 0; $i--) {
			$pid = $nodes[$i]['treeParentId'];
			if ($pid === null) {
				continue;
			}
			$p = $index[$pid];
			foreach ($nodes[$i]['sum'] as $col => $value) {
				$nodes[$p]['sum'][$col] += $value;
				if ($nodes[$i]['own'][$col] !== 0 || $nodes[$i]['below'][$col]) {
					$nodes[$p]['below'][$col] = true;
				}
			}
		}

		return $nodes;
	}

	/**
	 * Ergänzt die Soll-Ist-Auswertung: Planposten, Sperren, Auffälligkeiten
	 * und den Startzustand der Ansicht.
	 *
	 * - `isPlanItem`: eigener Plan ≠ 0 (0 oder leer zählt nicht als Plan)
	 * - `coveredBy`: nächster Vorfahr, der Planposten ist
	 * - `firstPlanBelow`: erster Planposten unter dem Konto (Vorordnung)
	 * - `planLockedBy`: Konto, das die Planeingabe hier sperrt – nur für
	 *   Konten, die nicht selbst Planposten sind (die bleiben bearbeitbar,
	 *   damit sich Altkonflikte bereinigen lassen)
	 * - `comparable`: eine Differenz Plan/Ist ist aussagekräftig. Nicht bei
	 *   einem Unterkonto, dessen Plan auf einem Vorfahren liegt – es hat
	 *   keinen eigenen Plan, seine Buchungen zählen dort mit
	 * - `expanded`: aufgeklappt, wenn darunter noch geplant wird – so öffnet
	 *   sich jeder Zweig genau bis zur Planungsebene
	 * - `flags`: Teilmenge von conflict, unplanned, unplannedBelow, overrun
	 *
	 * Ohne Ist-Spalte (Plan-Stände) entfallen die Ist-Flags.
	 *
	 * @param list<array<string, mixed>> $nodes aus {@see build()}
	 * @return list<array<string, mixed>>
	 */
	public static function annotate(array $nodes, string $planCol, ?string $actualCol = null): array {
		$index = [];
		foreach ($nodes as $i => $n) {
			$index[$n['id']] = $i;
		}

		// Vorwärts: Eltern sind vor ihren Kindern fertig.
		foreach ($nodes as $i => $n) {
			$nodes[$i]['isPlanItem'] = $n['own'][$planCol] !== 0;
			$covered = null;
			$pid = $n['treeParentId'];
			if ($pid !== null) {
				$parent = $nodes[$index[$pid]];
				$covered = $parent['isPlanItem'] ? $parent['id'] : $parent['coveredBy'];
			}
			$nodes[$i]['coveredBy'] = $covered;
			$nodes[$i]['firstPlanBelow'] = null;
			$nodes[$i]['unplanned'] = $actualCol !== null
				&& $n['own'][$actualCol] !== 0
				&& !$nodes[$i]['isPlanItem']
				&& $covered === null;
			$nodes[$i]['unplannedBelow'] = false;
		}

		// Rückwärts: Kinder sind vor ihren Eltern fertig. Weil die Kinder
		// eines Knotens in umgekehrter Reihenfolge ankommen, gewinnt beim
		// Überschreiben der in Vorordnung erste Planposten.
		for ($i = count($nodes) - 1; $i >= 0; $i--) {
			$pid = $nodes[$i]['treeParentId'];
			if ($pid === null) {
				continue;
			}
			$p = $index[$pid];
			$candidate = $nodes[$i]['isPlanItem'] ? $nodes[$i]['id'] : $nodes[$i]['firstPlanBelow'];
			if ($candidate !== null) {
				$nodes[$p]['firstPlanBelow'] = $candidate;
			}
			if ($nodes[$i]['unplanned'] || $nodes[$i]['unplannedBelow']) {
				$nodes[$p]['unplannedBelow'] = true;
			}
		}

		foreach ($nodes as $i => $n) {
			$planBelow = $n['firstPlanBelow'] !== null;
			$flags = [];
			if ($n['isPlanItem'] && ($n['coveredBy'] !== null || $planBelow)) {
				$flags[] = self::FLAG_CONFLICT;
			}
			if ($n['unplanned']) {
				$flags[] = self::FLAG_UNPLANNED;
			}
			if ($n['unplannedBelow']) {
				$flags[] = self::FLAG_UNPLANNED_BELOW;
			}
			// Nur Ausgaben: Mindereinnahmen sind unterjährig der Normalfall.
			if ($actualCol !== null && $n['isPlanItem'] && $n['type'] === 'expense'
				&& $n['sum'][$actualCol] > $n['sum'][$planCol]) {
				$flags[] = self::FLAG_OVERRUN;
			}
			$nodes[$i]['flags'] = $flags;
			$nodes[$i]['comparable'] = $n['coveredBy'] === null || $n['isPlanItem'] || $planBelow;
			$nodes[$i]['expanded'] = $planBelow;
			$nodes[$i]['planLockedBy'] = $n['isPlanItem'] ? null : ($n['coveredBy'] ?? $n['firstPlanBelow']);
		}

		return $nodes;
	}

	/**
	 * Konto, dessen Plan eine Planeingabe ≠ 0 auf $accountId sperrt – oder
	 * null, wenn im Zweig sonst nirgends geplant ist.
	 *
	 * @param list<array{id:int, parentId:?int, type:string, number:string}> $accounts
	 * @param array<int, int> $plan Cent je Konto-ID
	 */
	public static function blockingPlan(array $accounts, array $plan, int $accountId): ?int {
		// Der eigene Wert spielt für die Frage keine Rolle.
		unset($plan[$accountId]);
		foreach (self::annotate(self::build($accounts, ['plan' => $plan]), 'plan') as $node) {
			if ($node['id'] === $accountId) {
				return $node['planLockedBy'];
			}
		}
		return null;
	}

	/**
	 * Summe einer Spalte über die Wurzeln, je Typ. Entspricht der Summe aller
	 * eigenen Werte – die Gesamtsummen ändern sich durch den Baum nicht.
	 *
	 * @param list<array<string, mixed>> $nodes
	 * @return array{income:int, expense:int}
	 */
	public static function totals(array $nodes, string $col): array {
		$out = ['income' => 0, 'expense' => 0];
		foreach ($nodes as $n) {
			if ($n['treeParentId'] === null) {
				$out[$n['type']] += $n['sum'][$col];
			}
		}
		return $out;
	}

	/**
	 * Knoten entfernen, die in allen genannten Spalten Σ = 0 haben. Weil Σ
	 * die Nachfahren einschließt, fällt ein Zweig immer ganz weg.
	 *
	 * @param list<array<string, mixed>> $nodes
	 * @param list<string> $cols
	 * @return list<array<string, mixed>>
	 */
	public static function prune(array $nodes, array $cols): array {
		$kept = array_values(array_filter($nodes, static function (array $n) use ($cols): bool {
			foreach ($cols as $col) {
				if ($n['sum'][$col] !== 0) {
					return true;
				}
			}
			return false;
		}));
		$hasKids = [];
		foreach ($kept as $n) {
			if ($n['treeParentId'] !== null) {
				$hasKids[$n['treeParentId']] = true;
			}
		}
		foreach ($kept as $i => $n) {
			$kept[$i]['hasChildren'] = isset($hasKids[$n['id']]);
		}
		return $kept;
	}

	/**
	 * Tiefensuche ab einer Wurzel; hängt die Knoten in Vorordnung an $nodes an.
	 *
	 * @param array{id:int, parentId:?int, type:string, number:string} $root
	 * @param array<int, list<array{id:int, parentId:?int, type:string, number:string}>> $children
	 * @param array<string, array<int, int>> $columns
	 * @param array<array-key, bool> $seen
	 * @param list<array<string, mixed>> $nodes
	 */
	private static function walk(array $root, array $children, array $columns, array &$seen, array &$nodes): void {
		$stack = [[$root, null, 0]];
		while ($stack !== []) {
			[$a, $parentId, $depth] = array_pop($stack);
			if (isset($seen[$a['id']])) {
				continue;
			}
			$seen[$a['id']] = true;
			$own = [];
			foreach ($columns as $col => $values) {
				$own[$col] = (int)($values[$a['id']] ?? 0);
			}
			$kids = array_values(array_filter($children[$a['id']] ?? [], static fn (array $k): bool => !isset($seen[$k['id']])));
			$nodes[] = [
				'id' => $a['id'],
				'number' => $a['number'],
				'type' => $a['type'],
				'treeParentId' => $parentId,
				'depth' => $depth,
				'hasChildren' => $kids !== [],
				'own' => $own,
				'sum' => $own,
				'below' => array_fill_keys(array_keys($columns), false),
			];
			for ($i = count($kids) - 1; $i >= 0; $i--) {
				$stack[] = [$kids[$i], $a['id'], $depth + 1];
			}
		}
	}

	private static function budgetable(string $type): bool {
		return $type === 'income' || $type === 'expense';
	}

	/**
	 * Nächster Vorfahr, der budgetierbar und vom selben Typ ist.
	 *
	 * @param array{id:int, parentId:?int, type:string, number:string} $account
	 * @param array<int, array{id:int, parentId:?int, type:string, number:string}> $byId
	 */
	private static function treeParent(array $account, array $byId): ?int {
		$seen = [$account['id'] => true];
		$pid = $account['parentId'];
		while ($pid !== null && isset($byId[$pid]) && !isset($seen[$pid])) {
			$seen[$pid] = true;
			$candidate = $byId[$pid];
			if ($candidate['type'] === $account['type']) {
				return $pid;
			}
			$pid = $candidate['parentId'];
		}
		return null;
	}
}
