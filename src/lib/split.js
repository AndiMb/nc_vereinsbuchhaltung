// Regeln der Splittbuchung (eine feste Seite, mehrere Gegenkonten). Reine
// Funktionen ohne Vue-Bezug, damit App.vue und BookingDialog.vue dieselbe
// Entscheidung treffen: welche Seite aufgeteilt ist und was noch fehlt. Waeren
// diese beiden Regeln doppelt geschrieben, liefen Anzeige und Nutzlast
// frueher oder spaeter auseinander.

/**
 * Die aufgeteilte Seite eines Buchungsformulars.
 *
 * Im Einfach-Modus folgt sie der Buchungsart: bei einer Einnahme steht das
 * Geldkonto im Soll und die Kategorien im Haben, bei einer Ausgabe umgekehrt.
 * Im Experten-Modus waehlt sie der Nutzer.
 *
 * @param {object} form bookingForm
 * @param {string} mode 'simple' | 'expert'
 * @return {string} 'debit' | 'credit'
 */
export function splitSideOf(form, mode) {
	if (mode === 'simple') { return form.kind === 'income' ? 'credit' : 'debit' }
	return form.splitSide === 'debit' ? 'debit' : 'credit'
}

/**
 * Noch nicht verteilter Betrag in Euro; 0 heisst "die Aufteilung geht auf".
 * Gerechnet wird in Cent, damit sich die Rundungsfehler von 0,1 + 0,2 nicht
 * als "Rest 0,00 €, trotzdem nicht speicherbar" zeigen.
 *
 * @param {number} totalEuro Gesamtbetrag
 * @param {Array<{amount: number}>} lines Zeilen der Aufteilung
 * @return {number} Rest in Euro (negativ = zu viel verteilt)
 */
export function splitRemainder(totalEuro, lines) {
	const total = Math.round(Number(totalEuro || 0) * 100)
	const used = (lines || []).reduce((sum, l) => sum + Math.round(Number(l.amount || 0) * 100), 0)
	return (total - used) / 100
}

/** Ein Rest unter einem halben Cent gilt als aufgegangen. */
export function splitBalanced(totalEuro, lines) {
	return Math.abs(splitRemainder(totalEuro, lines)) < 0.005
}
