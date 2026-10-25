// Tabellen, die auf schmalen Bildschirmen als Karten erscheinen sollen.
//
// Eine Tabelle mit der Klasse `vbh-stack` wird im Container des `.vbh-tablecard`
// unterhalb von 640 px zu gestapelten Zeilen: jede Zeile ein Block, jede Zelle
// „Beschriftung ... Wert“. Die Beschriftung kommt aus der Kopfzeile. Damit das
// reines CSS bleiben kann, schreibt dieses Modul sie als `data-label` an jede Zelle,
// auch wenn die Tabelle erst später gerendert oder neu aufgebaut wird.

/**
 * Spaltenbeschriftungen aus den Kopfzellen; eine Zelle über mehrere Spalten
 * zählt für jede ihrer Spalten.
 *
 * @param {Array<{text: string, colspan: number}>} headers Kopfzellen der letzten Kopfzeile
 * @return {string[]}
 */
export function columnLabels(headers) {
	const out = []
	for (const h of headers) {
		const label = cleanLabel(h.text)
		for (let i = 0; i < Math.max(1, h.colspan); i++) { out.push(label) }
	}
	return out
}

/** Beschriftung ohne Sortierpfeile und überzählige Leerzeichen. */
export function cleanLabel(text) {
	return String(text ?? '').replace(/[▲▼↑↓]/g, '').replace(/\s+/g, ' ').trim()
}

/**
 * Beschriftung je Zelle einer Zeile. Zellen über mehrere Spalten (Gruppenköpfe,
 * Hinweiszeilen) bekommen keine, ebenso Spalten ohne Kopftext (Aktionen).
 *
 * @param {string[]} columns Ergebnis von columnLabels
 * @param {Array<{colspan: number}>} cells Zellen der Zeile
 * @return {Array<string|null>}
 */
export function labelsForRow(columns, cells) {
	const out = []
	let col = 0
	for (const cell of cells) {
		const span = Math.max(1, cell.colspan)
		out.push(span > 1 ? null : (columns[col] || null))
		col += span
	}
	return out
}

function labelTable(table) {
	const headRows = table.querySelectorAll('thead tr')
	const headerRow = headRows[headRows.length - 1]
	if (!headerRow) { return }
	const columns = columnLabels([...headerRow.children].map((th) => ({ text: th.textContent, colspan: th.colSpan || 1 })))
	for (const row of table.querySelectorAll('tbody tr, tfoot tr')) {
		const cells = [...row.children]
		const labels = labelsForRow(columns, cells.map((c) => ({ colspan: c.colSpan || 1 })))
		cells.forEach((cell, i) => {
			if (labels[i]) {
				if (cell.dataset.label !== labels[i]) { cell.dataset.label = labels[i] }
			} else if (cell.dataset.label !== undefined) {
				delete cell.dataset.label
			}
		})
	}
}

/**
 * Beschriftet alle Tabellen mit `vbh-stack` und hält sie aktuell, wenn sich die Seite ändert.
 *
 * @param {Document} [doc] Dokument, Standard das der Seite
 */
export function installStackTables(doc = document) {
	let queued = false
	const run = () => {
		queued = false
		doc.querySelectorAll('table.vbh-stack').forEach(labelTable)
	}
	const schedule = () => {
		if (queued) { return }
		queued = true
		requestAnimationFrame(run)
	}
	new MutationObserver(schedule).observe(doc.body, { childList: true, subtree: true })
	schedule()
}
