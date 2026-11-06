import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../api.js', () => ({ default: { listAssignments: vi.fn() } }))

const api = (await import('../api.js')).default
const { useAssignments } = await import('./useAssignments.js')

describe('loadAssignments', () => {
	beforeEach(() => {
		api.listAssignments.mockReset()
	})

	it('zwei gleichzeitige Aufrufe rufen die API einmal auf', async () => {
		api.listAssignments.mockResolvedValue({ data: [{ id: 1 }] })
		const { state, loadAssignments } = useAssignments()
		await Promise.all([loadAssignments(), loadAssignments()])
		expect(api.listAssignments).toHaveBeenCalledTimes(1)
		expect(state.assignments).toEqual([{ id: 1 }])
	})

	it('nach Abschluss laedt ein neuer Aufruf erneut', async () => {
		api.listAssignments.mockResolvedValue({ data: [] })
		const { loadAssignments } = useAssignments()
		await loadAssignments()
		await loadAssignments()
		expect(api.listAssignments).toHaveBeenCalledTimes(2)
	})

	it('gibt nach einem Fehler den Lader wieder frei', async () => {
		api.listAssignments.mockRejectedValueOnce(new Error('x')).mockResolvedValue({ data: [] })
		const { loadAssignments } = useAssignments()
		await loadAssignments()
		await loadAssignments()
		expect(api.listAssignments).toHaveBeenCalledTimes(2)
	})
})
