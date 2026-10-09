// Zeilenaufbau der Mitgliederliste (MembersList.vue): bringt Mandat und
// Zuweisung (Issues #66/#68) und die nächste offene Forderung zu einem Mitglied
// in eine gemeinsame Anzeigeform.
//
// Die Anzeige ist bewusst flacher als das Datenmodell: eine Zuweisung kennt
// Monatsbetrag × Turnus in Monaten, die Liste zeigt dagegen „Betrag je Periode"
// und „Frequenz". Die Fälligkeit steht nicht an der Zuweisung, sondern erst an
// der Forderung, die der Einzugszyklus daraus erzeugt – deshalb kommt die
// „nächste Fälligkeit" aus den Forderungen (siehe nextDueDates()).
import { formatDate } from './format.js'
import { intervalLabel } from './frequency.js'
import { t } from './l10n.js'

function isoToday() {
	return new Date().toISOString().slice(0, 10)
}

/**
 * Zustände einer Forderung (ClaimOverviewService), die noch „fällig" sind: offen,
 * im Einzug (der Lauf ist noch nicht ausgeführt) und zurückgegeben (wieder offen).
 * Eingezogene gelten als bezahlt-unterwegs, erledigte und stornierte sind erledigt.
 */
const DUE_STATES = new Set(['offen', 'im_einzug', 'zurueckgegeben'])

/**
 * Die nächste Fälligkeit je Mitglied aus den Forderungen (Antwort von
 * GET /claims/overview): das früheste Datum unter den noch fälligen. Eine
 * gestundete Forderung zählt mit dem Ende der Stundung, nicht mit ihrer
 * ursprünglichen Fälligkeit. Einmal über alle Forderungen gerechnet statt je
 * Zeile, damit die Liste mit der Zahl der Mitglieder nicht quadratisch wächst.
 *
 * @param {Array<{memberId: number, state: string, dueDate: ?string, deferred?: boolean, deferredUntil?: ?string}>} claims
 * @return {Map<number, string>} Mitglieds-Id → Datum (JJJJ-MM-TT)
 */
export function nextDueDates(claims) {
	const next = new Map()
	for (const claim of claims) {
		if (!DUE_STATES.has(claim.state)) { continue }
		const due = claim.deferred && claim.deferredUntil ? claim.deferredUntil : claim.dueDate
		if (!due) { continue }
		const known = next.get(claim.memberId)
		if (known === undefined || due < known) { next.set(claim.memberId, due) }
	}
	return next
}

/** Anzeigeform eines Mandats (`vbh_mandates`). */
function mandateView(mandate) {
	return {
		iban: mandate.iban,
		mandateReference: mandate.mandateReference,
		// Ein aktives Mandat trägt keine Marke; „erloschen" kommt nie an (siehe pickMandate).
		statusTag: { entwurf: t('Entwurf'), ausgesetzt: t('ausgesetzt') }[mandate.status] ?? null,
	}
}

/** Zustand einer Zuweisung als Text – der Zeitraum *ist* der Status (Spec §2.2). */
function assignmentStatusLabel(assignment, today) {
	if (assignment.active) { return t('aktiv') }
	if (assignment.validFrom > today) { return t('ab {datum}', { datum: formatDate(assignment.validFrom) }) }
	return t('beendet {datum}', { datum: formatDate(assignment.validTo) })
}

/**
 * Ton des Statuspunkts neben dem Zustand (`vbh-status--<ton>` in styles.css):
 * laufend = `success`, künftig = `info`, beendet = `muted`. Die Farbe ist nur
 * die zweite Spur, das Wort steht daneben.
 *
 * @param {{active: boolean, validFrom: string}} assignment
 * @param {string} today Stichtag (JJJJ-MM-TT)
 * @return {'success'|'info'|'muted'}
 */
export function assignmentStatusTone(assignment, today = isoToday()) {
	if (assignment.active) { return 'success' }
	return assignment.validFrom > today ? 'info' : 'muted'
}

/**
 * Anzeigeform eines Beitrags aus einer Zuweisung. `amount` ist der Betrag je
 * Periode (Monatsbetrag × Turnus), damit die Spalte „Betrag" neben der
 * Frequenz dasselbe bedeutet wie auf der Rechnung; `yearlyAmount` zählt nur,
 * solange die Zuweisung aktiv ist.
 */
function assignmentView(assignment, today) {
	const free = assignment.monthlyAmountCents === 0
	return {
		amount: assignment.monthlyAmountCents * assignment.intervalMonths / 100,
		// Beitragsfrei (0 €, z. B. Gruppe „Ruhend“ für Pausen): es entstehen keine Forderungen
		// und es wird nichts eingezogen – weder Betrag noch Mandat sind hier ein Thema.
		free,
		frequencyLabel: intervalLabel(assignment.intervalMonths),
		active: assignment.active,
		statusLabel: assignmentStatusLabel(assignment, today),
		statusTone: assignmentStatusTone(assignment, today),
		// Überweiser und Beitragsfreie brauchen kein Mandat – „aktiver Beitrag ohne
		// Mandat" ist dort keine Auffälligkeit.
		needsMandate: assignment.paymentMethod === 'direct_debit' && !free,
		yearlyAmount: assignment.active ? assignment.monthlyAmountCents * 12 / 100 : 0,
	}
}

/** Das anzuzeigende Mandat: ein lebendes (aktiv vor Entwurf/ausgesetzt), sonst keines. */
function pickMandate(memberId, mandates) {
	const live = mandates.filter((m) => m.memberId === memberId && m.status !== 'erloschen')
	const current = live.find((m) => m.status === 'aktiv') ?? live[0]
	return current ? mandateView(current) : null
}

/**
 * Der anzuzeigende Beitrag: die früheste aktive Zuweisung (weitere aktive
 * zählt `moreFees`, ihr Jahresbetrag fließt in `yearlyAmount` ein), sonst die
 * jüngste nicht-aktive – damit auch eine erst künftig beginnende oder gerade
 * beendete Zuweisung sichtbar bleibt statt als „kein Beitrag" zu erscheinen.
 */
function pickFee(memberId, assignments, today) {
	const mine = assignments.filter((a) => a.memberId === memberId)
	const active = mine
		.filter((a) => a.active)
		.sort((a, b) => a.validFrom.localeCompare(b.validFrom) || a.id - b.id)
		.map((a) => assignmentView(a, today))
	if (active.length) {
		return {
			view: active[0],
			moreFees: active.length - 1,
			yearlyAmount: active.reduce((sum, v) => sum + v.yearlyAmount, 0),
		}
	}

	const latest = mine.sort((a, b) => b.validFrom.localeCompare(a.validFrom) || b.id - a.id)[0]
	return latest
		? { view: assignmentView(latest, today), moreFees: 0, yearlyAmount: 0 }
		: { view: null, moreFees: 0, yearlyAmount: 0 }
}

/**
 * Eine Zeile der Mitgliederliste. `nextDueDates` kommt aus {@link nextDueDates}
 * und wird einmal je Liste berechnet.
 */
export function buildMemberRow(member, { mandates, assignments, nextDueDates: dueDates = new Map(), today = isoToday() }) {
	const fee = pickFee(member.id, assignments, today)
	return {
		key: `member-${member.id}`,
		member,
		displayName: member.displayName,
		email: member.email,
		mandate: pickMandate(member.id, mandates),
		fee: fee.view,
		moreFees: fee.moreFees,
		yearlyAmount: fee.yearlyAmount,
		nextDueDate: dueDates.get(member.id) ?? null,
	}
}
