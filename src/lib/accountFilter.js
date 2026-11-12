// Kontofilter des Journals: eine Buchung kann ueber ein einzelnes Konto oder
// ueber eine ganze Konto-Kategorie ("Einnahmen", "Ausgaben", ...) gefiltert
// werden. Beides ist bewusst zustandslos gehalten, damit die Logik ohne Vue
// und ohne Backend getestet werden kann.

/**
 * Gruppen-Beschriftung eines Kontos in den Konto-Autocompletes. Konten ohne
 * Kategorie landen unter "Sonstige" - dieselbe Ableitung muss beim Aufbau der
 * Optionsliste und beim Filtern verwendet werden, sonst passen Ueberschrift
 * und Treffermenge nicht zusammen.
 *
 * @param {object} acc Konto
 * @param {(key: string) => string} t Uebersetzungsfunktion
 * @return {string}
 */
export function accountCategoryLabel(acc, t) {
	return acc.category || t('Sonstige')
}

/**
 * Alle Konten, die an einer Journalzeile beteiligt sind. Bei Splitbuchungen
 * sind das mehr als Soll und Haben der ersten Zeile - debitAccountId/
 * creditAccountId tragen nur die jeweils erste Buchungszeile.
 *
 * @param {object} row Journalzeile aus useJournal
 * @return {number[]}
 */
export function journalRowAccountIds(row) {
	const lines = row.lines || []
	if (lines.length) {
		return lines.map((l) => l.accountId)
	}
	return [row.debitAccountId, row.creditAccountId].filter((id) => id !== null && id !== undefined)
}

/**
 * Das Konto samt allen Unterkonten in jeder Tiefe (Issue #326): so rechnet
 * der Gliederungsbericht die Zeile eines Oberkontos, und so filtert das
 * Journal mit der Option "inkl. Unterkonten".
 *
 * @param {number} accountId Konto, dessen Ast gemeint ist
 * @param {Array<{id: number, parentId?: number|null}>} accounts alle Konten
 * @return {Set<number>} IDs des Kontos und seiner Unterkonten
 */
export function accountSubtreeIds(accountId, accounts) {
	const children = new Map()
	for (const acc of accounts) {
		if (acc.parentId === null || acc.parentId === undefined) { continue }
		if (!children.has(acc.parentId)) { children.set(acc.parentId, []) }
		children.get(acc.parentId).push(acc.id)
	}
	const ids = new Set([accountId])
	const queue = [accountId]
	while (queue.length) {
		for (const child of children.get(queue.shift()) || []) {
			// Schon gesehen = Kreis in der Hierarchie; nicht noch einmal ablaufen.
			if (!ids.has(child)) {
				ids.add(child)
				queue.push(child)
			}
		}
	}
	return ids
}

/**
 * Prueft, ob eine Journalzeile zum gewaehlten Kontofilter passt.
 *
 * @param {object} row Journalzeile aus useJournal
 * @param {{accountId?: number, accountIds?: Set<number>, category?: string}|null} filter null = kein Filter;
 *   { accountId } = genau dieses Konto; mit accountIds (accountSubtreeIds)
 *   jedes Konto der Menge; { category } = alle Konten dieser Gruppe
 * @param {object} accountsById Konten nach ID
 * @param {(key: string) => string} t Uebersetzungsfunktion
 * @return {boolean}
 */
export function journalRowMatchesAccountFilter(row, filter, accountsById, t) {
	if (!filter) { return true }
	const ids = journalRowAccountIds(row)
	if (filter.accountIds) {
		return ids.some((id) => filter.accountIds.has(id))
	}
	if (filter.accountId) {
		return ids.includes(filter.accountId)
	}
	if (filter.category !== undefined) {
		return ids.some((id) => {
			const acc = accountsById[id]
			return !!acc && accountCategoryLabel(acc, t) === filter.category
		})
	}
	return true
}
