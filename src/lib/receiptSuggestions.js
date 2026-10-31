// Belegvorschläge im Buchungsdialog (#222): Texte zu den Gründen, Symbol je
// Stufe und der Hinweis auf noch nicht gelesene Belege. Reine Funktionen;
// Abruf und Entprellen stehen in composables/useReceiptSuggestions.js.
import { mdiHelpCircleOutline, mdiPaperclipCheck } from '@mdi/js'
import { formatDate as defaultFormatDate, formatMoney as defaultFormatMoney } from './format.js'
import { n as defaultN, t as defaultT, tRaw } from './l10n.js'

/**
 * Ein Grund als Satzteil.
 *
 * Betrag und Datum kommen vom Server so: `{kind:'amount', cents}`,
 * `{kind:'date', date}`; `ref` trägt den Originalausschnitt aus dem Buchungstext
 * in `value` (Nutzerdaten, deshalb tRaw).
 *
 * @param {{kind: string, value?: string, where?: string, cents?: number, date?: string}} reason ein Grund
 * @param {(text: string, vars?: object) => string} [t] Übersetzung
 * @param {(euro: number) => string} [formatMoney] Euro-Betrag -> Text
 * @param {(iso: string) => string} [formatDate] ISO-Datum -> Text
 * @return {string} Text, leer bei unbekannter Art
 */
export function reasonText(reason, t = defaultT, formatMoney = defaultFormatMoney, formatDate = defaultFormatDate) {
	if (reason.kind === 'ref') {
		if (reason.where === 'name') { return tRaw('Rechnungsnr. {value} im Dateinamen', { value: reason.value }) }
		if (reason.where === 'einvoice') { return tRaw('Rechnungsnr. {value} in der E-Rechnung', { value: reason.value }) }
		return tRaw('Rechnungsnr. {value} im Beleg', { value: reason.value })
	}
	if (reason.kind === 'amount') {
		return t('Betrag {amount} im Beleg', { amount: formatMoney((reason.cents ?? 0) / 100) })
	}
	if (reason.kind === 'date') {
		return t('Datum {date}', { date: formatDate(reason.date) })
	}
	return ''
}

/**
 * Alle Gründe in einer Zeile. Ein Buchungstext kann denselben Ausschnitt über
 * mehrere Kerne treffen (R-2026-4711 → 20264711 und 4711): gleiche Texte
 * erscheinen nur einmal.
 *
 * @param {Array<object>} reasons die Gründe
 * @param {(text: string, vars?: object) => string} [t] Übersetzung
 * @param {(euro: number) => string} [formatMoney] Euro-Betrag -> Text
 * @param {(iso: string) => string} [formatDate] ISO-Datum -> Text
 * @return {string} Gründe mit " · " verbunden
 */
export function reasonLine(reasons, t = defaultT, formatMoney = defaultFormatMoney, formatDate = defaultFormatDate) {
	const texts = (reasons || []).map((r) => reasonText(r, t, formatMoney, formatDate)).filter(Boolean)
	return [...new Set(texts)].join(' · ')
}

/**
 * Symbol, CSS-Klasse und Beschriftung einer Stufe.
 *
 * @param {'sure'|'possible'} level die Stufe
 * @param {(text: string, vars?: object) => string} [t] Übersetzung
 * @return {{path: string, className: string, label: string}} Darstellung
 */
export function levelIcon(level, t = defaultT) {
	if (level === 'sure') {
		return { path: mdiPaperclipCheck, className: 'vbh-suggest--sure', label: t('sicher') }
	}
	return { path: mdiHelpCircleOutline, className: 'vbh-suggest--possible', label: t('möglich') }
}

/**
 * Hinweis auf offene, noch nicht gelesene Dateien.
 *
 * @param {number} pending Anzahl
 * @param {(singular: string, plural: string, count: number) => string} [n] Mehrzahl-Übersetzung
 * @return {string} Text, leer bei 0
 */
export function pendingHint(pending, n = defaultN) {
	if (!pending || pending < 1) { return '' }
	return n('%n Beleg wird noch gelesen', '%n Belege werden noch gelesen', pending)
}

/**
 * Ob der Abschnitt „Vorschläge“ erscheint: mit Vorschlägen, oder solange noch
 * Dateien gelesen werden (dann nur Überschrift und Hinweis, damit fehlende
 * Vorschläge nicht als „nichts gefunden“ gelten).
 *
 * @param {number} count Zahl der Vorschläge
 * @param {number} pending Zahl der noch nicht gelesenen Dateien
 * @return {boolean} sichtbar
 */
export function suggestionsVisible(count, pending) {
	return count > 0 || pending > 0
}

/**
 * Titel des Symbols in der Buchungsliste.
 *
 * @param {{level: string, fileIds: number[]}|null} entry Eintrag aus `items`
 * @param {Object<string, string>} names Dateinamen je fileId
 * @param {(singular: string, plural: string, count: number) => string} [n] Mehrzahl-Übersetzung
 * @return {string} Text, leer ohne Eintrag
 */
export function suggestionTitle(entry, names, n = defaultN) {
	if (!entry) { return '' }
	if (entry.level === 'sure') {
		// Der Dateiname sind Nutzerdaten: nicht als t()-Platzhalter auflösen lassen.
		return tRaw('Passender Beleg gefunden: {name}', { name: (names || {})[entry.fileIds[0]] ?? '' })
	}
	return n('%n möglicher Beleg', '%n mögliche Belege', entry.fileIds.length)
}

/**
 * Die sicheren Paare für die Sammelbestätigung, in der Reihenfolge der Zeilen.
 * `items` kommt vom Server bei „keine Treffer“ als leeres Array statt Objekt;
 * die Schlüssel sind Texte.
 *
 * @param {object|Array|null} items Einträge je Buchungs-ID
 * @param {Object<string, string>} names Dateinamen je fileId
 * @param {Array<{id: number, entryNo: number, date: string, description: string, amount: number}>} rows Buchungen
 * @return {Array<object>} Paare
 */
export function sureSuggestionPairs(items, names, rows) {
	const source = items || {}
	const pairs = []
	for (const row of rows || []) {
		const entry = source[row.id] ?? source[String(row.id)]
		if (!entry || entry.level !== 'sure' || !entry.fileIds?.length) { continue }
		const fileId = entry.fileIds[0]
		pairs.push({
			journalId: row.id,
			entryNo: row.entryNo,
			date: row.date,
			description: row.description || '',
			amount: row.amount,
			fileId,
			fileName: (names || {})[fileId] ?? '',
			reasons: (entry.reasons || {})[fileId] ?? [],
		})
	}
	return pairs
}
