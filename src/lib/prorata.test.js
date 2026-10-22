import { describe, expect, it } from 'vitest'
import { monthsSpanned, nextTwelveMonths, periodAmount } from './prorata.js'

// Beitragsaufkommen je Zeitraum (Mitgliederliste): dieselbe Monatsregel wie
// ProrataCalculator auf dem Server – angebrochene Monate zählen an beiden
// Enden voll, nie wird geteilt oder gerundet.

const Y2026 = { startDate: '2026-01-01', endDate: '2026-12-31' }
const Y2027 = { startDate: '2027-01-01', endDate: '2027-12-31' }

function assignment(over = {}) {
	return { monthlyAmountCents: 1000, validFrom: '2027-01-01', validTo: null, ...over }
}

describe('monthsSpanned', () => {
	it('zählt Kalendermonate wie der Server: angebrochene Monate an beiden Enden voll', () => {
		expect(monthsSpanned('2027-01-01', '2027-12-31')).toBe(12)
		expect(monthsSpanned('2027-01-20', '2027-03-05')).toBe(3)
		expect(monthsSpanned('2027-01-15', '2027-01-31')).toBe(1)
		expect(monthsSpanned('2027-02-01', '2027-01-31')).toBe(0)
	})
})

describe('periodAmount', () => {
	it('eine Zuweisung ab 1.1.2027 zählt im Zeitraum 2027 voll, im Zeitraum 2026 gar nicht', () => {
		expect(periodAmount(assignment(), Y2027)).toBe(120)
		expect(periodAmount(assignment(), Y2026)).toBe(0)
	})

	it('eine ab 1.7. beginnende Zuweisung bringt sechs Monate', () => {
		expect(periodAmount(assignment({ validFrom: '2027-07-01' }), Y2027)).toBe(60)
	})

	it('eine innerhalb des Zeitraums endende Zuweisung zählt bis zum Ende', () => {
		const bisMaerz = assignment({ validFrom: '2026-01-01', validTo: '2027-03-31' })
		expect(periodAmount(bisMaerz, Y2027)).toBe(30)
		expect(periodAmount(bisMaerz, Y2026)).toBe(120)
	})

	it('beendete und zurückgenommene Zuweisungen zählen nicht', () => {
		expect(periodAmount(assignment({ validFrom: '2025-01-01', validTo: '2025-12-31' }), Y2027)).toBe(0)
		expect(periodAmount(assignment({ validFrom: '2027-03-01', validTo: '2027-02-28' }), Y2027)).toBe(0)
	})

	it('Gruppenwechsel zum Monatsersten: alter und neuer Beitrag ergänzen sich zu zwölf Monaten', () => {
		const alt = assignment({ monthlyAmountCents: 1000, validFrom: '2026-01-01', validTo: '2027-03-31' })
		const neu = assignment({ monthlyAmountCents: 2000, validFrom: '2027-04-01' })
		// 3 × 10 € + 9 × 20 €
		expect(periodAmount(alt, Y2027) + periodAmount(neu, Y2027)).toBe(210)
	})

	it('beitragsfrei (0 €) ergibt 0', () => {
		expect(periodAmount(assignment({ monthlyAmountCents: 0 }), Y2027)).toBe(0)
	})
})

describe('nextTwelveMonths', () => {
	it('endet am letzten Tag des elften Folgemonats – genau zwölf Monate', () => {
		expect(nextTwelveMonths('2026-10-23')).toEqual({ startDate: '2026-10-23', endDate: '2027-09-30' })
		expect(nextTwelveMonths('2026-03-01')).toEqual({ startDate: '2026-03-01', endDate: '2027-02-28' })
		expect(monthsSpanned('2026-10-23', '2027-09-30')).toBe(12)
	})

	it('„alle Zeiträume": heute laufende Beiträge zählen zwölf Monate, künftige ab ihrem Beginn', () => {
		const fenster = nextTwelveMonths('2026-10-23')
		expect(periodAmount(assignment({ validFrom: '2026-01-01' }), fenster)).toBe(120)
		expect(periodAmount(assignment({ validFrom: '2027-01-01' }), fenster)).toBe(90)
		expect(periodAmount(assignment({ validFrom: '2026-01-01', validTo: '2026-11-30' }), fenster)).toBe(20)
	})
})
