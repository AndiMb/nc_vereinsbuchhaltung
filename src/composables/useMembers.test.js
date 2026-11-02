import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../api.js', () => ({ default: { listMembers: vi.fn() } }))

const api = (await import('../api.js')).default
const { useMembers } = await import('./useMembers.js')

describe('loadMembers', () => {
	beforeEach(() => {
		api.listMembers.mockReset()
	})

	it('zwei gleichzeitige Aufrufe rufen die API einmal auf', async () => {
		api.listMembers.mockResolvedValue({ data: [{ id: 1 }] })
		const { state, loadMembers } = useMembers()
		await Promise.all([loadMembers(), loadMembers()])
		expect(api.listMembers).toHaveBeenCalledTimes(1)
		expect(state.members).toEqual([{ id: 1 }])
	})

	it('nach Abschluss laedt ein neuer Aufruf erneut', async () => {
		api.listMembers.mockResolvedValue({ data: [] })
		const { loadMembers } = useMembers()
		await loadMembers()
		await loadMembers()
		expect(api.listMembers).toHaveBeenCalledTimes(2)
	})

	it('gibt nach einem Fehler den Lader wieder frei', async () => {
		api.listMembers.mockRejectedValueOnce(new Error('x')).mockResolvedValue({ data: [] })
		const { loadMembers } = useMembers()
		await loadMembers()
		await loadMembers()
		expect(api.listMembers).toHaveBeenCalledTimes(2)
	})
})
