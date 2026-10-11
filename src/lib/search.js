// Suche in „Alle Buchungen“ und „Zuzuordnen“: Text und Betrag in einem Feld.
//
// Der Suchtext jeder Zeile wird einmal je Datenstand vorbereitet
// (buildSearchIndex), pro Tastendruck bleibt dann nur ein includes() und ein
// Set.has() je Zeile. Bei mehreren hundert Buchungen war das kleingeschriebene
// Zusammensetzen der Felder bei jedem Tastendruck der spürbare Teil.

// Trennt die Felder im Suchtext, damit ein Treffer nicht über eine Feldgrenze
// hinweg entsteht („Miete“ + „1200 Bank“ darf nicht „te12“ finden).
const SEP = '\u0001'

/**
 * Liest eine Sucheingabe als Betrag in deutscher Schreibweise.
 *
 * Das Komma ist der Dezimaltrenner. Ein Punkt gefolgt von genau drei Ziffern
 * ist ein Tausendertrenner („1.250“ = 1.250,00 €), jeder andere Punkt ein
 * Dezimalpunkt („12.5“ = 12,50 €). Vorzeichen, Leerzeichen und „€“ werden
 * ignoriert – gesucht wird nach dem Betrag, nicht nach der Richtung.
 *
 * @param {string} query Sucheingabe
 * @return {number|null} Betrag in Cent, null wenn die Eingabe kein Betrag ist
 */
export function parseAmountQuery(query) {
	const s = String(query || '').replace(/€|eur\b/gi, '').replace(/\s+/g, '').replace(/^[+-]/, '')
	let normalized = null
	if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(s)) {
		normalized = s.replace(/\./g, '').replace(',', '.')
	} else if (/^\d+(,\d{1,2})?$/.test(s)) {
		normalized = s.replace(',', '.')
	} else if (/^\d+\.\d{1,2}$/.test(s)) {
		normalized = s
	}
	if (normalized === null) { return null }
	return Math.round(Number(normalized) * 100)
}

/**
 * Bereitet die Suche vor: Kleinschreibung des Suchtexts und Betrag aus der
 * Eingabe. Leere Eingabe liefert null (= kein Suchfilter).
 *
 * @param {string} query Sucheingabe
 * @return {{text: string, cents: number|null}|null}
 */
export function prepareQuery(query) {
	const text = String(query || '').trim().toLowerCase()
	if (!text) { return null }
	return { text, cents: parseAmountQuery(text) }
}

/**
 * Suchindex über eine Liste.
 *
 * @param {Array<object>} rows Zeilen mit eindeutiger id
 * @param {(row: object) => Array<string|number|null|undefined>} fields durchsuchte Textfelder
 * @param {(row: object) => Array<number>} cents Beträge der Zeile in Cent (Vorzeichen egal)
 * @return {Map<number, {text: string, cents: Set<number>}>}
 */
export function buildSearchIndex(rows, fields, cents) {
	const index = new Map()
	for (const row of rows) {
		index.set(row.id, {
			text: fields(row).map((v) => (v === null || v === undefined ? '' : String(v))).join(SEP).toLowerCase(),
			cents: new Set(cents(row).map((c) => Math.abs(Math.round(Number(c) || 0)))),
		})
	}
	return index
}

/**
 * Passt ein Indexeintrag zur Suche? Text ODER Betrag (F1.2): „12“ findet
 * Buchung Nr. 12, Texte mit „12“ und Beträge von 12,00 €.
 *
 * @param {{text: string, cents: Set<number>}|undefined} entry Indexeintrag
 * @param {{text: string, cents: number|null}|null} query aus prepareQuery()
 * @return {boolean}
 */
export function matchesQuery(entry, query) {
	if (!query) { return true }
	if (!entry) { return false }
	if (query.cents !== null && entry.cents.has(query.cents)) { return true }
	return entry.text.includes(query.text)
}

/**
 * Suchfelder und Beträge einer Journalzeile (useJournal.journalRows). Bei
 * Splittbuchungen zählen der Gesamtbetrag und jeder Teilbetrag.
 */
export const journalSearch = {
	fields: (r) => [r.entryNo, r.description, r.soll, r.haben],
	cents: (r) => [
		Math.round((Number(r.amount) || 0) * 100),
		...(r.lines || []).map((l) => (l.debitCents || 0) + (l.creditCents || 0)),
	],
}

/** Suchfelder und Betrag eines Bankumsatzes („Zuzuordnen“). */
export const transactionSearch = {
	fields: (tx) => [tx.counterparty, tx.purpose, tx.bookingDate],
	cents: (tx) => [tx.amountCents ?? Math.round((Number(tx.amount) || 0) * 100)],
}
