import { beforeEach, describe, expect, it, vi } from 'vitest'

// Zustand und Aktionen des Bankabgleichs (Issue #105). Geprüft wird die
// Reihenfolge, nicht die Oberfläche: ein Urteil ändert die Zeile örtlich (kein
// Neuladen – ein Sammler hat hunderte Zeilen), Verbuchen und Zahlungseingang
// laden dagegen neu, und ein Serverfehler lädt trotzdem neu und wird
// weitergereicht.

const api = {
	bankReconciliation: vi.fn(),
	bankReconciliationPreview: vi.fn(),
	assignSepaDetail: vi.fn(),
	rejectSepaDetail: vi.fn(),
	markSepaDetailUnmatched: vi.fn(),
	settleSepaImport: vi.fn(),
	confirmIncomingPayment: vi.fn(),
	rejectIncomingPayment: vi.fn(),
}
vi.mock('../api.js', () => ({ default: api }))

const { useBankReconciliation } = await import('./useBankReconciliation.js')
const bank = useBankReconciliation()

function detail(over = {}) {
	return { id: 7, status: 'offen', debitItemId: null, decidedAt: null, assignedItem: null, candidates: [], ...over }
}

function worklist(items = [{ bankTx: { id: 1 }, details: [detail()] }], incoming = []) {
	return { data: { items, incoming } }
}

beforeEach(() => {
	for (const fn of Object.values(api)) { fn.mockReset() }
	api.bankReconciliation.mockResolvedValue(worklist())
	bank.state.items = []
	bank.state.incoming = []
	bank.state.loaded = false
	bank.state.loading = false
	bank.state.error = null
})

describe('load', () => {
	it('übernimmt Umsätze und Zahlungseingänge', async () => {
		api.bankReconciliation.mockResolvedValue(worklist([{ bankTx: { id: 1 }, details: [] }], [{ bankTx: { id: 2 }, suggestions: [] }]))

		await bank.load()

		expect(bank.state.items.map((i) => i.bankTx.id)).toEqual([1])
		expect(bank.state.incoming.map((i) => i.bankTx.id)).toEqual([2])
		expect(bank.state.loaded).toBe(true)
		expect(bank.state.error).toBeNull()
	})

	it('meldet einen Fehler und behält den letzten Stand', async () => {
		await bank.load()
		api.bankReconciliation.mockRejectedValue(Object.assign(new Error('500'), { response: { data: { message: 'Es ist ein Fehler aufgetreten' } } }))

		await bank.load()

		expect(bank.state.error).toBe('Es ist ein Fehler aufgetreten')
		expect(bank.state.items).toHaveLength(1)
		expect(bank.state.loaded).toBe(true)
		expect(bank.state.loading).toBe(false)
	})

	it('verwirft die Antwort einer überholten Anfrage', async () => {
		let releaseSlow
		api.bankReconciliation
			.mockImplementationOnce(() => new Promise((resolve) => { releaseSlow = () => resolve(worklist([{ bankTx: { id: 1 }, details: [] }])) }))
			.mockResolvedValueOnce(worklist([{ bankTx: { id: 2 }, details: [] }]))

		const slow = bank.load()
		await bank.load()
		releaseSlow()
		await slow

		expect(bank.state.items.map((i) => i.bankTx.id)).toEqual([2])
	})
})

describe('decide', () => {
	it('ordnet zu: die Zeile übernimmt Urteil und gewählten Posten, ohne Neuladen', async () => {
		await bank.load()
		const row = bank.state.items[0].details[0]
		const candidate = { debitItemId: 10, stage: 1, memberName: 'Anna Muster', amountCents: 4500 }
		api.assignSepaDetail.mockResolvedValue({ data: { id: 7, status: 'zugeordnet', debitItemId: 10, decidedAt: '2026-10-10 10:00:00' } })

		await bank.decide(row, 'assign', candidate)

		expect(api.assignSepaDetail).toHaveBeenCalledWith(7, 10)
		expect(row.status).toBe('zugeordnet')
		expect(row.debitItemId).toBe(10)
		expect(row.assignedItem).toEqual(candidate)
		expect(row.assignedItem).not.toBe(candidate)
		expect(api.bankReconciliation).toHaveBeenCalledTimes(1)
	})

	it('lehnt ab und vergisst dabei eine frühere Zuordnung', async () => {
		await bank.load()
		const row = bank.state.items[0].details[0]
		row.status = 'zugeordnet'
		row.debitItemId = 10
		row.assignedItem = { debitItemId: 10 }
		api.rejectSepaDetail.mockResolvedValue({ data: { id: 7, status: 'abgelehnt', debitItemId: null, decidedAt: '2026-10-10 10:05:00' } })

		await bank.decide(row, 'reject')

		expect(api.rejectSepaDetail).toHaveBeenCalledWith(7)
		expect(row.status).toBe('abgelehnt')
		expect(row.debitItemId).toBeNull()
		expect(row.assignedItem).toBeNull()
	})

	it('markiert als nicht zuordenbar', async () => {
		await bank.load()
		const row = bank.state.items[0].details[0]
		api.markSepaDetailUnmatched.mockResolvedValue({ data: { id: 7, status: 'nicht_zuordenbar', debitItemId: null, decidedAt: 'x' } })

		await bank.decide(row, 'unmatched')

		expect(api.markSepaDetailUnmatched).toHaveBeenCalledWith(7)
		expect(row.status).toBe('nicht_zuordenbar')
	})

	it('lässt die Zeile bei einem Fehler unverändert und reicht ihn weiter', async () => {
		await bank.load()
		const row = bank.state.items[0].details[0]
		const error = Object.assign(new Error('403'), { response: { status: 403 } })
		api.rejectSepaDetail.mockRejectedValue(error)

		await expect(bank.decide(row, 'reject')).rejects.toBe(error)

		expect(row.status).toBe('offen')
	})
})

describe('settle', () => {
	it('verbucht und lädt danach neu', async () => {
		await bank.load()
		api.settleSepaImport.mockResolvedValue({ data: { settled: 3, returned: 0 } })
		api.bankReconciliation.mockResolvedValue(worklist([]))

		const result = await bank.settle(1)

		expect(api.settleSepaImport).toHaveBeenCalledWith(1)
		expect(result).toEqual({ settled: 3, returned: 0 })
		expect(bank.state.items).toEqual([])
	})

	it('bei einem Fehler (z. B. geschlossene Periode): Fehler weiterreichen, aber neu laden', async () => {
		await bank.load()
		const error = Object.assign(new Error('423'), { response: { status: 423 } })
		api.settleSepaImport.mockRejectedValue(error)

		await expect(bank.settle(1)).rejects.toBe(error)

		expect(api.bankReconciliation).toHaveBeenCalledTimes(2)
	})
})

describe('Zahlungseingang', () => {
	it('bestätigt und lädt neu', async () => {
		api.confirmIncomingPayment.mockResolvedValue({ data: { id: 100, status: 'paid' } })

		await bank.confirmIncoming(5, 100)

		expect(api.confirmIncomingPayment).toHaveBeenCalledWith(5, 100)
		expect(api.bankReconciliation).toHaveBeenCalledTimes(1)
	})

	it('lehnt ab und lädt neu – der Vorschlag ist danach weg', async () => {
		api.rejectIncomingPayment.mockResolvedValue({ data: { rejected: true } })

		await bank.rejectIncoming(5, 100)

		expect(api.rejectIncomingPayment).toHaveBeenCalledWith(5, 100)
		expect(api.bankReconciliation).toHaveBeenCalledTimes(1)
	})

	it('lädt auch nach einem Fehler neu (die Forderung ist vielleicht schon bezahlt)', async () => {
		const error = Object.assign(new Error('400'), { response: { status: 400, data: { message: 'Diese Forderung ist nicht mehr offen.' } } })
		api.confirmIncomingPayment.mockRejectedValue(error)

		await expect(bank.confirmIncoming(5, 100)).rejects.toBe(error)

		expect(api.bankReconciliation).toHaveBeenCalledTimes(1)
	})
})

describe('loadPreview', () => {
	it('liefert die Vorschau des Servers', async () => {
		api.bankReconciliationPreview.mockResolvedValue({ data: { direction: 'collection', blockers: [] } })

		expect(await bank.loadPreview(1)).toEqual({ direction: 'collection', blockers: [] })
		expect(api.bankReconciliationPreview).toHaveBeenCalledWith(1)
	})
})
