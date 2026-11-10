import { describe, expect, it } from 'vitest'
import { bulletGeometry, hasChildren, monthLabel, statusMeta, visibleRows } from './budgetVisual.js'

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
