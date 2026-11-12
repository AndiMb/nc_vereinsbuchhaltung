// Prorata-Mathematik für Beiträge – das Gegenstück zu
// lib/Service/ProrataCalculator.php (Spec §3.3): der Monatsbeitrag ist das
// Atom, Beträge entstehen nur durch Multiplikation mit Kalendermonaten, nie
// durch Teilen oder Runden. Angebrochene Monate zählen an beiden Enden voll.
//
// Ausnahme sind Gruppen mit Jahresbetrag (Issue #270, auf dem Server
// lib/Service/ContributionAmount.php): ein volles Jahr kostet exakt den
// Jahresbetrag, ein angebrochenes wird anteilig auf den Cent gerundet – oder
// bleibt voll, wenn die Gruppe nicht anteilig rechnet.

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
 * Trägt die Zuweisung einen Jahresbetrag (`annualAmountCents`), zählt der:
 * ab zwölf Monaten voll, darunter anteilig gerundet – oder voll, wenn
 * `prorate` aus ist (Einstellung der Beitragsgruppe).
 *
 * @param {{validFrom: string, validTo: string|null, monthlyAmountCents: number, annualAmountCents?: number|null}} assignment
 * @param {{startDate: string, endDate: string}} period
 * @param {boolean} prorate angebrochenes Jahr anteilig berechnen (nur beim Jahresbetrag von Belang)
 * @return {number} in Euro
 */
export function periodAmount(assignment, period, prorate = true) {
	const from = assignment.validFrom > period.startDate ? assignment.validFrom : period.startDate
	const to = assignment.validTo !== null && assignment.validTo < period.endDate ? assignment.validTo : period.endDate
	const months = monthsSpanned(from, to)
	if (!isAnnual(assignment)) {
		return assignment.monthlyAmountCents * months / 100
	}
	if (months === 0) { return 0 }
	if (!prorate || months >= 12) { return assignment.annualAmountCents / 100 }
	return Math.round(assignment.annualAmountCents * months / 12) / 100
}

/**
 * Trägt die Zuweisung einen Jahresbetrag (Gruppe mit Betragsangabe „pro Jahr“)?
 *
 * @param {{annualAmountCents?: number|null}} assignment
 * @return {boolean}
 */
export function isAnnual(assignment) {
	return assignment.annualAmountCents !== null && assignment.annualAmountCents !== undefined
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
