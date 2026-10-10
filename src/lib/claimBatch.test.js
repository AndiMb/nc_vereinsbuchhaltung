import { describe, expect, it, vi } from 'vitest'
import { createClaimsForMembers } from './claimBatch.js'

const form = { memberIds: [3, 5, 8], type: 'beitrag', amount: '12,50', label: 'Sommerfest', dueDate: '2026-12-01' }

describe('createClaimsForMembers', () => {
	it('legt je Mitglied eine Forderung mit denselben Angaben an, in der Reihenfolge der Auswahl', async () => {
		const createClaim = vi.fn().mockResolvedValue({})
		const result = await createClaimsForMembers(createClaim, form, String)

		expect(result).toEqual({ created: [3, 5, 8], failed: [] })
		expect(createClaim.mock.calls.map(([data]) => data)).toEqual([
			{ memberId: 3, type: 'beitrag', amount: '12,50', label: 'Sommerfest', dueDate: '2026-12-01' },
			{ memberId: 5, type: 'beitrag', amount: '12,50', label: 'Sommerfest', dueDate: '2026-12-01' },
			{ memberId: 8, type: 'beitrag', amount: '12,50', label: 'Sommerfest', dueDate: '2026-12-01' },
		])
	})

	it('macht nach einem Fehler mit den übrigen weiter und meldet, wer fehlt', async () => {
		const createClaim = vi.fn()
			.mockResolvedValueOnce({})
			.mockRejectedValueOnce(new Error('Mitglied nicht gefunden'))
			.mockResolvedValueOnce({})
		const result = await createClaimsForMembers(createClaim, form, (e) => e.message)

		expect(createClaim).toHaveBeenCalledTimes(3)
		expect(result.created).toEqual([3, 8])
		expect(result.failed).toEqual([{ memberId: 5, message: 'Mitglied nicht gefunden' }])
	})

	it('tut ohne Auswahl nichts', async () => {
		const createClaim = vi.fn()
		const result = await createClaimsForMembers(createClaim, { ...form, memberIds: [] }, String)

		expect(createClaim).not.toHaveBeenCalled()
		expect(result).toEqual({ created: [], failed: [] })
	})
})
