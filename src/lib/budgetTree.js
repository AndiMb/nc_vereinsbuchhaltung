// Auf-/Zuklappen und Filtern des Finanzplan-Baums.
//
// Die Zeilen kommen vom Server bereits in Vorordnung (Eltern vor Kindern) mit
// treeParentId, depth, hasChildren und dem Startzustand `expanded` – berechnet
// in lib/Service/BudgetTree.php. Hier wird nur entschieden, welche davon
// gerade sichtbar sind.

// Auffälligkeiten, nach denen „Nur Auffälligkeiten" filtert. `unplannedBelow`
// fehlt bewusst: es ist nur der Hinweis am eingeklappten Elternknoten, die
// betroffene Zeile selbst trägt `unplanned`.
export const ATTENTION_FLAGS = ['overrun', 'unplanned', 'conflict']

// IDs der Zeilen, die beim Öffnen aufgeklappt sind (bis zur Planungsebene).
export function initialExpanded(rows) {
	return new Set(rows.filter((r) => r.expanded).map((r) => r.accountId))
}

// IDs aller Zeilen mit Unterkonten – für „Alle aufklappen".
export function allExpandable(rows) {
	return new Set(rows.filter((r) => r.hasChildren).map((r) => r.accountId))
}

export function hasAttention(row) {
	return (row.flags || []).some((f) => ATTENTION_FLAGS.includes(f))
}

// Sichtbare Zeilen. Ohne Filter: jede Zeile, deren Vorfahren alle
// aufgeklappt sind. Mit Filter: jede auffällige Zeile samt ihren Vorfahren,
// unabhängig vom Klappzustand – sonst verschwände ein Treffer in einem
// eingeklappten Zweig.
export function visibleRows(rows, expanded, onlyAttention = false) {
	if (onlyAttention) {
		const parentOf = new Map(rows.map((r) => [r.accountId, r.treeParentId]))
		const keep = new Set()
		for (const r of rows) {
			if (!hasAttention(r)) { continue }
			let id = r.accountId
			while (id !== null && id !== undefined && !keep.has(id)) {
				keep.add(id)
				id = parentOf.get(id)
			}
		}
		return rows.filter((r) => keep.has(r.accountId))
	}
	const open = new Map()
	const out = []
	for (const r of rows) {
		const parent = r.treeParentId
		if (parent === null || parent === undefined || open.get(parent)) {
			out.push(r)
			open.set(r.accountId, expanded.has(r.accountId))
		}
	}
	return out
}

// Anzahl je Auffälligkeit, für die Zusammenfassung über der Tabelle.
export function attentionCounts(rows) {
	const out = { overrun: 0, unplanned: 0, conflict: 0 }
	for (const r of rows) {
		for (const f of r.flags || []) {
			if (f in out) { out[f]++ }
		}
	}
	return out
}
