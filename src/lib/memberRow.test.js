import { describe, expect, it } from 'vitest'
import { buildMemberRow } from './memberRow.js'

// Übergangs-Adapter der Mitgliederliste: neues Modell (Mandate/Assignment)
// hat Vorrang, der Alt-Bestand greift nur, wenn es zu einem Mitglied nichts
// Neues gibt. Die Fälle unterscheiden genau diese Vorrangregeln und die
// abweichenden Datenformen (Monatsbetrag × Turnus statt Betrag je Periode).

const TODAY = '2026-09-20'
const MEMBER = { id: 1, displayName: 'Petra Aufnahme', email: 'petra@example.org' }

function build(over = {}) {
	return buildMemberRow(MEMBER, { mandates: [], sepaMandates: [], assignments: [], membershipFees: [], today: TODAY, ...over })
}

function mandate(over = {}) {
	return { id: 10, memberId: 1, iban: 'DE02120300000000202051', mandateReference: 'M-1', status: 'aktiv', ...over }
}

function legacyMandate(over = {}) {
	return { id: 10, memberId: 1, iban: 'DE89370400440532013000', mandateReference: 'ALT-1', status: 'active', ...over }
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

function legacyFee(over = {}) {
	return { id: 20, memberId: 1, amount: 30, frequency: 'quarterly', nextDueDate: '2026-10-01', dueCount: 0, active: true, ...over }
}

describe('buildMemberRow – Mandat', () => {
	it('ohne jede Quelle: kein Mandat', () => {
		const row = build()
		expect(row.mandate).toBeNull()
		expect(row.legacyMandate).toBeNull()
	})

	it('zeigt ein aktives Mandat des neuen Modells ohne Marke und ohne Alt-Datensatz', () => {
		const row = build({ mandates: [mandate()] })
		expect(row.mandate).toMatchObject({ iban: 'DE02120300000000202051', mandateReference: 'M-1', statusTag: null })
		expect(row.legacyMandate).toBeNull()
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

	it('fällt auf den Alt-Bestand zurück und gibt nur dann den Roh-Datensatz für die Alt-Aktionen frei', () => {
		const alt = legacyMandate()
		const row = build({ sepaMandates: [alt] })
		expect(row.mandate).toMatchObject({ iban: 'DE89370400440532013000', statusTag: null })
		expect(row.legacyMandate).toBe(alt)
	})

	it('Alt-Bestand: aktiv sticht widerrufen, ein widerrufenes trägt die Marke', () => {
		const widerrufen = legacyMandate({ id: 1, status: 'revoked' })
		expect(build({ sepaMandates: [widerrufen] }).mandate.statusTag).toBe('widerrufen')
		const row = build({ sepaMandates: [widerrufen, legacyMandate({ id: 2, iban: 'AKTIV' })] })
		expect(row.mandate.iban).toBe('AKTIV')
	})

	it('das neue Modell sticht den Alt-Bestand', () => {
		const row = build({ mandates: [mandate()], sepaMandates: [legacyMandate()] })
		expect(row.mandate.iban).toBe('DE02120300000000202051')
		expect(row.legacyMandate).toBeNull()
	})

	it('nur ein erloschenes neues Mandat lässt den Alt-Bestand durch', () => {
		const row = build({ mandates: [mandate({ status: 'erloschen' })], sepaMandates: [legacyMandate()] })
		expect(row.mandate.iban).toBe('DE89370400440532013000')
	})
})

describe('buildMemberRow – Beitrag', () => {
	it('ohne jede Quelle: kein Beitrag, Jahressumme null', () => {
		const row = build()
		expect(row.fee).toBeNull()
		expect(row.yearlyAmount).toBe(0)
	})

	it('rechnet die Zuweisung auf Betrag je Periode um (Monatsbetrag × Turnus), Fälligkeit bleibt leer', () => {
		const row = build({ assignments: [assignment({ intervalMonths: 3, monthlyAmountCents: 1050 })] })
		expect(row.fee).toMatchObject({
			amount: 31.5,
			frequencyLabel: 'vierteljährlich',
			nextDueDate: null,
			dueCount: 0,
			active: true,
			statusLabel: 'aktiv',
			needsMandate: true,
		})
		expect(row.legacyFee).toBeNull()
		// Jahresbetrag = 12 Monatsbeiträge, unabhängig vom Turnus.
		expect(row.yearlyAmount).toBe(126)
	})

	it('schreibt ungewöhnliche Turnusse aus', () => {
		const row = build({ assignments: [assignment({ intervalMonths: 2 })] })
		expect(row.fee.frequencyLabel).toBe('alle 2 Monate')
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

	it('die Zuweisung sticht den Alt-Beitrag', () => {
		const row = build({ assignments: [assignment()], membershipFees: [legacyFee()] })
		expect(row.fee.frequencyLabel).toBe('jährlich')
		expect(row.legacyFee).toBeNull()
	})

	it('Alt-Beitrag: unveränderte Anzeigewerte, Roh-Datensatz für die Alt-Aktionen', () => {
		const alt = legacyFee({ dueCount: 2 })
		const row = build({ membershipFees: [alt] })
		expect(row.fee).toMatchObject({
			amount: 30,
			frequencyLabel: 'vierteljährlich',
			nextDueDate: '2026-10-01',
			dueCount: 2,
			active: true,
			statusLabel: null,
			needsMandate: true,
		})
		expect(row.legacyFee).toBe(alt)
		expect(row.yearlyAmount).toBe(120)
	})

	it('inaktiver Alt-Beitrag zählt nicht in die Jahressumme', () => {
		expect(build({ membershipFees: [legacyFee({ active: false })] }).yearlyAmount).toBe(0)
	})

	it('eine beendete Zuweisung bleibt sichtbar, zählt aber nicht', () => {
		const row = build({ assignments: [assignment({ active: false, validTo: '2026-06-30' })] })
		expect(row.fee.statusLabel).toBe('beendet 2026-06-30')
		expect(row.yearlyAmount).toBe(0)
	})

	it('eine künftige Zuweisung zeigt ihr Startdatum', () => {
		const row = build({ assignments: [assignment({ active: false, validFrom: '2026-12-01' })] })
		expect(row.fee.statusLabel).toBe('ab 2026-12-01')
	})

	it('der Alt-Beitrag sticht eine nicht-aktive Zuweisung', () => {
		const row = build({ assignments: [assignment({ active: false, validTo: '2026-06-30' })], membershipFees: [legacyFee()] })
		expect(row.legacyFee).not.toBeNull()
	})
})
