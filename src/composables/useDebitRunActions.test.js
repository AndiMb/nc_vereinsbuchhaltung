import { beforeEach, describe, expect, it, vi } from 'vitest'

// Die Schreibaktionen des Einzug-Unterreiters (Issue #103) laden nach jeder
// Aktion alles neu und stellen die Auswahl so, dass das Ergebnis sichtbar ist.
// Geprüft wird die Reihenfolge, nicht die Oberfläche: Auswahl VOR dem Nachladen
// (sonst lädt reload() Vorschau und Lauf des alten Termins), und ein
// Serverfehler lädt trotzdem neu, damit der wirkliche Stand erscheint.

const api = {
	releaseDebitBatch: vi.fn(),
	submitDebitBatch: vi.fn(),
	discardDebitBatch: vi.fn(),
	rescheduleDebitBatch: vi.fn(),
	debitTimeline: vi.fn(),
	listDebitBatches: vi.fn(),
	debitPreview: vi.fn(),
	getDebitBatch: vi.fn(),
}
vi.mock('../api.js', () => ({ default: api }))

const { useDebitRuns } = await import('./useDebitRuns.js')
const { useDebitRunActions } = await import('./useDebitRunActions.js')
const { state } = useDebitRuns()
const actions = useDebitRunActions()

function timeline(dates = ['2026-11-01', '2026-12-01'], counts = {}) {
	return {
		today: '2026-10-04',
		year: { anchorYear: 2026, label: '2026', start: '2026-01-01', end: '2026-12-31', isCurrent: true },
		leadDays: { warning: 21, prenotification: 14, release: 5 },
		dates: dates.map((dueDate) => ({ dueDate, milestones: [], preview: { count: counts[dueDate] ?? 0, sumCents: 0 }, batches: [] })),
		// Wie im echten Zeitstrahl: der nächste Termin ist der erste mit etwas zu tun.
		next: { dueDate: dates[0], milestones: [], preview: { count: 0, sumCents: 0 }, batches: [] },
	}
}

const run = (over = {}) => ({ id: 5, dueDate: '2026-11-01', status: 'freigegeben', itemCount: 2, sumCents: 2500, ...over })

beforeEach(() => {
	for (const fn of Object.values(api)) { fn.mockReset() }
	api.debitTimeline.mockResolvedValue({ data: timeline() })
	api.listDebitBatches.mockResolvedValue({ data: [run()] })
	api.debitPreview.mockResolvedValue({ data: { summary: { count: 0, sumCents: 0 }, claims: [], issues: [] } })
	api.getDebitBatch.mockImplementation(async (id) => ({ data: run({ id, items: [] }) }))
	state.timeline = null
	state.runs = []
	state.runDetails = {}
	state.previews = {}
	state.selectedDate = '2026-12-01'
	state.expandedRunId = null
	state.year = null
})

describe('releaseRun', () => {
	it('wählt den Termin des neuen Laufs, klappt ihn auf und lädt ihn frisch', async () => {
		api.releaseDebitBatch.mockResolvedValue({ data: run({ id: 9, dueDate: '2026-11-01' }) })

		const batch = await actions.releaseRun('2026-11-01')

		expect(api.releaseDebitBatch).toHaveBeenCalledWith('2026-11-01')
		expect(batch.id).toBe(9)
		expect(state.selectedDate).toBe('2026-11-01')
		expect(state.expandedRunId).toBe(9)
		expect(api.getDebitBatch).toHaveBeenCalledWith(9)
		expect(api.debitPreview).toHaveBeenCalledWith('2026-11-01')
	})

	it('bei einem Serverfehler: Fehler weiterreichen, Auswahl nicht anfassen, aber neu laden', async () => {
		const error = Object.assign(new Error('400'), { response: { data: { message: 'Keine fälligen Posten' } } })
		api.releaseDebitBatch.mockRejectedValue(error)

		await expect(actions.releaseRun('2026-11-01')).rejects.toBe(error)

		expect(state.selectedDate).toBe('2026-12-01')
		expect(state.expandedRunId).toBeNull()
		expect(api.debitTimeline).toHaveBeenCalledTimes(1)
	})
})

describe('submitRun', () => {
	it('lädt nach der Einreichung neu und belässt die Auswahl', async () => {
		state.expandedRunId = 5
		api.submitDebitBatch.mockResolvedValue({ data: run({ status: 'eingereicht' }) })

		await actions.submitRun(run())

		expect(api.submitDebitBatch).toHaveBeenCalledWith(5)
		expect(state.expandedRunId).toBe(5)
		expect(state.selectedDate).toBe('2026-12-01')
		expect(api.debitTimeline).toHaveBeenCalledTimes(1)
		expect(api.getDebitBatch).toHaveBeenCalledWith(5)
	})
})

describe('discardRun', () => {
	it('schickt die Begründung mit und wählt den Termin, damit die Geisterkarte „Neu freigeben“ zeigt', async () => {
		state.expandedRunId = 5
		api.discardDebitBatch.mockResolvedValue({ data: run({ status: 'verworfen' }) })

		await actions.discardRun(run(), 'Falsches Datum')

		expect(api.discardDebitBatch).toHaveBeenCalledWith(5, 'Falsches Datum')
		expect(state.selectedDate).toBe('2026-11-01')
		expect(state.expandedRunId).toBe(5)
		expect(api.debitPreview).toHaveBeenCalledWith('2026-11-01')
	})

	it('wählt den Termin, an dem die Forderungen wieder frei sind, wenn der Lauf verschoben war', async () => {
		// Lauf auf den 01.12. verschoben; seine Forderungen sind am 01.11. fällig und tauchen dort wieder auf.
		state.expandedRunId = 5
		state.timeline = timeline(['2026-11-01', '2026-12-01'], {})
		api.debitTimeline.mockResolvedValue({ data: timeline(['2026-11-01', '2026-12-01'], { '2026-11-01': 2 }) })
		api.discardDebitBatch.mockResolvedValue({ data: run({ status: 'verworfen', dueDate: '2026-12-01' }) })

		await actions.discardRun(run({ dueDate: '2026-12-01' }), 'Falsches Datum')

		expect(state.selectedDate).toBe('2026-11-01')
		expect(api.debitPreview).toHaveBeenLastCalledWith('2026-11-01')
		expect(state.expandedRunId).toBe(5)
	})
})

describe('rescheduleRun', () => {
	it('wählt den neuen Termin, wenn der Zeitstrahl ihn kennt', async () => {
		state.expandedRunId = 5
		api.rescheduleDebitBatch.mockResolvedValue({ data: run({ dueDate: '2026-12-01' }) })
		api.debitTimeline.mockResolvedValue({ data: timeline(['2026-11-01', '2026-12-01']) })
		state.selectedDate = '2026-11-01'

		await actions.rescheduleRun(run(), '2026-12-01')

		expect(api.rescheduleDebitBatch).toHaveBeenCalledWith(5, '2026-12-01')
		expect(state.selectedDate).toBe('2026-12-01')
	})

	it('fällt auf die Vorauswahl zurück, wenn das angezeigte Jahr den neuen Termin nicht kennt', async () => {
		state.expandedRunId = 5
		api.rescheduleDebitBatch.mockResolvedValue({ data: run({ dueDate: '2027-01-15' }) })
		state.selectedDate = '2026-11-01'

		await actions.rescheduleRun(run(), '2027-01-15')

		// 2027-01-15 steht nicht auf dem 2026er-Strahl: ensureSelection() verwirft die Auswahl
		// und nimmt den nächsten Termin.
		expect(state.selectedDate).toBe('2026-11-01')
	})
})
