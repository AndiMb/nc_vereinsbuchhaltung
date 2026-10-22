// Prorata-Mathematik für Beiträge – das Gegenstück zu
// lib/Service/ProrataCalculator.php (Spec §3.3): der Monatsbeitrag ist das
// Atom, Beträge entstehen nur durch Multiplikation mit Kalendermonaten, nie
// durch Teilen oder Runden. Angebrochene Monate zählen an beiden Enden voll.

/**
 * Kalendermonate von `from` bis `to`, beide Enden inklusive und jeweils voll
 * gezählt – wie ProrataCalculator::monthsSpanned() auf dem Server. Der
 * 20. Januar bis zum 5. März sind 3 Monate; liegt `to` vor `from`, sind es 0.
 *
 * @param {string} from JJJJ-MM-TT
 * @param {string} to JJJJ-MM-TT
 * @return {number}
 */
export function monthsSpanned(from, to) {
	if (to < from) { return 0 }
	const [fy, fm] = from.split('-').map(Number)
	const [ty, tm] = to.split('-').map(Number)
	return (ty - fy) * 12 + (tm - fm) + 1
}

/**
 * Beitrag einer Zuweisung innerhalb eines Zeitraums: Monatsbetrag × Monate,
 * in denen die Zuweisung im Zeitraum gilt. Eine ab 1.7. beginnende Zuweisung
 * bringt im Kalenderjahr sechs Monate. Ob sie *heute* aktiv ist, spielt keine
 * Rolle – der Zeitraum kann in der Zukunft liegen. Eine zurückgenommene
 * Zuweisung (Ende vor Beginn) ergibt über monthsSpanned() von selbst 0.
 *
 * @param {{validFrom: string, validTo: string|null, monthlyAmountCents: number}} assignment
 * @param {{startDate: string, endDate: string}} period
 * @return {number} in Euro
 */
export function periodAmount(assignment, period) {
	const from = assignment.validFrom > period.startDate ? assignment.validFrom : period.startDate
	const to = assignment.validTo !== null && assignment.validTo < period.endDate ? assignment.validTo : period.endDate
	return assignment.monthlyAmountCents * monthsSpanned(from, to) / 100
}

/**
 * Die nächsten zwölf Monate ab `today` als Zeitraum – der Bezug für das
 * Beitragsaufkommen, wenn kein Geschäftsjahr gewählt ist („alle Zeiträume").
 * Das Ende ist der letzte Tag des elften Folgemonats, damit es mit
 * monthsSpanned() genau zwölf Monate sind.
 *
 * @param {string} today JJJJ-MM-TT
 * @return {{startDate: string, endDate: string}}
 */
export function nextTwelveMonths(today) {
	const [y, m] = today.split('-').map(Number)
	// Tag 0 des übernächsten Jahresmonats = letzter Tag des Monats davor.
	const end = new Date(Date.UTC(y, m - 1 + 12, 0))
	return { startDate: today, endDate: end.toISOString().slice(0, 10) }
}
