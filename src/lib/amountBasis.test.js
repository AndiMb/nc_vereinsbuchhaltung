import { describe, expect, it } from 'vitest'
import {
	amountPayload,
	amountWithUnit,
	assignmentAmount,
	assignmentAmountCents,
	assignmentMinOverrideCents,
	groupDefaultAmount,
	groupMinCents,
	isAnnualGroup,
	minOverridePayload,
} from './amountBasis.js'

const monthly = { amountBasis: 'month', minMonthlyAmountCents: 500, defaultMonthlyAmount: 8, minAnnualAmountCents: null, defaultAnnualAmount: null }
const annual = { amountBasis: 'year', minMonthlyAmountCents: 208, defaultMonthlyAmount: 4.17, minAnnualAmountCents: 2500, defaultAnnualAmount: 50 }

describe('Betragsangabe der Beitragsgruppe', () => {
	it('erkennt Gruppen mit Jahresbetrag; ohne Angabe gilt der Monatsbetrag', () => {
		expect(isAnnualGroup(annual)).toBe(true)
		expect(isAnnualGroup(monthly)).toBe(false)
		expect(isAnnualGroup({})).toBe(false)
		expect(isAnnualGroup(null)).toBe(false)
	})

	it('liefert Untergrenze und Standardbeitrag in der Angabe der Gruppe', () => {
		expect(groupMinCents(monthly)).toBe(500)
		expect(groupMinCents(annual)).toBe(2500)
		expect(groupDefaultAmount(monthly)).toBe(8)
		expect(groupDefaultAmount(annual)).toBe(50)
	})
})

describe('Betrag einer Zuweisung', () => {
	const m = { monthlyAmount: 8, monthlyAmountCents: 800, annualAmount: null, annualAmountCents: null, minMonthlyAmountOverrideCents: 300, minAnnualAmountOverrideCents: null }
	const a = { monthlyAmount: 4.17, monthlyAmountCents: 417, annualAmount: 50, annualAmountCents: 5000, minMonthlyAmountOverrideCents: null, minAnnualAmountOverrideCents: 2000 }

	it('ist bei einem Jahresbetrag der Jahresbetrag, nicht der gerundete Monatswert', () => {
		expect(assignmentAmount(m)).toBe(8)
		expect(assignmentAmount(a)).toBe(50)
		expect(assignmentAmountCents(m)).toBe(800)
		expect(assignmentAmountCents(a)).toBe(5000)
	})

	it('nimmt die individuelle Untergrenze aus derselben Angabe', () => {
		expect(assignmentMinOverrideCents(m)).toBe(300)
		expect(assignmentMinOverrideCents(a)).toBe(2000)
	})
})

describe('Nutzlast an den Server', () => {
	it('schickt den Betrag unter dem Namen seiner Angabe – nie beide', () => {
		expect(amountPayload(false, 8)).toEqual({ monthlyAmount: 8 })
		expect(amountPayload(true, 50)).toEqual({ annualAmount: 50 })
	})

	it('schickt die individuelle Untergrenze unter dem Namen ihrer Angabe', () => {
		expect(minOverridePayload(false, 3)).toEqual({ minMonthlyAmountOverride: 3 })
		expect(minOverridePayload(true, null)).toEqual({ minAnnualAmountOverride: null })
	})
})

describe('amountWithUnit', () => {
	it('hängt die Einheit an den formatierten Betrag', () => {
		expect(amountWithUnit('8,00 €', false)).toBe('8,00 € / Monat')
		expect(amountWithUnit('50,00 €', true)).toBe('50,00 € / Jahr')
	})
})
