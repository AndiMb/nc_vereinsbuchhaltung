import { describe, expect, it } from 'vitest'
import { assignmentStatusTone, buildMemberRow, nextDueDates } from './memberRow.js'

// Zeilenaufbau der Mitgliederliste: Mandat und Zuweisung in die gemeinsame
// Anzeigeform, die nächste Fälligkeit aus den Forderungen. Die Datenformen
// unterscheiden sich von der Anzeige (Monatsbetrag × Turnus statt Betrag je
// Periode), genau dort liegen die Fälle.

const TODAY = '2026-09-20'
const MEMBER = { id: 1, displayName: 'Petra Aufnahme', email: 'petra@example.org' }

function build(over = {}) {
	return buildMemberRow(MEMBER, { mandates: [], assignments: [], today: TODAY, ...over })
}

function mandate(over = {}) {
	return { id: 10, memberId: 1, iban: 'DE02120300000000202051', mandateReference: 'M-1', status: 'aktiv', ...over }
}

function assignment(over = {}) {
	return {
		id: 20,
		memberId: 1,
		groupId: 3,
		intervalMonths: 12,
		monthlyAmountCents: 1000,
		paymentMethod: 'direct_debit',
		validFrom: '2026-01-01',
		validTo: null,
		active: true,
		...over,
	}
}

function claim(over = {}) {
	return { id: 30, memberId: 1, state: 'offen', dueDate: '2026-10-01', deferred: false, deferredUntil: null, ...over }
}

describe('buildMemberRow – Mandat', () => {
	it('ohne jedes Mandat: kein Mandat', () => {
		expect(build().mandate).toBeNull()
	})

	it('zeigt ein aktives Mandat ohne Marke', () => {
		const row = build({ mandates: [mandate()] })
		expect(row.mandate).toMatchObject({ iban: 'DE02120300000000202051', mandateReference: 'M-1', statusTag: null })
	})

	it('markiert Entwurf und ausgesetztes Mandat', () => {
		expect(build({ mandates: [mandate({ status: 'entwurf' })] }).mandate.statusTag).toBe('Entwurf')
		expect(build({ mandates: [mandate({ status: 'ausgesetzt' })] }).mandate.statusTag).toBe('ausgesetzt')
	})

	it('bevorzugt unter mehreren lebenden das aktive', () => {
		const row = build({ mandates: [mandate({ id: 1, status: 'ausgesetzt', iban: 'A' }), mandate({ id: 2, status: 'aktiv', iban: 'B' })] })
		expect(row.mandate.iban).toBe('B')
	})

	it('ignoriert erloschene Mandate und Mandate anderer Mitglieder', () => {
		const row = build({ mandates: [mandate({ status: 'erloschen' }), mandate({ memberId: 2 })] })
		expect(row.mandate).toBeNull()
	})
})

describe('buildMemberRow – Beitrag', () => {
	it('ohne jede Zuweisung: kein Beitrag, Jahressumme null', () => {
		const row = build()
		expect(row.fee).toBeNull()
		expect(row.yearlyAmount).toBe(0)
	})

	it('rechnet die Zuweisung auf Betrag je Periode um (Monatsbetrag × Turnus)', () => {
		const row = build({ assignments: [assignment({ intervalMonths: 3, monthlyAmountCents: 1050 })] })
		expect(row.fee).toMatchObject({
			amount: 31.5,
			frequencyLabel: 'vierteljährlich',
			active: true,
			statusLabel: 'aktiv',
			needsMandate: true,
		})
		// Jahresbetrag = 12 Monatsbeiträge, unabhängig vom Turnus.
		expect(row.yearlyAmount).toBe(126)
	})

	it('schreibt ungewöhnliche Turnusse aus', () => {
		const row = build({ assignments: [assignment({ intervalMonths: 2 })] })
		expect(row.fee.frequencyLabel).toBe('alle 2 Monate')
	})

	it('beitragsfrei (0 €): kein Mandat nötig, keine Auffälligkeit', () => {
		const fee = build({ assignments: [assignment({ monthlyAmountCents: 0 })] }).fee
		expect(fee).toMatchObject({ free: true, amount: 0, needsMandate: false })
		expect(build({ assignments: [assignment({ monthlyAmountCents: 1000 })] }).fee.free).toBe(false)
	})

	it('Überweisung braucht kein Mandat', () => {
		expect(build({ assignments: [assignment({ paymentMethod: 'ueberweisung' })] }).fee.needsMandate).toBe(false)
	})

	it('mehrere aktive Zuweisungen: früheste wird gezeigt, alle zählen in die Jahressumme', () => {
		const row = build({
			assignments: [
				assignment({ id: 2, validFrom: '2026-05-01', monthlyAmountCents: 500, intervalMonths: 1 }),
				assignment({ id: 1, validFrom: '2026-01-01', monthlyAmountCents: 1000, intervalMonths: 12 }),
			],
		})
		expect(row.fee.amount).toBe(120)
		expect(row.moreFees).toBe(1)
		expect(row.yearlyAmount).toBe(180)
	})

	it('ignoriert Zuweisungen anderer Mitglieder', () => {
		const row = build({ assignments: [assignment({ memberId: 2 })] })
		expect(row.fee).toBeNull()
		expect(row.yearlyAmount).toBe(0)
	})

	it('eine beendete Zuweisung bleibt sichtbar, zählt aber nicht', () => {
		const row = build({ assignments: [assignment({ active: false, validTo: '2026-06-30' })] })
		expect(row.fee.statusLabel).toBe('beendet 30.06.2026')
		expect(row.yearlyAmount).toBe(0)
	})

	it('eine künftige Zuweisung zeigt ihr Startdatum', () => {
		const row = build({ assignments: [assignment({ active: false, validFrom: '2026-12-01' })] })
		expect(row.fee.statusLabel).toBe('ab 01.12.2026')
	})

	it('unter mehreren nicht-aktiven wird die jüngste gezeigt', () => {
		const row = build({
			assignments: [
				assignment({ id: 1, active: false, validFrom: '2024-01-01', validTo: '2024-12-31' }),
				assignment({ id: 2, active: false, validFrom: '2025-01-01', validTo: '2025-12-31' }),
			],
		})
		expect(row.fee.statusLabel).toBe('beendet 31.12.2025')
	})
})

describe('nextDueDates', () => {
	it('liefert je Mitglied das früheste Datum unter den fälligen Forderungen', () => {
		const dates = nextDueDates([
			claim({ id: 1, dueDate: '2026-12-01' }),
			claim({ id: 2, dueDate: '2026-10-01' }),
			claim({ id: 3, memberId: 2, dueDate: '2026-11-15' }),
		])
		expect(dates.get(1)).toBe('2026-10-01')
		expect(dates.get(2)).toBe('2026-11-15')
		expect(dates.has(3)).toBe(false)
	})

	it('zählt offene, im Einzug befindliche und zurückgegebene Forderungen', () => {
		for (const state of ['offen', 'im_einzug', 'zurueckgegeben']) {
			expect(nextDueDates([claim({ state })]).get(1)).toBe('2026-10-01')
		}
	})

	it('übergeht eingezogene, erledigte und stornierte Forderungen', () => {
		for (const state of ['eingezogen', 'erledigt', 'storniert']) {
			expect(nextDueDates([claim({ state })]).size).toBe(0)
		}
	})

	it('eine laufende Stundung verschiebt die Fälligkeit auf ihr Ende', () => {
		const dates = nextDueDates([
			claim({ id: 1, dueDate: '2026-09-01', deferred: true, deferredUntil: '2026-11-30' }),
			claim({ id: 2, dueDate: '2026-12-01' }),
		])
		expect(dates.get(1)).toBe('2026-11-30')
	})

	it('übergeht Forderungen ohne Fälligkeitsdatum', () => {
		expect(nextDueDates([claim({ dueDate: null })]).size).toBe(0)
	})

	it('ohne Forderungen ist die Zuordnung leer', () => {
		expect(nextDueDates([]).size).toBe(0)
	})
})

describe('buildMemberRow – nächste Fälligkeit', () => {
	it('übernimmt das Datum des Mitglieds aus der Zuordnung', () => {
		const row = build({ nextDueDates: new Map([[1, '2026-10-01'], [2, '2026-11-01']]) })
		expect(row.nextDueDate).toBe('2026-10-01')
	})

	it('bleibt leer, wenn zum Mitglied keine fällige Forderung bekannt ist – auch ohne Zuordnung', () => {
		expect(build({ nextDueDates: new Map([[2, '2026-11-01']]) }).nextDueDate).toBeNull()
		expect(build().nextDueDate).toBeNull()
	})
})

describe('Zustandston der Zuweisung', () => {
	it('laufend ist grün, künftig blau, beendet gedämpft – das Wort steht immer daneben', () => {
		expect(assignmentStatusTone(assignment(), TODAY)).toBe('success')
		expect(assignmentStatusTone(assignment({ active: false, validFrom: '2026-10-01' }), TODAY)).toBe('info')
		expect(assignmentStatusTone(assignment({ active: false, validTo: '2026-06-30' }), TODAY)).toBe('muted')
	})

	it('die Zeile trägt Wort und Ton zusammen', () => {
		const running = build({ assignments: [assignment()] }).fee
		expect(running).toMatchObject({ statusLabel: 'aktiv', statusTone: 'success' })
		const future = build({ assignments: [assignment({ active: false, validFrom: '2027-01-01' })] }).fee
		expect(future).toMatchObject({ statusLabel: 'ab 01.01.2027', statusTone: 'info' })
		const ended = build({ assignments: [assignment({ active: false, validTo: '2026-06-30' })] }).fee
		expect(ended).toMatchObject({ statusLabel: 'beendet 30.06.2026', statusTone: 'muted' })
	})
})
