import { describe, expect, it } from 'vitest'
import { bulletGeometry, hasChildren, monthLabel, periodMonths, statusMeta, trendDatasets, visibleRows } from './budgetVisual.js'

describe('bulletGeometry', () => {
	it('füllt anteilig und setzt die Zeitmarke', () => {
		expect(bulletGeometry(100000, 60000, 0.5)).toEqual({ fill: 60, marker: 50, over: false })
	})

	it('kappt bei Überschreitung auf 100 und markiert sie', () => {
		expect(bulletGeometry(100000, 150000, 1)).toEqual({ fill: 100, marker: 100, over: true })
	})

	it('negatives Ist füllt nichts', () => {
		expect(bulletGeometry(100000, -5000, 0.5)).toEqual({ fill: 0, marker: 50, over: false })
	})

	it('ohne Soll: Ist über null ist Überschreitung, sonst leer, nie eine Marke', () => {
		expect(bulletGeometry(0, 5000, 0.5)).toEqual({ fill: 100, marker: null, over: true })
		expect(bulletGeometry(0, 0, 0.5)).toEqual({ fill: 0, marker: null, over: false })
		expect(bulletGeometry(-100, 0, 0.5)).toEqual({ fill: 0, marker: null, over: false })
	})

	it('ohne verwertbaren Zeitanteil keine Marke', () => {
		expect(bulletGeometry(100000, 60000, 0)).toEqual({ fill: 60, marker: null, over: false })
		expect(bulletGeometry(100000, 60000, null)).toEqual({ fill: 60, marker: null, over: false })
		expect(bulletGeometry(100000, 60000, NaN)).toEqual({ fill: 60, marker: null, over: false })
		expect(bulletGeometry(100000, 60000, Infinity)).toEqual({ fill: 60, marker: null, over: false })
	})

	it('kappt die Marke auf 0 bis 100', () => {
		expect(bulletGeometry(100000, 0, 1.5).marker).toBe(100)
	})

	it('liefert nie NaN', () => {
		const g = bulletGeometry(undefined, undefined, undefined)
		expect(Number.isNaN(g.fill)).toBe(false)
		expect(g).toEqual({ fill: 0, marker: null, over: false })
	})
})

describe('statusMeta', () => {
	it('unterscheidet ungeplant nach Art', () => {
		expect(statusMeta('unplanned', 'income').tone).toBe('good')
		expect(statusMeta('unplanned', 'expense').tone).toBe('serious')
	})

	it('liefert Symbol, Text und Ton je Stufe und Art', () => {
		expect(statusMeta('good', 'expense')).toEqual({ icon: '✓', label: 'im Rahmen', tone: 'good' })
		expect(statusMeta('good', 'income').label).toBe('im Plan')
		expect(statusMeta('good', 'result').label).toBe('im Plan')
		expect(statusMeta('warn', 'expense')).toEqual({ icon: '!', label: 'beobachten', tone: 'warn' })
		expect(statusMeta('warn', 'income').label).toBe('beobachten')
		expect(statusMeta('warn', 'result').label).toBe('leicht unter Plan')
		expect(statusMeta('crit', 'expense')).toEqual({ icon: '✕', label: 'überschritten', tone: 'crit' })
		expect(statusMeta('crit', 'income').label).toBe('unter Plan')
		expect(statusMeta('crit', 'result').label).toBe('deutlich unter Plan')
		expect(statusMeta('unplanned', 'expense')).toEqual({ icon: '?', label: 'ungeplant', tone: 'serious' })
		expect(statusMeta('unplanned', 'result').label).toBe('–')
		expect(statusMeta('none', 'income')).toEqual({ icon: '–', label: 'ohne Werte', tone: 'none' })
	})

	it('behandelt unbekannte Stufen wie none', () => {
		expect(statusMeta('foo', 'expense').tone).toBe('none')
	})
})

const status = (level) => ({ year: { level, ratio: 1 }, time: { level, ratio: 1 } })

const rows = [
	{ kind: 'heading', depth: 0, label: 'Ideeller Bereich' },
	{ kind: 'account', depth: 1, label: 'A', nature: 'expense', status: status('crit') },
	{ kind: 'account', depth: 2, label: 'B', nature: 'expense', status: status('crit') },
	{ kind: 'account', depth: 2, label: 'C', nature: 'income', status: status('crit') },
	{ kind: 'account', depth: 1, label: 'D', nature: 'expense', status: status('good') },
	{ kind: 'footer', depth: 0, label: 'Summe', nature: 'expense', status: status('crit') },
]

describe('visibleRows', () => {
	it('liefert ohne Zugeklapptes und Filter alle Zeilen mit Index', () => {
		const out = visibleRows(rows, new Set(), null, 'year')
		expect(out.map((e) => e.index)).toEqual([0, 1, 2, 3, 4, 5])
		expect(out[2].row).toBe(rows[2])
	})

	it('klappt untergeordnete Kontozeilen ein', () => {
		const out = visibleRows(rows, new Set([1]), null, 'year')
		expect(out.map((e) => e.index)).toEqual([0, 1, 4, 5])
	})

	it('Zuklappen endet an einer Nicht-Kontozeile', () => {
		const withLine = [
			{ kind: 'account', depth: 1, label: 'A' },
			{ kind: 'line', depth: 2, label: 'L' },
			{ kind: 'account', depth: 2, label: 'B' },
		]
		expect(visibleRows(withLine, new Set([0]), null, 'year').map((e) => e.index)).toEqual([0, 1, 2])
	})

	it('filtert auf Art und Stufe der Basis, Überschriften mit Treffer bleiben', () => {
		const out = visibleRows(rows, new Set(), { nature: 'expense', level: 'crit' }, 'year')
		expect(out.map((e) => e.index)).toEqual([0, 1, 2])
	})

	it('lässt Überschriften ohne Treffer weg', () => {
		expect(visibleRows(rows, new Set(), { nature: 'income', level: 'good' }, 'year')).toEqual([])
	})

	it('zeigt jedes Konto nur beim ersten Auftreten', () => {
		const twice = [
			{ kind: 'heading', depth: 0, label: 'Abschnitt' },
			{ kind: 'account', depth: 1, accountId: 7, label: 'A', nature: 'expense', status: status('crit') },
			{ kind: 'heading', depth: 0, label: 'Zusammenfassung' },
			{ kind: 'account', depth: 1, accountId: 7, label: 'A', nature: 'expense', status: status('crit') },
			{ kind: 'account', depth: 1, accountId: 8, label: 'B', nature: 'expense', status: status('crit') },
		]
		expect(visibleRows(twice, new Set(), { nature: 'expense', level: 'crit' }, 'year').map((e) => e.index)).toEqual([0, 1, 2, 4])
		const onlyTwice = twice.slice(0, 4)
		expect(visibleRows(onlyTwice, new Set(), { nature: 'expense', level: 'crit' }, 'year').map((e) => e.index)).toEqual([0, 1])
	})

	it('behält die Vorfahren einer tieferen Überschrift mit Treffer', () => {
		const nested = [
			{ kind: 'heading', depth: 0, label: 'Einnahmen' },
			{ kind: 'heading', depth: 1, label: 'Ideeller Bereich' },
			{ kind: 'account', depth: 2, accountId: 1, label: 'A', nature: 'income', status: status('good') },
			{ kind: 'heading', depth: 1, label: 'Projekte' },
			{ kind: 'account', depth: 2, accountId: 2, label: 'B', nature: 'income', status: status('crit') },
			{ kind: 'heading', depth: 0, label: 'Ausgaben' },
			{ kind: 'account', depth: 1, accountId: 3, label: 'C', nature: 'expense', status: status('crit') },
		]
		expect(visibleRows(nested, new Set(), { nature: 'income', level: 'crit' }, 'year').map((e) => e.index)).toEqual([0, 3, 4])
	})

	it('nutzt die gewählte Basis', () => {
		const r = [
			{ kind: 'account', depth: 1, nature: 'expense', status: { year: { level: 'good' }, time: { level: 'crit' } } },
		]
		expect(visibleRows(r, new Set(), { nature: 'expense', level: 'crit' }, 'year')).toEqual([])
		expect(visibleRows(r, new Set(), { nature: 'expense', level: 'crit' }, 'time')).toHaveLength(1)
	})

	it('ignoriert Zugeklapptes bei aktivem Filter', () => {
		const out = visibleRows(rows, new Set([1]), { nature: 'expense', level: 'crit' }, 'year')
		expect(out.map((e) => e.index)).toEqual([0, 1, 2])
	})
})

describe('hasChildren', () => {
	it('erkennt eine tiefere Kontozeile als nächste Zeile', () => {
		expect(hasChildren(rows, 1)).toBe(true)
		expect(hasChildren(rows, 4)).toBe(false)
		expect(hasChildren(rows, 5)).toBe(false)
		expect(hasChildren(rows, 2)).toBe(false)
	})
})

describe('monthLabel', () => {
	it('benennt den Monat deutsch', () => {
		expect(monthLabel('2026-10', 'de')).toBe('Oktober 2026')
	})

	it('benennt den Monat englisch', () => {
		expect(monthLabel('2026-10', 'en')).toBe('October 2026')
	})
})

describe('periodMonths', () => {
	it('zählt die Monate ab einem Beginn mitten im Jahr über den Jahreswechsel', () => {
		const months = periodMonths('2026-07-01', 12)
		expect(months).toHaveLength(12)
		expect(months.slice(0, 2)).toEqual(['2026-07', '2026-08'])
		expect(months.slice(5, 7)).toEqual(['2026-12', '2027-01'])
		expect(months[11]).toBe('2027-06')
	})

	it('liefert für ein Rumpfjahr nur dessen Monate, auch bei Beginn mitten im Monat', () => {
		expect(periodMonths('2026-10-15', 3)).toEqual(['2026-10', '2026-11', '2026-12'])
	})

	it('ohne Monate eine leere Liste', () => {
		expect(periodMonths('2026-01-01', 0)).toEqual([])
	})
})

describe('trendDatasets', () => {
	const series = { months: ['2026-01', '2026-02', '2026-03'], income: [100, 200, 300], expense: [50, 150, 150] }

	it('Ist bis zum letzten Monat der Reihe, Hochrechnung ab dort, Plan linear', () => {
		const d = trendDatasets(series, 1200, 600, 12, 1200, 600)
		expect(d.labels).toHaveLength(12)
		expect(d.labels[0]).toBe('2026-01')
		expect(d.labels[11]).toBe('2026-12')
		expect(d.income.slice(0, 3)).toEqual([100, 200, 300])
		expect(d.income[3]).toBeNull()
		expect(d.income).toHaveLength(12)
		expect(d.incomeForecast.slice(0, 2)).toEqual([null, null])
		expect(d.incomeForecast[2]).toBe(300)
		expect(d.incomeForecast[3]).toBe(400)
		expect(d.incomeForecast[11]).toBe(1200)
		expect(d.incomePlan[0]).toBe(100)
		expect(d.incomePlan[11]).toBe(1200)
		expect(d.expense.slice(0, 3)).toEqual([50, 150, 150])
		expect(d.expenseForecast[2]).toBe(150)
		expect(d.expenseForecast[11]).toBe(600)
		expect(d.expensePlan[5]).toBe(300)
		expect(d.expensePlan[11]).toBe(600)
	})

	it('rundet auf ganze Cent', () => {
		const d = trendDatasets({ months: ['2026-01'], income: [100], expense: [0] }, 1000, 0, 4, 400, 1)
		expect(d.incomePlan).toEqual([250, 500, 750, 1000])
		expect(d.expensePlan).toEqual([0, 0, 0, 0])
		expect(d.incomeForecast).toEqual([100, 200, 300, 400])
		expect(d.expenseForecast).toEqual([0, 0, 1, 1])
	})

	it('ohne Ist-Monate nur Plan-Linien', () => {
		const d = trendDatasets({ months: [], income: [], expense: [] }, 1200, 600, 12, null, null)
		expect(d.labels).toHaveLength(12)
		for (const key of ['income', 'expense', 'incomeForecast', 'expenseForecast']) {
			expect(d[key]).toHaveLength(12)
			expect(d[key].every((v) => v === null)).toBe(true)
		}
		expect(d.incomePlan[11]).toBe(1200)
	})

	it('übernimmt den Endwert der Hochrechnung, statt ihn aus der Reihe zu rechnen', () => {
		const d = trendDatasets(series, 1200, 600, 12, 1290, 600)
		expect(d.incomeForecast[2]).toBe(300)
		expect(d.incomeForecast[3]).toBe(410)
		expect(d.incomeForecast[11]).toBe(1290)
	})

	it('ohne Hochrechnung (null) bleibt deren Reihe leer, das Ist nicht', () => {
		const d = trendDatasets(series, 1200, 600, 12, null, 600)
		expect(d.incomeForecast.every((v) => v === null)).toBe(true)
		expect(d.income.slice(0, 3)).toEqual([100, 200, 300])
		expect(d.expenseForecast[11]).toBe(600)
	})

	it('Reihe deckt die ganze Periode: keine Hochrechnung', () => {
		const d = trendDatasets({ months: ['2026-01', '2026-02'], income: [100, 300], expense: [10, 20] }, 0, 0, 2, 300, 20)
		expect(d.income).toEqual([100, 300])
		expect(d.incomeForecast).toEqual([null, null])
		expect(d.expenseForecast).toEqual([null, null])
	})

	it('Reihe über den Stand hinaus (vordatierte Buchungen): Ist wird nicht verworfen', () => {
		const long = { months: ['2026-01', '2026-02', '2026-03', '2026-04'], income: [100, 200, 300, 500], expense: [0, 0, 0, 0] }
		const d = trendDatasets(long, 1200, 0, 6, 900, 0)
		expect(d.income).toEqual([100, 200, 300, 500, null, null])
		expect(d.incomeForecast).toEqual([null, null, null, 500, 700, 900])
	})
})
