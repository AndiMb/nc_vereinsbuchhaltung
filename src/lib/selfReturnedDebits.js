// Anzeigelogik der eigenen Rücklastschriften in „Mein Beitrag" (SelfServiceTab.vue,
// Issue #122, Spec §3.4 Pflicht-UI „Rücklastschriften als Klartext (nie Code)"):
// macht aus der Antwort von GET /api/self/returned-debits die Zeilen der
// Oberfläche. Reine Funktionen ohne Vue-/DOM-Bezug, damit sich Reihenfolge und
// Leerfall ohne Komponententest-Umgebung prüfen lassen (selfReturnedDebits.test.js).
//
// Die Antwort trägt bewusst keinen Rückgabecode – der Grund steht schon als
// Klartext darin (`reason`, ein wahrheitsfester Text je Rückgabe-Klasse, Spec
// §3.6/§3.11) und wird hier unverändert übernommen. Bezeichnung und Zeitraum
// sind Nutzerdaten und stehen nie als Variable in t() (der Wrapper escapt
// HTML: „Echo & Söhne" würde sonst als „Echo &amp; Söhne" erscheinen) –
// sie werden hier als Text zusammengesetzt.
import { formatDate, formatMoney } from './format.js'

/**
 * Zeile der Oberfläche zu einer Rücklastschrift der API.
 *
 * @param {{receivedAt: string, amountCents: number, description: ?string, periodStart: ?string, periodEnd: ?string, reason: string}} debit
 */
function row(debit) {
	const period = debit.periodStart && debit.periodEnd
		? `${formatDate(debit.periodStart)}–${formatDate(debit.periodEnd)}`
		: ''
	return {
		date: formatDate(debit.receivedAt),
		amount: formatMoney(debit.amountCents / 100),
		// Welcher Beitrag zurückkam: Bezeichnung und betroffener Zeitraum, was davon da ist
		subject: [debit.description, period].filter(Boolean).join(' · '),
		reason: debit.reason,
	}
}

/**
 * Die Rücklastschriften als Anzeigezeilen, neueste zuerst. Bei gleichem Datum
 * bleibt die Reihenfolge der API (zuletzt erfasste zuerst). Leere oder fehlende
 * Liste ergibt eine leere Liste – dann steht der Leer-Hinweis da, sonst nie.
 *
 * @param {Array|null|undefined} debits Antwort von GET /api/self/returned-debits
 * @return {Array<{date: string, amount: string, subject: string, reason: string}>}
 */
export function returnedDebitRows(debits) {
	return [...(debits ?? [])]
		// ISO-Datum (JJJJ-MM-TT): die Textreihenfolge ist die zeitliche
		.sort((a, b) => b.receivedAt.localeCompare(a.receivedAt))
		.map(row)
}
