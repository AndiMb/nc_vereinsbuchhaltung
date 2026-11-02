import { beforeEach, describe, expect, it, vi } from 'vitest'

// loadPeriods() ist die einzige Stelle, die die Zeitraum-Auswahl (Kopfzeile,
// v.a. `.vbh-yearsel`) speist. Bis 0.34.0 schluckte ein Fehlschlag der
// Anfrage still - eine einzelne unter Serverlast steckengebliebene Antwort
// liess die Auswahl fuer den Rest der Sitzung leer, ohne dass irgendwo ein
// Reload nachgeholfen haette (anders als bei loadAccounts/loadBalances).
// Getestet wird deshalb genau das: ein einzelner Fehlschlag heilt sich per
// Wiederholung selbst, und erst wenn alle Versuche scheitern, meldet sich
// die App - wie jede andere kritische Ladefunktion auch.

const periods = vi.fn()
vi.mock('../api.js', () => ({ default: { periods: (config) => periods(config) } }))

const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError: (...args) => showError(...args) }))

const { usePeriods } = await import('./usePeriods.js')
const { state, loadPeriods } = usePeriods()

const PERIOD_2031 = { id: 1, label: '2031', startDate: '2031-01-01', endDate: '2031-12-31', closedAt: null }

describe('loadPeriods', () => {
	beforeEach(() => {
		state.periods = []
		state.selectedPeriodId = null
		state.initialised = false
		periods.mockReset()
		showError.mockReset()
	})

	it('setzt die Zeiträume und die Vorgabe beim ersten erfolgreichen Laden', async () => {
		periods.mockResolvedValue({ data: [PERIOD_2031] })
		await loadPeriods()
		expect(state.periods).toEqual([PERIOD_2031])
		expect(state.selectedPeriodId).toBe(1)
		expect(showError).not.toHaveBeenCalled()
	})

	it('erholt sich von einem einzelnen Fehlschlag durch Wiederholung', async () => {
		periods.mockRejectedValueOnce(new Error('kurz haengengeblieben'))
		periods.mockResolvedValueOnce({ data: [PERIOD_2031] })
		await loadPeriods()
		expect(state.periods).toEqual([PERIOD_2031])
		expect(periods).toHaveBeenCalledTimes(2)
		expect(showError).not.toHaveBeenCalled()
	})

	it('meldet einen Fehler, wenn alle Versuche scheitern, und laesst den bisherigen Stand unangetastet', async () => {
		state.periods = [PERIOD_2031]
		periods.mockRejectedValue(new Error('offline'))
		await loadPeriods()
		expect(periods).toHaveBeenCalledTimes(3)
		expect(showError).toHaveBeenCalledTimes(1)
		// Kein Ueberschreiben mit einer leeren Liste - ein spaeterer Poll darf
		// den zuletzt bekannten Stand nicht wegen eines einzelnen Ausfalls verlieren.
		expect(state.periods).toEqual([PERIOD_2031])
	})

	it('gibt jedem Versuch ein eigenes Zeitlimit mit, damit axios nicht unbegrenzt wartet', async () => {
		periods.mockResolvedValue({ data: [PERIOD_2031] })
		await loadPeriods()
		expect(periods).toHaveBeenCalledWith(expect.objectContaining({ timeout: expect.any(Number) }))
	})
})

describe('Vorgabe-Zeitraum – Issue #263', () => {
	beforeEach(() => {
		state.periods = []
		state.selectedPeriodId = null
		state.initialised = false
		periods.mockReset()
	})

	it('nimmt den Tag des Vereins: Silvester 23:30 Uhr UTC ist in Berlin schon das neue Jahr', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-12-31T23:30:00Z'))
		try {
			periods.mockResolvedValue({
				data: [
					{ id: 27, label: '2027', startDate: '2027-01-01', endDate: '2027-12-31', closedAt: null },
					{ id: 26, label: '2026', startDate: '2026-01-01', endDate: '2026-12-31', closedAt: null },
				],
			})
			// Mit dem UTC-Tag (31.12.) wäre 2026 der laufende Zeitraum.
			await loadPeriods()
			expect(state.selectedPeriodId).toBe(27)
		} finally {
			vi.useRealTimers()
		}
	})
})
