import { describe, expect, it } from 'vitest'
import { accountCategoryLabel, journalRowAccountIds, journalRowMatchesAccountFilter } from './accountFilter.js'

// Der Kontofilter des Journals kennt zwei Formen: ein einzelnes Konto oder
// eine ganze Kategorie. Beide muessen auch Splitbuchungen treffen, deren
// weitere Zeilen nicht in debitAccountId/creditAccountId stehen.

const t = (s) => s

const accountsById = {
	1: { id: 1, number: '1200', name: 'Bankkonto', category: 'Geldkonten' },
	2: { id: 2, number: '4000', name: 'Mitgliedsbeiträge', category: 'Einnahmen' },
	3: { id: 3, number: '4100', name: 'Spenden', category: 'Einnahmen' },
	4: { id: 4, number: '5000', name: 'Raumkosten', category: 'Ausgaben' },
	5: { id: 5, number: '9999', name: 'Ohne Kategorie', category: null },
}

function row({ debit, credit, lines = null }) {
	return {
		debitAccountId: debit,
		creditAccountId: credit,
		lines: lines ?? [
			{ accountId: debit, debitCents: 100, creditCents: 0 },
			{ accountId: credit, debitCents: 0, creditCents: 100 },
		],
	}
}

describe('accountCategoryLabel', () => {
	it('nimmt die Kategorie des Kontos', () => {
		expect(accountCategoryLabel(accountsById[2], t)).toBe('Einnahmen')
	})

	it('faellt ohne Kategorie auf "Sonstige" zurueck', () => {
		expect(accountCategoryLabel(accountsById[5], t)).toBe('Sonstige')
		expect(accountCategoryLabel({ category: '' }, t)).toBe('Sonstige')
	})
})

describe('journalRowAccountIds', () => {
	it('liefert alle Zeilenkonten einer Splitbuchung', () => {
		const r = row({ debit: 1, credit: 2, lines: [
			{ accountId: 1, debitCents: 300, creditCents: 0 },
			{ accountId: 2, debitCents: 0, creditCents: 100 },
			{ accountId: 3, debitCents: 0, creditCents: 200 },
		] })
		expect(journalRowAccountIds(r)).toEqual([1, 2, 3])
	})

	it('faellt ohne Zeilen auf Soll/Haben zurueck und laesst leere Seiten weg', () => {
		expect(journalRowAccountIds({ debitAccountId: 1, creditAccountId: null, lines: [] })).toEqual([1])
		expect(journalRowAccountIds({ debitAccountId: 1, creditAccountId: 2 })).toEqual([1, 2])
	})
})

describe('journalRowMatchesAccountFilter', () => {
	const beitrag = row({ debit: 1, credit: 2 })
	const miete = row({ debit: 4, credit: 1 })
	const sonstiges = row({ debit: 1, credit: 5 })

	it('laesst ohne Filter alles durch', () => {
		expect(journalRowMatchesAccountFilter(beitrag, null, accountsById, t)).toBe(true)
	})

	it('filtert nach einem einzelnen Konto auf beiden Seiten', () => {
		expect(journalRowMatchesAccountFilter(beitrag, { accountId: 2 }, accountsById, t)).toBe(true)
		expect(journalRowMatchesAccountFilter(beitrag, { accountId: 1 }, accountsById, t)).toBe(true)
		expect(journalRowMatchesAccountFilter(beitrag, { accountId: 4 }, accountsById, t)).toBe(false)
	})

	it('filtert nach Kategorie ueber alle Konten der Kategorie', () => {
		const einnahmen = { category: 'Einnahmen' }
		expect(journalRowMatchesAccountFilter(beitrag, einnahmen, accountsById, t)).toBe(true)
		expect(journalRowMatchesAccountFilter(row({ debit: 1, credit: 3 }), einnahmen, accountsById, t)).toBe(true)
		expect(journalRowMatchesAccountFilter(miete, einnahmen, accountsById, t)).toBe(false)
		expect(journalRowMatchesAccountFilter(miete, { category: 'Ausgaben' }, accountsById, t)).toBe(true)
	})

	it('trifft Splitbuchungen auch ueber die weiteren Zeilen', () => {
		const split = row({ debit: 1, credit: 2, lines: [
			{ accountId: 1, debitCents: 300, creditCents: 0 },
			{ accountId: 2, debitCents: 0, creditCents: 100 },
			{ accountId: 4, debitCents: 0, creditCents: 200 },
		] })
		expect(journalRowMatchesAccountFilter(split, { accountId: 4 }, accountsById, t)).toBe(true)
		expect(journalRowMatchesAccountFilter(split, { category: 'Ausgaben' }, accountsById, t)).toBe(true)
	})

	it('ordnet Konten ohne Kategorie der Gruppe "Sonstige" zu', () => {
		expect(journalRowMatchesAccountFilter(sonstiges, { category: 'Sonstige' }, accountsById, t)).toBe(true)
		expect(journalRowMatchesAccountFilter(beitrag, { category: 'Sonstige' }, accountsById, t)).toBe(false)
	})

	it('ignoriert unbekannte Konten statt zu werfen', () => {
		const fremd = row({ debit: 1, credit: 42 })
		expect(journalRowMatchesAccountFilter(fremd, { category: 'Einnahmen' }, accountsById, t)).toBe(false)
	})
})
