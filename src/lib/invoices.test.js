import { describe, expect, it } from 'vitest'
import {
	addDays,
	displayStatus,
	editorLineNet,
	editorTotals,
	emptyLine,
	formatNumberPattern,
	formatQuantity,
	isEmptyLine,
	lineNet,
	linesToPayload,
	parseDecimal,
	quantityToE4,
	roundDiv,
	totals,
} from './invoices.js'

describe('roundDiv', () => {
	it.each([[10, 5, 2], [14, 10, 1], [15, 10, 2], [-15, 10, -2], [-14, 10, -1], [15, -10, -2]])('%i / %i = %i', (n, d, expected) => {
		expect(roundDiv(n, d)).toBe(expected)
	})
})

describe('lineNet (wie InvoiceCalculator.php)', () => {
	it.each([
		[10000, 2500, 0, 2500],
		[30000, 1999, 0, 5997],
		[15000, 4000, 0, 6000],
		[2500, 5999, 0, 1500],
		[10000, 1000, 1000, 900],
		[10000, 1005, 1000, 904],
		[-20000, 1500, 0, -3000],
		[10000, 500, 10000, 0],
	])('Menge %i × Preis %i, Rabatt %i → %i', (q, p, d, expected) => {
		expect(lineNet(q, p, d)).toBe(expected)
	})
})

describe('totals', () => {
	it('summiert ohne Steuerausweis', () => {
		expect(totals([{ lineNetCents: 1000 }, { lineNetCents: 550 }])).toEqual({ netCents: 1550, taxCents: 0, grossCents: 1550 })
	})
	it('leer ist null', () => {
		expect(totals([]).grossCents).toBe(0)
	})
})

describe('parseDecimal', () => {
	it.each([
		['1,5', 1.5],
		['1.234,5', 1234.5],
		['2.5', 2.5],
		['1.234.567', 1234567],
		['  3  ', 3],
		['-2,25', -2.25],
		[4, 4],
	])('liest %s', (text, expected) => {
		expect(parseDecimal(text)).toBe(expected)
	})
	it.each(['', 'abc', '1,2,3', '--1', null, undefined])('lehnt %s ab', (text) => {
		expect(parseDecimal(text)).toBeNull()
	})
})

describe('Menge', () => {
	it('rechnet in Zehntausendstel', () => {
		expect(quantityToE4('3,5')).toBe(35000)
		expect(quantityToE4('0,0001')).toBe(1)
		expect(quantityToE4('x')).toBeNull()
	})
	it('formatiert ohne überflüssige Nullen', () => {
		expect(formatQuantity(35000)).toBe('3,5')
		expect(formatQuantity(10000)).toBe('1')
		expect(formatQuantity(12500)).toBe('1,25')
	})
})

describe('Editor', () => {
	const line = (over) => ({ ...emptyLine(), name: 'x', unitPrice: 25.5, quantity: '3', ...over })

	it('rechnet eine Zeile wie der Server', () => {
		expect(editorLineNet(line())).toBe(7650)
		expect(editorLineNet(line({ quantity: '1,5', discountPercent: '10' }))).toBe(3442)
	})
	it('meldet unlesbare Mengen als null', () => {
		expect(editorLineNet(line({ quantity: 'viel' }))).toBeNull()
	})
	it('behandelt eine leere Menge als 1', () => {
		expect(editorLineNet(line({ quantity: '' }))).toBe(2550)
	})
	it('erkennt leere Zeilen', () => {
		expect(isEmptyLine(emptyLine())).toBe(true)
		expect(isEmptyLine(line())).toBe(false)
	})
	it('summiert ohne leere Zeilen und meldet Fehler', () => {
		expect(editorTotals([line(), emptyLine()])).toEqual({ netCents: 7650, taxCents: 0, grossCents: 7650, invalid: false })
		expect(editorTotals([line({ quantity: 'viel' })]).invalid).toBe(true)
	})
	it('bereitet die Positionen für den Server auf', () => {
		const payload = linesToPayload([line({ discountPercent: '10', accountId: 4 }), emptyLine()])
		expect(payload).toEqual([{
			name: 'x',
			description: '',
			quantity: '3',
			unitCode: 'C62',
			unitPriceCents: 2550,
			discountPercent: '10',
			accountId: 4,
		}])
	})
	it('gibt jeder Zeile einen eigenen Schlüssel', () => {
		expect(emptyLine().key).not.toBe(emptyLine().key)
	})
})

describe('Datum', () => {
	it('addiert Tage über Monatsgrenzen', () => {
		expect(addDays('2026-03-05', 14)).toBe('2026-03-19')
		expect(addDays('2026-12-25', 14)).toBe('2027-01-08')
		expect(addDays('2026-03-05', 0)).toBe('2026-03-05')
	})
})

describe('displayStatus', () => {
	it('Stornorechnung geht vor allem', () => {
		expect(displayStatus({ kind: 'storno', status: 'issued' })).toBe('credit')
	})
	it('überfällig und Teilzahlung', () => {
		expect(displayStatus({ kind: 'rechnung', status: 'sent', overdue: true })).toBe('overdue')
		expect(displayStatus({ kind: 'rechnung', status: 'issued', settledCents: 500, remainingCents: 100 })).toBe('partial')
		expect(displayStatus({ kind: 'rechnung', status: 'paid', settledCents: 500, remainingCents: 0 })).toBe('paid')
	})
})

describe('formatNumberPattern', () => {
	const date = new Date(2026, 2, 5)
	it('setzt Jahr und Zähler ein', () => {
		expect(formatNumberPattern('RE-{JJJJ}-{NNNN}', date, 42)).toBe('RE-2026-0042')
		expect(formatNumberPattern('{JJ}/{NNN}', date, 7)).toBe('26/007')
		expect(formatNumberPattern('R{NNNNN}', date, 123456)).toBe('R123456')
	})
})
