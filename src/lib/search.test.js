import { describe, expect, it } from 'vitest'
import { buildSearchIndex, journalSearch, matchesQuery, parseAmountQuery, prepareQuery, transactionSearch } from './search.js'

describe('parseAmountQuery', () => {
	it.each([
		['12', 1200],
		['12,5', 1250],
		['12,50', 1250],
		['12.5', 1250],
		['12.50', 1250],
		['1.250', 125000],
		['1.250,5', 125050],
		['1.234.567,89', 123456789],
		['1250,00', 125000],
		['-12,50', 1250],
		['+12,50', 1250],
		['12,50 €', 1250],
		['€ 12,50', 1250],
		['0,99', 99],
	])('%s → %i Cent', (input, cents) => {
		expect(parseAmountQuery(input)).toBe(cents)
	})

	it.each(['', 'miete', '12a', '12,505', '1.25.0', '12,', ',5', '1.2345', '.5'])('%s ist kein Betrag', (input) => {
		expect(parseAmountQuery(input)).toBeNull()
	})
})

describe('matchesQuery', () => {
	const rows = [
		{ id: 1, entryNo: 12, description: 'Miete März', soll: '4210 Miete', haben: '1200 Bank', amount: 450, lines: [{ debitCents: 45000, creditCents: 0 }, { debitCents: 0, creditCents: 45000 }] },
		{ id: 2, entryNo: 40, description: 'Sommerfest', soll: '1200 Bank', haben: '8100 Erlöse, 8200 Spenden', amount: 12, lines: [{ debitCents: 1200, creditCents: 0 }, { debitCents: 0, creditCents: 700 }, { debitCents: 0, creditCents: 500 }] },
		{ id: 3, entryNo: 41, description: 'Getränke', soll: '4900 Sonstiges', haben: '1000 Kasse', amount: 1250, lines: [] },
	]
	const index = buildSearchIndex(rows, journalSearch.fields, journalSearch.cents)
	const hits = (q) => rows.filter((r) => matchesQuery(index.get(r.id), prepareQuery(q))).map((r) => r.id)

	it('ohne Suche passt alles', () => {
		expect(hits('')).toEqual([1, 2, 3])
		expect(hits('   ')).toEqual([1, 2, 3])
	})

	it('Text ohne Groß-/Kleinschreibung', () => {
		expect(hits('MIETE')).toEqual([1])
	})

	it('Zahl findet Betrag, Buchungsnummer und Text (ODER)', () => {
		// 12,00 € (Nr. 40), Nr. 12, „1200 Bank“ enthält „12“
		expect(hits('12')).toEqual([1, 2])
	})

	it('Teilbetrag einer Splittbuchung', () => {
		expect(hits('7,00')).toEqual([2])
	})

	it('Tausendertrenner', () => {
		expect(hits('1.250')).toEqual([3])
	})

	it('kein Treffer über Feldgrenzen hinweg', () => {
		expect(hits('märz4210')).toEqual([])
	})

	it('Bankumsatz: Betrag unabhängig vom Vorzeichen', () => {
		const txs = [{ id: 7, counterparty: 'Stadtwerke', purpose: 'Abschlag', bookingDate: '2026-03-01', amountCents: -4990 }]
		const txIndex = buildSearchIndex(txs, transactionSearch.fields, transactionSearch.cents)
		expect(matchesQuery(txIndex.get(7), prepareQuery('49,90'))).toBe(true)
		expect(matchesQuery(txIndex.get(7), prepareQuery('stadt'))).toBe(true)
		expect(matchesQuery(txIndex.get(7), prepareQuery('50'))).toBe(false)
	})
})
