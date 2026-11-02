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

const { accountLabelKey, journalVersion, useJournal } = await import('./useJournal.js')
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

// Stand einer Buchung für den Zeilen-Zwischenspeicher (Issue #254). Bankzuordnung
// und Eröffnungsbilanz legen Buchungen nur mit createdAt an.
describe('journalVersion', () => {
	const item = (journal) => ({ journal: { id: 1, entryNo: 5, ...journal }, lines: [] })

	it('nimmt updatedAt, wenn vorhanden', () => {
		expect(journalVersion(item({ createdAt: '2026-01-01 08:00:00', updatedAt: '2026-02-01 09:00:00' }))).toBe('2026-02-01 09:00:00#5')
	})

	it('faellt auf createdAt zurueck', () => {
		expect(journalVersion(item({ createdAt: '2026-01-01 08:00:00', updatedAt: null }))).toBe('2026-01-01 08:00:00#5')
	})

	it('eine neue Buchungsnummer ergibt einen neuen Stand', () => {
		const a = journalVersion(item({ createdAt: '2026-01-01 08:00:00' }))
		const b = journalVersion(item({ createdAt: '2026-01-01 08:00:00', entryNo: 6 }))
		expect(b).not.toBe(a)
	})

	it('ohne Zeitstempel kein Stand', () => {
		expect(journalVersion(item({ createdAt: null, updatedAt: null }))).toBeNull()
	})
})

describe('accountLabelKey', () => {
	const acc = (over) => ({ id: 1, number: '1200', name: 'Bank', isBank: true, ...over })

	it('gleiche Konten, gleicher Schluessel', () => {
		expect(accountLabelKey([acc()])).toBe(accountLabelKey([acc()]))
	})

	it('aendert sich mit isBank', () => {
		expect(accountLabelKey([acc({ isBank: false })])).not.toBe(accountLabelKey([acc()]))
	})

	it('aendert sich mit Nummer und Name', () => {
		expect(accountLabelKey([acc({ number: '1210' })])).not.toBe(accountLabelKey([acc()]))
		expect(accountLabelKey([acc({ name: 'Sparkasse' })])).not.toBe(accountLabelKey([acc()]))
	})
})
