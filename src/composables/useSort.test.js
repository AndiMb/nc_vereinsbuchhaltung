import { describe, it, expect, beforeEach } from 'vitest'
import { useSort, applySort } from './useSort.js'

// applySort steht hinter jeder sortierbaren Tabelle der App. Der Vergleich
// ist nicht trivial: Kontonummern sind Zeichenketten, sollen aber numerisch
// einsortiert werden, und ISO-Datumsangaben duerfen gerade nicht in die
// Locale-Sortierung geraten.

const { sort, toggleSort, sortArrow } = useSort()

describe('applySort', () => {
	it('gibt die Zeilen unveraendert zurueck, wenn keine Spalte gewaehlt ist', () => {
		const rows = [{ a: 2 }, { a: 1 }]
		expect(applySort(rows, null)).toBe(rows)
		expect(applySort(rows, {})).toBe(rows)
	})

	it('veraendert die uebergebene Liste nicht', () => {
		const rows = [{ a: 2 }, { a: 1 }]
		const sorted = applySort(rows, { key: 'a', dir: 'asc' })
		expect(rows[0].a).toBe(2)
		expect(sorted).not.toBe(rows)
	})

	it('sortiert Zahlen numerisch', () => {
		const rows = [{ n: 10 }, { n: 9 }, { n: 100 }]
		expect(applySort(rows, { key: 'n', dir: 'asc' }).map(r => r.n)).toEqual([9, 10, 100])
		expect(applySort(rows, { key: 'n', dir: 'desc' }).map(r => r.n)).toEqual([100, 10, 9])
	})

	it('sortiert Kontonummern numerisch, obwohl sie Zeichenketten sind', () => {
		// Rein lexikografisch stuende "1000" vor "900" - in einer Saldenliste
		// waere das die falsche Reihenfolge.
		const rows = [{ nr: '1000' }, { nr: '900' }, { nr: '4100' }]
		expect(applySort(rows, { key: 'nr', dir: 'asc' }).map(r => r.nr)).toEqual(['900', '1000', '4100'])
	})

	it('sortiert Umlaute nach deutscher Sortierung', () => {
		const rows = [{ s: 'Zuschuss' }, { s: 'Übertrag' }, { s: 'Anfang' }]
		expect(applySort(rows, { key: 's', dir: 'asc' }).map(r => r.s)).toEqual(['Anfang', 'Übertrag', 'Zuschuss'])
	})

	it('vergleicht als lexKeys benannte Felder rein zeichenweise', () => {
		// Bei ISO-Datumsangaben ist der Zeichenvergleich schon chronologisch;
		// die Locale-Sortierung mit numeric: true wuerde die Zahlengruppen
		// dagegen einzeln lesen.
		const rows = [{ d: '2026-01-31' }, { d: '2025-12-01' }, { d: '2026-01-05' }]
		const sorted = applySort(rows, { key: 'd', dir: 'asc' }, ['d'])
		expect(sorted.map(r => r.d)).toEqual(['2025-12-01', '2026-01-05', '2026-01-31'])
	})

	it('behandelt fehlende Werte als leer und stellt sie nach vorn', () => {
		const rows = [{ v: 'b' }, { v: null }, { v: 'a' }, {}]
		const sorted = applySort(rows, { key: 'v', dir: 'asc' })
		expect(sorted.slice(2).map(r => r.v)).toEqual(['a', 'b'])
	})
})

describe('toggleSort', () => {
	beforeEach(() => {
		sort.journal.key = 'entryNo'
		sort.journal.dir = 'desc'
	})

	it('dreht die Richtung, wenn dieselbe Spalte erneut gewaehlt wird', () => {
		toggleSort('journal', 'entryNo')
		expect(sort.journal.dir).toBe('asc')
		toggleSort('journal', 'entryNo')
		expect(sort.journal.dir).toBe('desc')
	})

	it('beginnt bei einer anderen Spalte aufsteigend', () => {
		toggleSort('journal', 'date')
		expect(sort.journal.key).toBe('date')
		expect(sort.journal.dir).toBe('asc')
	})

	it('laesst die anderen Tabellen unberuehrt', () => {
		const vorher = { ...sort.balances }
		toggleSort('journal', 'date')
		expect(sort.balances).toEqual(vorher)
	})
})

describe('sortArrow', () => {
	beforeEach(() => {
		sort.balances.key = 'number'
		sort.balances.dir = 'asc'
	})

	it('zeigt einen Pfeil nur an der sortierten Spalte', () => {
		expect(sortArrow('balances', 'number')).toBe(' ▲')
		expect(sortArrow('balances', 'name')).toBe('')
	})

	it('dreht den Pfeil mit der Richtung', () => {
		sort.balances.dir = 'desc'
		expect(sortArrow('balances', 'number')).toBe(' ▼')
	})
})
