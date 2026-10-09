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
 * Prueft, ob eine Journalzeile zum gewaehlten Kontofilter passt.
 *
 * @param {object} row Journalzeile aus useJournal
 * @param {{accountId?: number, category?: string}|null} filter null = kein Filter;
 *   { accountId } = genau dieses Konto; { category } = alle Konten dieser Gruppe
 * @param {object} accountsById Konten nach ID
 * @param {(key: string) => string} t Uebersetzungsfunktion
 * @return {boolean}
 */
export function journalRowMatchesAccountFilter(row, filter, accountsById, t) {
	if (!filter) { return true }
	const ids = journalRowAccountIds(row)
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
