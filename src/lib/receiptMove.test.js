import { describe, expect, it } from 'vitest'
import { moveOutcome } from './receiptMove.js'

describe('moveOutcome', () => {
	it('alles verschoben: Erfolg', () => {
		expect(moveOutcome({ moved: [1, 2], skipped: [] })).toEqual({ kind: 'success', moved: 2, skipped: 0, error: '' })
	})

	it('einzelne übersprungen: Warnung', () => {
		expect(moveOutcome({ moved: [1], skipped: [{ fileId: 2, reason: 'assigned' }] }).kind).toBe('warning')
	})

	it('ein späteres Paket scheitert: Fehler, auch wenn das erste verschoben ist', () => {
		expect(moveOutcome({ moved: [1, 2], skipped: [], error: 'Zeitüberschreitung' }))
			.toEqual({ kind: 'error', moved: 2, skipped: 0, error: 'Zeitüberschreitung' })
	})
})
