// Datumsfilter des Journals (Issue #328): eine Spanne von/bis über das
// Buchungsdatum, beide Grenzen eingeschlossen, jede Grenze für sich
// freiwillig. Zustandslos, damit die Logik ohne Vue getestet werden kann.

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/

/**
 * Datum aus der URL (?from=/?to=): nur ein echtes Kalenderdatum JJJJ-MM-TT
 * gilt, alles andere ist kein Filter.
 *
 * @param {unknown} raw Wert des Query-Parameters
 * @return {string|null} das Datum oder null
 */
export function parseDateParam(raw) {
	if (typeof raw !== 'string') { return null }
	const m = ISO_DATE.exec(raw)
	if (!m) { return null }
	const [year, month, day] = [Number(m[1]), Number(m[2]), Number(m[3])]
	const date = new Date(Date.UTC(year, month - 1, day))
	// Date rollt den 31.09. stillschweigend auf den 01.10. - das ist kein gültiges Datum.
	return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? raw : null
}

/**
 * Liegt das Buchungsdatum der Journalzeile in der Spanne?
 *
 * @param {{date?: string|null}} row Journalzeile aus useJournal (Datum JJJJ-MM-TT)
 * @param {string|null} from erster Tag der Spanne oder null (offen)
 * @param {string|null} to letzter Tag der Spanne oder null (offen)
 * @return {boolean}
 */
export function journalRowInDateRange(row, from, to) {
	if (!from && !to) { return true }
	const date = String(row.date || '').slice(0, 10)
	if (!date) { return false }
	// JJJJ-MM-TT sortiert als Text wie als Datum.
	return (!from || date >= from) && (!to || date <= to)
}
