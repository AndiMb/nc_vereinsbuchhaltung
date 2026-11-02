import { beforeEach, describe, expect, it, vi } from 'vitest'

// „Zuzuordnen" lädt seit Issue #255 nur noch offene Umsätze; Historie für die
// Vorschläge, der Fortschritt und die Umsätze der Journal-Zeilen kommen als
// Kontext aus einer zweiten, parallelen Abfrage.

const listTransactions = vi.fn()
const transactionContext = vi.fn()
vi.mock('../api.js', () => ({
	default: {
		listTransactions: (...args) => listTransactions(...args),
		transactionContext: (...args) => transactionContext(...args),
		// usePeriods/useAccounts werden mitgeladen und rufen beim Import nichts ab.
	},
}))
const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError: (message) => showError(message), showSuccess: vi.fn(), showWarning: vi.fn() }))

const { useJournal } = await import('./useJournal.js')
const { state, unassignedCount, loadTransactions } = useJournal()

const emptyContext = () => ({ history: [], progress: { total: 0, assigned: 0 }, byJournal: {} })

beforeEach(() => {
	state.transactions = []
	state.txContext = emptyContext()
	listTransactions.mockReset()
	transactionContext.mockReset()
	showError.mockReset()
})

describe('loadTransactions', () => {
	it('laedt nur unzugeordnete Umsaetze und den Kontext parallel', async () => {
		let resolveList
		let resolveContext
		listTransactions.mockReturnValue(new Promise((resolve) => { resolveList = resolve }))
		transactionContext.mockReturnValue(new Promise((resolve) => { resolveContext = resolve }))

		const pending = loadTransactions(7)

		// Beide Abfragen sind unterwegs, bevor eine davon antwortet.
		expect(listTransactions).toHaveBeenCalledWith('unassigned')
		expect(transactionContext).toHaveBeenCalledWith(7)

		const context = { history: [{ counterparty: 'Stadtwerke', contraAccountId: 4200, count: 3 }], progress: { total: 10, assigned: 6 }, byJournal: { 101: { id: 1 } } }
		resolveList({ data: [{ id: 5, status: 'unassigned' }] })
		resolveContext({ data: context })
		await pending

		expect(state.transactions).toEqual([{ id: 5, status: 'unassigned' }])
		expect(state.txContext).toEqual(context)
	})

	it('unassignedCount zaehlt die geladene Liste', async () => {
		listTransactions.mockResolvedValue({ data: [{ id: 1, status: 'unassigned' }, { id: 2, status: 'unassigned' }] })
		transactionContext.mockResolvedValue({ data: emptyContext() })
		await loadTransactions(null)

		expect(unassignedCount.value).toBe(2)
	})

	it('meldet einen Ladefehler und behaelt den bisherigen Stand', async () => {
		listTransactions.mockResolvedValueOnce({ data: [{ id: 1, status: 'unassigned' }] })
		transactionContext.mockResolvedValueOnce({ data: emptyContext() })
		await loadTransactions(null)
		listTransactions.mockRejectedValueOnce({ response: { data: { message: 'Keine Leseberechtigung.' } } })
		transactionContext.mockResolvedValueOnce({ data: emptyContext() })
		await loadTransactions(null)

		expect(showError).toHaveBeenCalledWith('Keine Leseberechtigung.')
		expect(state.transactions.map((t) => t.id)).toEqual([1])
	})
})
