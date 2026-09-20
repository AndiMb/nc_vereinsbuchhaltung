// Zeilenaufbau der Mitgliederliste (MembersList.vue): ein Übergangs-Adapter,
// der das NEUE Domänenmodell (Mandate/Assignment, Issues #66/#68) und den
// ALTEN Bestand (SepaMandate/MembershipFee) in dieselbe Anzeigeform bringt.
//
// Die Datenformen sind nicht feldgleich (alt: Betrag je Periode + Frequenz +
// Fälligkeitsdatum; neu: Monatsbetrag + Turnus in Monaten, KEINE Fälligkeit –
// die entsteht erst mit der Forderung), deshalb normalisiert dieser Adapter,
// statt die Vorlage an beide Formen anzupassen. Vorrang hat immer das neue
// Modell (dort legt der Aufnahme-Assistent und der CSV-Import an); der Alt-
// Bestand greift nur, wenn es zu einem Mitglied nichts Neues gibt.
//
// Beim finalen Cutover (Alt-Tabellen fallen weg, Spec „Umbaupfad" §3.1) lassen
// sich die legacy*-Zweige samt `legacyMandate`/`legacyFee` ersatzlos streichen.
import { FREQUENCY_MONTHS, frequencyLabel } from './frequency.js'
import { t } from './l10n.js'

function isoToday() {
	return new Date().toISOString().slice(0, 10)
}

/** Turnus in Monaten als bekanntes Frequenz-Label; ungewöhnliche Turnusse („alle 2 Monate") ausgeschrieben. */
function intervalLabel(months) {
	const key = Object.keys(FREQUENCY_MONTHS).find((k) => FREQUENCY_MONTHS[k] === months)
	return key ? frequencyLabel(key) : t('alle {monate} Monate', { monate: months })
}

/** Anzeigeform eines Mandats aus dem neuen Modell (`vbh_mandates`). */
function mandateView(mandate) {
	return {
		iban: mandate.iban,
		mandateReference: mandate.mandateReference,
		// Ein aktives Mandat trägt keine Marke; „erloschen" kommt nie an (siehe pickMandate).
		statusTag: { entwurf: t('Entwurf'), ausgesetzt: t('ausgesetzt') }[mandate.status] ?? null,
	}
}

/** Anzeigeform eines Mandats aus dem Alt-Bestand (`vbh_sepa_mandates`). */
function legacyMandateView(mandate) {
	return {
		iban: mandate.iban,
		mandateReference: mandate.mandateReference,
		statusTag: mandate.status === 'active' ? null : t('widerrufen'),
	}
}

/** Zustand einer Zuweisung als Text – der Zeitraum *ist* der Status (Spec §2.2). */
function assignmentStatusLabel(assignment, today) {
	if (assignment.active) { return t('aktiv') }
	if (assignment.validFrom > today) { return t('ab {datum}', { datum: assignment.validFrom }) }
	return t('beendet {datum}', { datum: assignment.validTo })
}

/**
 * Anzeigeform eines Beitrags aus einer Zuweisung. `amount` ist der Betrag je
 * Periode (Monatsbetrag × Turnus), damit die Spalte „Betrag" neben der
 * Frequenz dasselbe bedeutet wie beim Alt-Beitrag; `yearlyAmount` zählt nur,
 * solange die Zuweisung aktiv ist.
 */
function assignmentView(assignment, today) {
	return {
		amount: assignment.monthlyAmountCents * assignment.intervalMonths / 100,
		frequencyLabel: intervalLabel(assignment.intervalMonths),
		nextDueDate: null,
		dueCount: 0,
		active: assignment.active,
		statusLabel: assignmentStatusLabel(assignment, today),
		// Überweiser brauchen kein Mandat – „aktiver Beitrag ohne Mandat" ist
		// dort keine Auffälligkeit.
		needsMandate: assignment.paymentMethod === 'direct_debit',
		yearlyAmount: assignment.active ? assignment.monthlyAmountCents * 12 / 100 : 0,
	}
}

/** Anzeigeform eines Beitrags aus dem Alt-Bestand (`vbh_membership_fees`). */
function legacyFeeView(fee) {
	return {
		amount: fee.amount,
		frequencyLabel: frequencyLabel(fee.frequency),
		nextDueDate: fee.nextDueDate,
		dueCount: fee.dueCount,
		active: fee.active,
		statusLabel: null,
		needsMandate: true,
		yearlyAmount: fee.active ? fee.amount * (12 / (FREQUENCY_MONTHS[fee.frequency] || 12)) : 0,
	}
}

/**
 * Das anzuzeigende Mandat: ein lebendes aus dem neuen Modell (aktiv vor
 * Entwurf/ausgesetzt), sonst das aus dem Alt-Bestand (aktiv sticht widerrufen –
 * gezeigt wird das, mit dem tatsächlich eingezogen wird).
 */
function pickMandate(memberId, mandates, sepaMandates) {
	const live = mandates.filter((m) => m.memberId === memberId && m.status !== 'erloschen')
	const current = live.find((m) => m.status === 'aktiv') ?? live[0]
	if (current) { return { view: mandateView(current), legacy: null } }

	const legacy = sepaMandates.filter((m) => m.memberId === memberId)
	const legacyCurrent = legacy.find((m) => m.status === 'active') ?? legacy[0]
	return legacyCurrent
		? { view: legacyMandateView(legacyCurrent), legacy: legacyCurrent }
		: { view: null, legacy: null }
}

/**
 * Der anzuzeigende Beitrag: die früheste aktive Zuweisung (weitere aktive
 * zählt `moreFees`, ihr Jahresbetrag fließt in `yearlyAmount` ein), sonst der
 * Alt-Beitrag, sonst die jüngste nicht-aktive Zuweisung – damit auch eine
 * erst künftig beginnende oder gerade beendete Zuweisung sichtbar bleibt statt
 * als „kein Beitrag" zu erscheinen.
 */
function pickFee(memberId, assignments, membershipFees, today) {
	const mine = assignments.filter((a) => a.memberId === memberId)
	const active = mine
		.filter((a) => a.active)
		.sort((a, b) => a.validFrom.localeCompare(b.validFrom) || a.id - b.id)
		.map((a) => assignmentView(a, today))
	if (active.length) {
		return {
			view: active[0],
			legacy: null,
			moreFees: active.length - 1,
			yearlyAmount: active.reduce((sum, v) => sum + v.yearlyAmount, 0),
		}
	}

	const legacy = membershipFees.find((f) => f.memberId === memberId)
	if (legacy) {
		const view = legacyFeeView(legacy)
		return { view, legacy, moreFees: 0, yearlyAmount: view.yearlyAmount }
	}

	const latest = mine.sort((a, b) => b.validFrom.localeCompare(a.validFrom) || b.id - a.id)[0]
	return latest
		? { view: assignmentView(latest, today), legacy: null, moreFees: 0, yearlyAmount: 0 }
		: { view: null, legacy: null, moreFees: 0, yearlyAmount: 0 }
}

/**
 * Eine Zeile der Mitgliederliste. `mandate`/`fee` sind die gemeinsame
 * Anzeigeform; `legacyMandate`/`legacyFee` tragen den Roh-Datensatz NUR, wenn
 * die Anzeige aus dem Alt-Bestand stammt – die Alt-Aktionen (Bearbeiten,
 * Löschen, Widerrufen …) sprechen die alten Endpunkte an und dürfen nie mit
 * der Id eines neuen Datensatzes aufgerufen werden.
 */
export function buildMemberRow(member, { mandates, sepaMandates, assignments, membershipFees, today = isoToday() }) {
	const mandate = pickMandate(member.id, mandates, sepaMandates)
	const fee = pickFee(member.id, assignments, membershipFees, today)
	return {
		key: `member-${member.id}`,
		member,
		displayName: member.displayName,
		email: member.email,
		mandate: mandate.view,
		legacyMandate: mandate.legacy,
		fee: fee.view,
		legacyFee: fee.legacy,
		moreFees: fee.moreFees,
		yearlyAmount: fee.yearlyAmount,
	}
}
