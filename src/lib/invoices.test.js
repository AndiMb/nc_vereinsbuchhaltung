import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
	addDays,
	checklistTarget,
	defaultPlanName,
	displayStatus,
	dueDaysOf,
	editorLineNet,
	editorTotals,
	eInvoiceDocuments,
	emptyLine,
	formatNumberPattern,
	formatQuantity,
	invoiceSortFields,
	isDunningDue,
	isEmptyLine,
	lineFromPlan,
	lineFromTemplate,
	lineNet,
	linesToPayload,
	missingEInvoiceFormats,
	parseDecimal,
	planIntervalLabel,
	planLinesFromInvoice,
	quantityToE4,
	roundDiv,
	suggestTaxKey,
	taxKeyOf,
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
		expect(totals([{ lineNetCents: 1000 }, { lineNetCents: 550 }])).toEqual({ netCents: 1550, taxCents: 0, grossCents: 1550, taxBreakdown: [] })
	})
	it('leer ist null', () => {
		expect(totals([]).grossCents).toBe(0)
	})
	it('rechnet die Steuer je Satz einmal aus der Summe (wie der Server)', () => {
		const result = totals([
			{ lineNetCents: 333, taxCategory: 'S', taxRateBp: 1900 },
			{ lineNetCents: 333, taxCategory: 'S', taxRateBp: 1900 },
			{ lineNetCents: 1000, taxCategory: 'S', taxRateBp: 700 },
			{ lineNetCents: 200, taxCategory: 'E', taxRateBp: 0 },
		], true)
		// 666 × 19 % = 126,54 → 127; 1000 × 7 % = 70; befreit bleibt ohne Steuer.
		expect(result.netCents).toBe(1866)
		expect(result.taxCents).toBe(197)
		expect(result.grossCents).toBe(2063)
		expect(result.taxBreakdown).toEqual([{ rateBp: 1900, baseCents: 666, taxCents: 127 }, { rateBp: 700, baseCents: 1000, taxCents: 70 }])
	})
	it('ignoriert die Steuer, solange keine Regelbesteuerung gilt', () => {
		expect(totals([{ lineNetCents: 1000, taxCategory: 'S', taxRateBp: 1900 }], false).taxCents).toBe(0)
	})
})

describe('Steuerauswahl', () => {
	it('findet den Schlüssel zu Kategorie und Satz', () => {
		expect(taxKeyOf('S', 1900)).toBe('S1900')
		expect(taxKeyOf('S', 700)).toBe('S700')
		expect(taxKeyOf('E', 0)).toBe('E')
		expect(taxKeyOf('Z', 0)).toBe('Z')
	})
	it('schlägt den Satz nach der Sphäre des Erlöskontos vor', () => {
		const rates = { zweckbetrieb: 700, wirtschaftlich: 1900 }
		expect(suggestTaxKey({ sphere: 'zweckbetrieb' }, rates)).toBe('S700')
		expect(suggestTaxKey({ sphere: 'wirtschaftlich' }, rates)).toBe('S1900')
		expect(suggestTaxKey({ sphere: null }, rates)).toBe('S1900')
		expect(suggestTaxKey(null, rates)).toBe('S1900')
		expect(suggestTaxKey({ sphere: 'zweckbetrieb' }, { zweckbetrieb: 0, wirtschaftlich: 1900 })).toBe('Z')
	})
	it('schickt die Steuer nur bei Regelbesteuerung zum Server', () => {
		const line = { ...emptyLine(4, 'S700'), name: 'x', unitPrice: 10 }
		expect(linesToPayload([line])[0]).not.toHaveProperty('taxCategory')
		expect(linesToPayload([line], true)[0]).toMatchObject({ taxCategory: 'S', taxRateBp: 700 })
	})
	it('summiert die Editor-Zeilen mit Steuer', () => {
		const a = { ...emptyLine(4, 'S1900'), name: 'a', unitPrice: 100 }
		const b = { ...emptyLine(4, 'E'), name: 'b', unitPrice: 50 }
		expect(editorTotals([a, b], true)).toMatchObject({ netCents: 15000, taxCents: 1900, grossCents: 16900 })
		expect(editorTotals([a, b], false)).toMatchObject({ netCents: 15000, taxCents: 0, grossCents: 15000 })
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
		expect(editorTotals([line(), emptyLine()])).toEqual({ netCents: 7650, taxCents: 0, grossCents: 7650, taxBreakdown: [], invalid: false })
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

describe('E-Rechnung', () => {
	it('bietet nur Formate an, die es noch nicht gibt', () => {
		expect(missingEInvoiceFormats([])).toEqual(['zugferd', 'xrechnung_cii', 'xrechnung_ubl'])
		expect(missingEInvoiceFormats([{ kind: 'pdf' }, { kind: 'zugferd_pdf' }])).toEqual(['xrechnung_cii', 'xrechnung_ubl'])
		expect(missingEInvoiceFormats(undefined)).toHaveLength(3)
	})
	it('trennt E-Rechnungen vom einfachen PDF', () => {
		expect(eInvoiceDocuments([{ kind: 'pdf' }, { kind: 'dunning' }, { kind: 'xrechnung_ubl' }])).toEqual([{ kind: 'xrechnung_ubl' }])
	})
})

describe('Positionsvorlagen', () => {
	it('übernimmt eine Vorlage als Kopie in eine Editor-Zeile', () => {
		const line = lineFromTemplate({ name: 'Hallenmiete', description: 'pro Stunde', unitCode: 'HUR', unitPriceCents: 4550, taxCategory: 'S', taxRateBp: 700, accountId: 8 })
		expect(line).toMatchObject({ name: 'Hallenmiete', description: 'pro Stunde', unitCode: 'HUR', unitPrice: 45.5, accountId: 8, taxKey: 'S700', quantity: '1', taxTouched: true })
		expect(editorLineNet(line)).toBe(4550)
	})
	it('lässt den Preis bei einer Vorlage ohne Preis leer', () => {
		expect(lineFromTemplate({ name: 'Spende', unitCode: 'LS', unitPriceCents: 0, taxCategory: 'E', taxRateBp: 0, accountId: null })).toMatchObject({ unitPrice: '', taxKey: 'E', accountId: null })
	})
})

describe('Wiederkehrende Rechnungen', () => {
	it('bereitet die Positionen einer Rechnung für den Plan auf', () => {
		const lines = planLinesFromInvoice({ lines: [{ name: 'Miete', description: null, quantityE4: 15000, unitCode: 'MON', unitPriceCents: 12000, discountBp: 1050, accountId: 4, taxCategory: 'S', taxRateBp: 1900 }] })
		expect(lines).toEqual([{ name: 'Miete', description: '', quantity: '1,5', unitCode: 'MON', unitPriceCents: 12000, discountPercent: '10,5', accountId: 4, taxCategory: 'S', taxRateBp: 1900 }])
	})
	it('liest das Zahlungsziel in Tagen aus der Rechnung', () => {
		expect(dueDaysOf({ issueDate: '2026-03-05', dueDate: '2026-03-19' })).toBe(14)
		expect(dueDaysOf({ issueDate: '2026-12-20', dueDate: '2027-01-03' })).toBe(14)
		expect(dueDaysOf({ issueDate: '2026-03-05' })).toBeNull()
	})
	it('benennt den Takt', () => {
		expect(planIntervalLabel(1)).toBe('monatlich')
		expect(planIntervalLabel(12)).toBe('jährlich')
	})
})

describe('Mahnwesen', () => {
	it('erkennt eine fällige Mahnstufe', () => {
		expect(isDunningDue({ dunning: { due: true } })).toBe(true)
		expect(isDunningDue({ dunning: { due: false } })).toBe(false)
		expect(isDunningDue({})).toBe(false)
	})
})

describe('Plan im Editor', () => {
	it('macht aus einer gespeicherten Plan-Position eine Editor-Zeile', () => {
		const line = lineFromPlan({ name: 'Miete', description: null, quantity: '1,5', unitCode: 'MON', unitPriceCents: 12000, discountPercent: '10', accountId: 4, taxCategory: 'S', taxRateBp: 700 })
		expect(line).toMatchObject({ name: 'Miete', description: '', quantity: '1,5', unitCode: 'MON', unitPrice: 120, discountPercent: '10', accountId: 4, taxKey: 'S700' })
		expect(editorLineNet(line)).toBe(16200)
	})
	it('liest Mengen als Zahl und fehlende Angaben mit Standardwerten', () => {
		expect(lineFromPlan({ name: 'x', quantity: 2, unitPriceCents: 0 })).toMatchObject({ quantity: '2', unitPrice: '', unitCode: 'C62', taxKey: 'S1900' })
	})
	it('nennt einen Plan nach Empfänger und erster Position', () => {
		expect(defaultPlanName('Studio GmbH', [{ name: ' ' }, { name: 'Hallenmiete' }])).toBe('Studio GmbH – Hallenmiete')
		expect(defaultPlanName('', [])).toBe('')
	})
	it('übernimmt eine Plan-Position unverändert in die Server-Form zurück', () => {
		const stored = { name: 'Miete', description: '', quantity: '1', unitCode: 'LS', unitPriceCents: 12000, discountPercent: '', accountId: 4 }
		expect(linesToPayload([lineFromPlan(stored)])[0]).toMatchObject({ name: 'Miete', quantity: '1', unitCode: 'LS', unitPriceCents: 12000, accountId: 4 })
	})
})

describe('Ziele der Prüfliste (Issue #204)', () => {
	it('führt Vereins-, Steuer- und Bankangaben in die Einstellungen', () => {
		expect(checklistTarget('bank')).toEqual({ settings: 'rechnungen' })
		expect(checklistTarget('issuer')).toEqual({ settings: 'rechnungen' })
		expect(checklistTarget('clubSuffix')).toEqual({ settings: 'verein' })
	})

	it('führt Angaben der Rechnung zum Bereich im Editor', () => {
		expect(checklistTarget('debtor')).toEqual({ field: 'debtor' })
		expect(checklistTarget('amount')).toEqual({ field: 'lines' })
		expect(checklistTarget('exemption')).toEqual({ field: 'exemption' })
	})

	it('hat für jeden Punkt der Prüfliste des Servers ein Ziel', () => {
		const php = readFileSync(new URL('../../lib/Service/InvoiceService.php', import.meta.url), 'utf8')
		const keys = [...php.matchAll(/\$add\('([a-zA-Z]+)'/g)].map((m) => m[1])
		expect(keys.length).toBeGreaterThan(5)
		expect(keys.filter((k) => checklistTarget(k) === null)).toEqual([])
	})

	it('kennt keinen unbekannten Schlüssel', () => {
		expect(checklistTarget('gibtsnicht')).toBeNull()
	})
})

describe('Sortierwerte der Rechnungsliste (Issue #213)', () => {
	it('nimmt bei Entwürfen das Anlagedatum und lässt die Nummer leer', () => {
		expect(invoiceSortFields({ number: null, issueDate: null, createdAt: '2026-10-29 10:00:00', status: 'draft' }))
			.toMatchObject({ number: '', date: '2026-10-29 10:00:00', status: 'Entwurf' })
	})

	it('gibt Stornorechnungen keine Fälligkeit und keinen negativen Rest', () => {
		expect(invoiceSortFields({ kind: 'storno', dueDate: '2026-11-12', grossCents: -5000, remainingCents: -5000, status: 'issued' }))
			.toMatchObject({ due: '', gross: -5000, remaining: 0, status: 'Stornorechnung' })
	})

	it('sortiert den Status nach dem angezeigten Text', () => {
		expect(invoiceSortFields({ status: 'issued', overdue: true }).status).toBe('Überfällig')
	})
})
