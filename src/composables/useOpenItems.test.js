import { beforeEach, describe, expect, it, vi } from 'vitest'

// useOpenItems hält die Liste der generischen Offene-Posten-Sicht (Buchungen →
// Offene Posten). Seit Issue #121 trägt sie freie Posten UND Forderungen des
// Beitragsmoduls; `isClaim` unterscheidet sie für die Ansicht, damit an einer
// Forderung keine schreibenden Aktionen stehen. Geprüft wird, dass die Felder
// des Servers (`memberId`/`type`) bis in die Ansicht durchkommen und die
// Überfälligkeits-Zahl der Dashboard-Kachel beide Sorten weiter zählt.

const listOpenItems = vi.fn()
const getOpenItemCounts = vi.fn()
vi.mock('../api.js', () => ({ default: { listOpenItems: (status) => listOpenItems(status), getOpenItemCounts: () => getOpenItemCounts() } }))
const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError: (message) => showError(message) }))

const { useOpenItems } = await import('./useOpenItems.js')
const { state, overdueCount, loadOpenItems, isClaim } = useOpenItems()

const free = (id, over = {}) => ({ id, debtor: 'Schreinerei Holz', status: 'open', overdue: false, memberId: null, type: null, ...over })
const claim = (id, over = {}) => free(id, { debtor: 'Anna Musterfrau', memberId: 7, type: 'beitrag', ...over })

beforeEach(() => {
	state.openItems = []
	state.openItemCounts = { open: 0, overdue: 0, paid: 0, waived: 0, cancelled: 0, all: 0 }
	state.openItemFilterKey = 'open'
	listOpenItems.mockReset()
	getOpenItemCounts.mockReset()
	getOpenItemCounts.mockResolvedValue({ data: { open: 0, overdue: 0, paid: 0, waived: 0, cancelled: 0, all: 0 } })
	showError.mockReset()
})

describe('loadOpenItems', () => {
	it('liefert freie Posten und Forderungen mit ihren Feldern und erkennt sie an memberId/type', async () => {
		listOpenItems.mockResolvedValue({ data: [free(1), claim(2), claim(3, { type: 'gebuehr' })] })
		await loadOpenItems()

		expect(state.openItems.map((o) => isClaim(o))).toEqual([false, true, true])
		expect(state.openItems[1]).toMatchObject({ memberId: 7, type: 'beitrag' })
	})

	it('laedt standardmaessig nur offene Posten', async () => {
		listOpenItems.mockResolvedValue({ data: [free(1)] })
		await loadOpenItems()

		expect(listOpenItems).toHaveBeenCalledWith('open')
		expect(getOpenItemCounts).toHaveBeenCalledTimes(1)
	})

	it('laedt beim Filterwechsel den Status nach', async () => {
		listOpenItems.mockResolvedValue({ data: [free(5, { status: 'paid' })] })
		await loadOpenItems('paid')

		expect(listOpenItems).toHaveBeenLastCalledWith('paid')
		expect(state.openItems.map((o) => o.id)).toEqual([5])

		await loadOpenItems('all')
		expect(listOpenItems).toHaveBeenLastCalledWith(null)
		await loadOpenItems('overdue')
		expect(listOpenItems).toHaveBeenLastCalledWith('open')
	})

	it('behaelt den gewaehlten Filter beim Nachladen ohne Argument', async () => {
		listOpenItems.mockResolvedValue({ data: [] })
		await loadOpenItems('cancelled')
		await loadOpenItems()

		expect(listOpenItems).toHaveBeenLastCalledWith('cancelled')
	})

	it('overdueCount kommt aus den Zaehlern', async () => {
		listOpenItems.mockResolvedValue({ data: [free(1)] })
		getOpenItemCounts.mockResolvedValue({ data: { open: 3, overdue: 2, paid: 0, waived: 0, cancelled: 0, all: 3 } })
		await loadOpenItems()

		expect(state.openItemCounts.all).toBe(3)
		expect(overdueCount.value).toBe(2)
	})

	it('meldet einen Ladefehler mit der Antwort des Servers und behält die Liste', async () => {
		listOpenItems.mockResolvedValueOnce({ data: [free(1)] })
		await loadOpenItems()
		listOpenItems.mockRejectedValueOnce({ response: { data: { message: 'Keine Leseberechtigung.' } } })
		await loadOpenItems()

		expect(showError).toHaveBeenCalledWith('Keine Leseberechtigung.')
		expect(state.openItems.map((o) => o.id)).toEqual([1])
	})
})
