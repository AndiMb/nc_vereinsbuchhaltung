import { describe, expect, it, vi } from 'vitest'
import { createRowCache } from './rowCache.js'

// Issue #254: Das Journal baut nach jedem Nachladen nur die Zeilen neu, die
// sich geändert haben. Gleiche Objekte lassen v-memo die Zeile überspringen.

const item = (id, updatedAt, text = 'x') => ({ id, updatedAt, text })

describe('createRowCache', () => {
	it('liefert dasselbe Objekt fuer unveraenderte Zeilen', () => {
		const rows = createRowCache((i) => ({ id: i.id, text: i.text }))
		const first = rows([item(1, '2026-01-01 10:00:00'), item(2, '2026-01-02 10:00:00')], 'k')
		// Neue Objekte vom Server, gleicher Stand.
		const second = rows([item(1, '2026-01-01 10:00:00'), item(2, '2026-01-02 10:00:00')], 'k')
		expect(second).not.toBe(first)
		expect(second[0]).toBe(first[0])
		expect(second[1]).toBe(first[1])
	})

	it('baut eine geaenderte Zeile neu', () => {
		const build = vi.fn((i) => ({ id: i.id, text: i.text }))
		const rows = createRowCache(build)
		const first = rows([item(1, '2026-01-01 10:00:00'), item(2, '2026-01-02 10:00:00')], 'k')
		const second = rows([item(1, '2026-01-01 10:00:00'), item(2, '2026-01-03 09:00:00', 'neu')], 'k')
		expect(second[0]).toBe(first[0])
		expect(second[1]).not.toBe(first[1])
		expect(second[1].text).toBe('neu')
		expect(build).toHaveBeenCalledTimes(3)
	})

	it('baut alle neu, wenn sich deps aendern', () => {
		const rows = createRowCache((i) => ({ id: i.id }))
		const first = rows([item(1, 'a'), item(2, 'b')], 'k1')
		const second = rows([item(1, 'a'), item(2, 'b')], 'k2')
		expect(second[0]).not.toBe(first[0])
		expect(second[1]).not.toBe(first[1])
	})

	it('baut Zeilen ohne Stand immer neu', () => {
		const rows = createRowCache((i) => ({ id: i.id }))
		const first = rows([item(1, null)], 'k')
		const second = rows([item(1, null)], 'k')
		expect(second[0]).not.toBe(first[0])
	})

	it('nimmt eigene Schluessel fuer Kennung und Stand', () => {
		const rows = createRowCache((i) => ({ id: i.journal.id }), { id: (i) => i.journal.id, version: (i) => i.journal.updatedAt })
		const a = { journal: { id: 7, updatedAt: 't1' } }
		const first = rows([a], 'k')
		const second = rows([{ journal: { id: 7, updatedAt: 't1' } }], 'k')
		expect(second[0]).toBe(first[0])
	})

	it('vergisst Zeilen, die nicht mehr vorkommen', () => {
		const rows = createRowCache((i) => ({ id: i.id }))
		const first = rows([item(1, 'a')], 'k')
		rows([], 'k')
		const third = rows([item(1, 'a')], 'k')
		expect(third[0]).not.toBe(first[0])
	})
})
