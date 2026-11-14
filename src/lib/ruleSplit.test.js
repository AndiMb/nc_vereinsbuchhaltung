import { describe, expect, it } from 'vitest'
import { ruleSplitParts, ruleSplitSuggestion } from './ruleSplit.js'

// Gegenstueck zu tests/unit/RuleSplitTest.php (Issue #163): beide Seiten
// muessen gleich aufteilen, sonst schlaegt der Vorschlag etwas anderes vor,
// als der Import gebucht haette.
function rule(parts) {
	return { contraAccountId: 5, costCenterId: 3, parts }
}

function fixed() {
	return rule([
		{ accountId: 10, amount: 10 },
		{ accountId: 11, amount: 5, costCenterId: 7 },
		{ accountId: 12, amount: 3, costCenterId: null },
	])
}

describe('ruleSplitParts', () => {
	it('ohne Teile geht der ganze Betrag aufs Gegenkonto', () => {
		expect(ruleSplitParts(rule([]), -6000)).toEqual([{ accountId: 5, amountCents: 6000, costCenterId: 3 }])
	})

	it('feste Teile in Reihenfolge, Rest aufs Gegenkonto', () => {
		expect(ruleSplitParts(fixed(), 6000)).toEqual([
			{ accountId: 10, amountCents: 1000, costCenterId: null },
			{ accountId: 11, amountCents: 500, costCenterId: 7 },
			{ accountId: 12, amountCents: 300, costCenterId: null },
			{ accountId: 5, amountCents: 4200, costCenterId: 3 },
		])
	})

	it('Rest 0 ergibt keinen Restteil', () => {
		const parts = ruleSplitParts(fixed(), 1800)
		expect(parts).toHaveLength(3)
		expect(parts.map((p) => p.accountId)).not.toContain(5)
	})

	it('Ausgang teilt wie Eingang', () => {
		expect(ruleSplitParts(fixed(), -6000)).toEqual(ruleSplitParts(fixed(), 6000))
	})

	it('zu kleiner Umsatz liefert null', () => {
		expect(ruleSplitParts(fixed(), 1200)).toBeNull()
	})

	it('rechnet in Cent ohne Gleitkomma-Rest', () => {
		const parts = ruleSplitParts(rule([{ accountId: 10, amount: 0.1 }, { accountId: 11, amount: 0.2 }]), 30)
		expect(parts).toEqual([
			{ accountId: 10, amountCents: 10, costCenterId: null },
			{ accountId: 11, amountCents: 20, costCenterId: null },
		])
	})
})

describe('ruleSplitSuggestion', () => {
	it('mehrere Teile werden als Aufteilung vorgeschlagen', () => {
		const s = ruleSplitSuggestion(fixed(), 6000)
		expect(s.splitParts).toHaveLength(4)
	})

	// Umsatz = einziger Anteil: der Import bucht ein Konto - der Vorschlag darf
	// dann keinen Aufteilen-Dialog mit nur einer Zeile oeffnen (der lehnt das ab).
	it('ein einziger Teil wird als gewoehnlicher Vorschlag geliefert', () => {
		const s = ruleSplitSuggestion(rule([{ accountId: 10, amount: 10, costCenterId: 7 }]), -1000)
		expect(s).toEqual({ accountId: 10, costCenterId: 7, splitParts: null })
	})

	it('zu kleiner Umsatz liefert keinen Vorschlag', () => {
		expect(ruleSplitSuggestion(fixed(), 1200)).toBeNull()
	})
})
