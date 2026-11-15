import { describe, expect, it } from 'vitest'
import { THRESHOLD_DEFAULTS, thresholdsError } from './budgetStatusSettings.js'

const valid = { expWarn: 70, expCrit: 110, incGood: 95, incWarn: 60, resTol: 10 }

describe('thresholdsError', () => {
	it('nimmt die Vorgaben und gültige Werte an', () => {
		expect(thresholdsError({ ...THRESHOLD_DEFAULTS })).toBeNull()
		expect(thresholdsError(valid)).toBeNull()
		expect(thresholdsError({ expWarn: 0, expCrit: 999, incGood: 999, incWarn: 0, resTol: 999 })).toBeNull()
	})

	it('entspricht den Vorgaben des Servers', () => {
		expect(THRESHOLD_DEFAULTS).toEqual({ expWarn: 80, expCrit: 120, incGood: 100, incWarn: 80, resTol: 20 })
	})

	it.each([
		['leer', { ...valid, resTol: '' }],
		['negativ', { ...valid, resTol: -1 }],
		['zu groß', { ...valid, expCrit: 1000 }],
		['Bruchzahl', { ...valid, expWarn: 80.5 }],
		['fehlt', { expWarn: 70, expCrit: 110, incGood: 95, incWarn: 60 }],
	])('lehnt einen ungültigen Wert ab (%s)', (_, values) => {
		expect(thresholdsError(values)).toMatch(/ganze Zahl zwischen 0 und 999/)
	})

	it('verlangt bei den Ausgaben Orange unter Rot', () => {
		expect(thresholdsError({ ...valid, expWarn: 120, expCrit: 120 })).toMatch(/Ausgaben/)
	})

	it('verlangt bei den Einnahmen Rot unter Grün', () => {
		expect(thresholdsError({ ...valid, incWarn: 100, incGood: 100 })).toMatch(/Einnahmen/)
	})
})
