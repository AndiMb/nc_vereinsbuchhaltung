import { describe, expect, it } from 'vitest'
import { chunkIds, emptyResult, mergeResult, reachableCount, RECONFIRM_BATCH_SIZE, targetIds } from './mandateReconfirmation.js'

describe('chunkIds', () => {
	it('teilt die Liste in Stücke der Obergrenze des Servers', () => {
		const ids = Array.from({ length: RECONFIRM_BATCH_SIZE * 2 + 3 }, (_, i) => i + 1)

		const chunks = chunkIds(ids)

		expect(chunks.map((c) => c.length)).toEqual([RECONFIRM_BATCH_SIZE, RECONFIRM_BATCH_SIZE, 3])
		expect(chunks.flat()).toEqual(ids)
	})

	it('liefert bei leerer Liste keine Stücke', () => {
		expect(chunkIds([])).toEqual([])
	})
})

describe('targetIds', () => {
	const overview = { readyIds: [1, 2], pendingIds: [3] }

	it('nimmt ohne Erinnerung nur die ohne gültigen Link', () => {
		expect(targetIds(overview, false)).toEqual([1, 2])
	})

	it('nimmt mit Erinnerung auch die mit gültigem Link', () => {
		expect(targetIds(overview, true)).toEqual([1, 2, 3])
	})

	it('verändert die Übersicht nicht', () => {
		targetIds(overview, true)
		expect(overview.readyIds).toEqual([1, 2])
	})
})

describe('mergeResult', () => {
	it('summiert Zähler und hängt die Namen aneinander', () => {
		const a = { sent: 2, alreadyCurrent: 1, skipped: 0, noEmail: ['A'], failed: [] }
		const b = { sent: 3, alreadyCurrent: 0, skipped: 1, noEmail: ['B'], failed: [{ name: 'C', reason: 'x' }] }

		expect(mergeResult(a, b)).toEqual({
			sent: 5,
			alreadyCurrent: 1,
			skipped: 1,
			noEmail: ['A', 'B'],
			failed: [{ name: 'C', reason: 'x' }],
		})
	})

	it('beginnt mit einem leeren Ergebnis', () => {
		expect(mergeResult(emptyResult(), emptyResult())).toEqual(emptyResult())
	})
})

describe('reachableCount', () => {
	it('zählt Mandate mit und ohne gültigen Link', () => {
		expect(reachableCount({ readyIds: [1, 2], pendingIds: [3] })).toBe(3)
	})
})
