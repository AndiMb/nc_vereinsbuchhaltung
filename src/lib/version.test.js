import { describe, expect, it } from 'vitest'
import { compareVersions, isNewerVersion } from './version.js'

describe('compareVersions', () => {
	it('behandelt gleiche Versionen als gleich', () => {
		expect(compareVersions('0.25.0', '0.25.0')).toBe(0)
	})

	it('vergleicht Segmente als Zahlen, nicht als String', () => {
		// Ein String-Vergleich haette '0.9.0' > '0.10.0' ergeben.
		expect(compareVersions('0.10.0', '0.9.0')).toBe(1)
		expect(compareVersions('0.9.0', '0.10.0')).toBe(-1)
	})

	it('fuellt fehlende Segmente mit 0 auf', () => {
		expect(compareVersions('1.0', '1.0.0')).toBe(0)
		expect(compareVersions('1.0.1', '1.0')).toBe(1)
	})
})

describe('isNewerVersion', () => {
	it('erkennt eine neuere Version', () => {
		expect(isNewerVersion('0.25.0', '0.24.3')).toBe(true)
		expect(isNewerVersion('0.24.3', '0.25.0')).toBe(false)
	})

	it('behandelt eine leere Vergleichsversion als aeltester Stand', () => {
		expect(isNewerVersion('0.25.0', '')).toBe(true)
	})
})
