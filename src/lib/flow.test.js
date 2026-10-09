import { describe, expect, it } from 'vitest'
import { bookingFlow, flowClass, flowLabel, formatFlowMoney, transactionFlow } from './flow.js'

// Konten wie im Standard-Kontenrahmen (AccountService): Bank und Kasse sind
// Geldkonten, Erlöse und Aufwand sind Kategorien.
const accounts = {
	1: { id: 1, number: '1200', name: 'Bankkonto', type: 'asset', isBank: true },
	2: { id: 2, number: '1000', name: 'Kasse', type: 'asset', isBank: true },
	3: { id: 3, number: '4000', name: 'Mitgliedsbeiträge', type: 'income', isBank: false },
	4: { id: 4, number: '6000', name: 'Raummiete', type: 'expense', isBank: false },
	5: { id: 5, number: '1400', name: 'Forderungen', type: 'asset', isBank: false },
}

const normalize = (s) => s.replace(/\s/g, ' ')

describe('bookingFlow', () => {
	it('Geldkonto im Soll ist eine Einnahme', () => {
		expect(bookingFlow({ debitAccountId: 1, creditAccountId: 3 }, accounts)).toBe('in')
	})

	it('Geldkonto im Haben ist eine Ausgabe', () => {
		expect(bookingFlow({ debitAccountId: 4, creditAccountId: 2 }, accounts)).toBe('out')
	})

	it('Umbuchung zwischen Geldkonten ist neutral', () => {
		expect(bookingFlow({ debitAccountId: 2, creditAccountId: 1 }, accounts)).toBe('')
	})

	it('Buchung ohne Geldkonto ist neutral', () => {
		expect(bookingFlow({ debitAccountId: 5, creditAccountId: 3 }, accounts)).toBe('')
	})

	it('unbekannte Konten sind neutral', () => {
		expect(bookingFlow({ debitAccountId: 99, creditAccountId: 3 }, accounts)).toBe('')
		expect(bookingFlow({ debitAccountId: null, creditAccountId: null }, accounts)).toBe('')
	})

	it('Splitt mit Bank im Soll und mehreren Erlöskonten ist eine Einnahme', () => {
		const row = {
			isSplit: true,
			splitSide: 'credit',
			debitAccountId: 1,
			creditAccountId: 3,
			lines: [
				{ accountId: 1, debitCents: 15000, creditCents: 0 },
				{ accountId: 3, debitCents: 0, creditCents: 10000 },
				{ accountId: 3, debitCents: 0, creditCents: 5000 },
			],
		}
		expect(bookingFlow(row, accounts)).toBe('in')
	})

	it('Splitt mit mehreren Aufwandskonten und Kasse im Haben ist eine Ausgabe', () => {
		const row = {
			isSplit: true,
			splitSide: 'debit',
			debitAccountId: 4,
			creditAccountId: 2,
			lines: [
				{ accountId: 4, debitCents: 3000, creditCents: 0 },
				{ accountId: 4, debitCents: 2000, creditCents: 0 },
				{ accountId: 2, debitCents: 0, creditCents: 5000 },
			],
		}
		expect(bookingFlow(row, accounts)).toBe('out')
	})

	it('Splitt bleibt neutral, wenn die Mehrfachseite ein Geldkonto enthält', () => {
		const row = {
			isSplit: true,
			splitSide: 'credit',
			debitAccountId: 1,
			creditAccountId: 3,
			lines: [
				{ accountId: 1, debitCents: 15000, creditCents: 0 },
				{ accountId: 3, debitCents: 0, creditCents: 10000 },
				{ accountId: 2, debitCents: 0, creditCents: 5000 },
			],
		}
		expect(bookingFlow(row, accounts)).toBe('')
	})

	it('Splitt ohne Geldkonto auf der Einzelseite und N:M-Splitt sind neutral', () => {
		expect(bookingFlow({ isSplit: true, splitSide: 'credit', debitAccountId: 5, creditAccountId: 3, lines: [] }, accounts)).toBe('')
		expect(bookingFlow({ isSplit: true, splitSide: null, debitAccountId: 1, creditAccountId: 3, lines: [] }, accounts)).toBe('')
	})
})

describe('transactionFlow', () => {
	it('folgt dem Vorzeichen des Bankumsatzes', () => {
		expect(transactionFlow({ amount: 120 })).toBe('in')
		expect(transactionFlow({ amount: -45.5 })).toBe('out')
		expect(transactionFlow({ amount: '-3' })).toBe('out')
		expect(transactionFlow({ amount: 0 })).toBe('')
		expect(transactionFlow({})).toBe('')
	})
})

describe('flowClass', () => {
	it('bildet die Richtung auf die Farbklassen ab', () => {
		expect(flowClass('in')).toBe('pos')
		expect(flowClass('out')).toBe('neg')
		expect(flowClass('')).toBe('')
	})
})

describe('formatFlowMoney', () => {
	it('setzt das Vorzeichen in Richtung, unabhängig vom Vorzeichen der Eingabe', () => {
		expect(normalize(formatFlowMoney(120, 'in'))).toBe('+120,00 €')
		expect(normalize(formatFlowMoney(45.5, 'out'))).toBe('-45,50 €')
		expect(normalize(formatFlowMoney(-45.5, 'out'))).toBe('-45,50 €')
		expect(normalize(formatFlowMoney(-120, 'in'))).toBe('+120,00 €')
	})

	it('lässt neutrale Beträge wie formatMoney', () => {
		expect(normalize(formatFlowMoney(1500, ''))).toBe('1.500,00 €')
		expect(normalize(formatFlowMoney(-7, ''))).toBe('-7,00 €')
	})

	it('zeigt null ohne Vorzeichen', () => {
		expect(normalize(formatFlowMoney(0, 'out'))).toBe('0,00 €')
		expect(normalize(formatFlowMoney(null, 'in'))).toBe('0,00 €')
	})
})

describe('flowLabel', () => {
	it('benennt die Richtung und lässt neutral ohne Attribut', () => {
		expect(flowLabel('in')).toBe('Einnahme')
		expect(flowLabel('out')).toBe('Ausgabe')
		expect(flowLabel('')).toBeNull()
	})
})
