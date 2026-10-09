// Geldrichtung einer Buchung für die Anzeige: kommt Geld rein ('in'), geht
// Geld raus ('out') oder keins von beidem (''): Umbuchung zwischen Geldkonten,
// Buchung ganz ohne Geldkonto, nicht auflösbare Splittbuchung.
//
// Ob die Richtung überhaupt gezeigt wird, entscheidet die Einstellung
// `amount_display` (Standard 'plain': neutral, ein Buchungssatz hat kein
// Vorzeichen). Die Aufrufer (DashboardTab, BookingsTab) liefern bei 'plain'
// die Richtung '' – damit sind alle Helfer unten ohne weiteres neutral.
// Ausnahme sind die mobilen Buchungskarten (BookingCard), die Richtung schon
// vor der Einstellung zeigten und sie weiter immer zeigen.
//
// Bei 'signed' zeigen alle Listen der App die Richtung auf dieselbe Weise:
// Einnahmen als grünes "+120,00 €", Ausgaben als rotes "-45,00 €", Neutrales
// ohne Vorzeichen und ohne Farbe. Das Vorzeichen ist das zweite Signal neben
// der Farbe, damit die Richtung auch ohne Farbsehen lesbar bleibt; der
// Tooltip benennt sie.
import { formatMoney } from './format.js'
import { t } from './l10n.js'

/**
 * Richtung einer Journalzeile (journalRows aus useJournal.js).
 *
 * Maßgeblich ist das Geldkonto (isBank: Bank, Kasse): steht es im Soll, kommt
 * Geld rein; steht es im Haben, geht Geld raus. Bei einer Splittbuchung zählt
 * die einzeilige Seite; die Gegenseite darf dann kein Geldkonto enthalten.
 * N:M-Buchungen und Buchungen ohne Geldkonto bleiben neutral.
 *
 * @param {object} row Journalzeile
 * @param {Object<number, object>} accountsById Konten nach ID
 * @return {'in'|'out'|''} Richtung
 */
export function bookingFlow(row, accountsById) {
	const isMoney = (id) => !!(id && accountsById[id] && accountsById[id].isBank)
	if (row.isSplit) {
		if (!row.splitSide) { return '' }
		const single = row.splitSide === 'credit' ? row.debitAccountId : row.creditAccountId
		if (!isMoney(single)) { return '' }
		const multi = (row.lines || []).filter((l) => (row.splitSide === 'credit' ? l.creditCents : l.debitCents) > 0)
		if (multi.some((l) => isMoney(l.accountId))) { return '' }
		return row.splitSide === 'credit' ? 'in' : 'out'
	}
	const dIn = isMoney(row.debitAccountId)
	const cOut = isMoney(row.creditAccountId)
	if (dIn && !cOut) { return 'in' }
	if (cOut && !dIn) { return 'out' }
	return ''
}

/**
 * Richtung eines Bankumsatzes (transactions): das Vorzeichen kommt von der
 * Bank, negativ ist ein Abgang.
 *
 * @param {object} tx Umsatz mit amount
 * @return {'in'|'out'|''} Richtung
 */
export function transactionFlow(tx) {
	const v = Number(tx && tx.amount) || 0
	if (v < 0) { return 'out' }
	if (v > 0) { return 'in' }
	return ''
}

/**
 * CSS-Klasse für Betragszellen (.vbh-table .num) und Kartenbeträge
 * (.vbh-mcard-amount).
 *
 * @param {string} flow Richtung
 * @return {'pos'|'neg'|''} Klasse
 */
export function flowClass(flow) {
	if (flow === 'in') { return 'pos' }
	if (flow === 'out') { return 'neg' }
	return ''
}

/**
 * Betrag mit Vorzeichen in Richtung: "+120,00 €" bzw. "-45,00 €". Nimmt den
 * Betrag wahlweise vorzeichenlos (Journal) oder vorzeichenbehaftet
 * (Bankumsatz) entgegen. Neutral bleibt es bei formatMoney.
 *
 * @param {number|string|null} amount Betrag
 * @param {string} flow Richtung
 * @return {string} formatierter Betrag
 */
export function formatFlowMoney(amount, flow) {
	const abs = Math.abs(Number(amount) || 0)
	if (abs === 0) { return formatMoney(0) }
	if (flow === 'in') { return '+' + formatMoney(abs) }
	if (flow === 'out') { return formatMoney(-abs) }
	return formatMoney(amount)
}

/**
 * Beschriftung für Tooltip und Screenreader. Neutral liefert null, damit Vue
 * das title-Attribut ganz weglässt.
 *
 * @param {string} flow Richtung
 * @return {string|null} Beschriftung
 */
export function flowLabel(flow) {
	if (flow === 'in') { return t('Einnahme') }
	if (flow === 'out') { return t('Ausgabe') }
	return null
}
