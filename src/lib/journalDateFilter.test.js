import { describe, expect, it } from 'vitest'
import { journalRowInDateRange, parseDateParam } from './journalDateFilter.js'

describe('parseDateParam', () => {
	it('nimmt ein Kalenderdatum JJJJ-MM-TT', () => {
		expect(parseDateParam('2026-09-30')).toBe('2026-09-30')
		expect(parseDateParam('2024-02-29')).toBe('2024-02-29')
	})

	it('verwirft alles andere', () => {
		for (const raw of [undefined, null, '', '2026-9-30', '30.09.2026', '2026-09-31', '2026-13-01', '2025-02-29', '2026-09-30T00:00', ['2026-09-30'], 20260930]) {
			expect(parseDateParam(raw)).toBeNull()
		}
	})
})

describe('journalRowInDateRange', () => {
	const row = { date: '2026-09-30' }

	it('ohne Grenzen passt jede Buchung', () => {
		expect(journalRowInDateRange(row, null, null)).toBe(true)
	})

	it('beide Grenzen zählen mit', () => {
		expect(journalRowInDateRange(row, '2026-09-30', null)).toBe(true)
		expect(journalRowInDateRange(row, null, '2026-09-30')).toBe(true)
		expect(journalRowInDateRange(row, '2026-09-30', '2026-09-30')).toBe(true)
	})

	it('außerhalb der Spanne passt die Buchung nicht', () => {
		expect(journalRowInDateRange(row, '2026-10-01', null)).toBe(false)
		expect(journalRowInDateRange(row, null, '2026-09-29')).toBe(false)
		expect(journalRowInDateRange(row, '2026-01-01', '2026-08-31')).toBe(false)
	})

	it('nur „bis“: alles bis einschließlich des Tages', () => {
		expect(journalRowInDateRange({ date: '2026-01-01' }, null, '2026-09-30')).toBe(true)
		expect(journalRowInDateRange({ date: '2026-10-01' }, null, '2026-09-30')).toBe(false)
	})

	it('eine Buchung ohne Datum fällt aus einer gefilterten Liste', () => {
		expect(journalRowInDateRange({ date: null }, null, null)).toBe(true)
		expect(journalRowInDateRange({ date: null }, '2026-01-01', null)).toBe(false)
	})
})
