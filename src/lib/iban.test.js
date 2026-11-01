import { describe, expect, it } from 'vitest'
import { formatIban, ibanState, isValidIban, normalizeIban } from './iban.js'

describe('iban', () => {
	it('vereinheitlicht die Schreibweise', () => {
		expect(normalizeIban(' de89 3704-0044 0532 0130 00 ')).toBe('DE89370400440532013000')
		expect(formatIban('de89370400440532013000')).toBe('DE89 3704 0044 0532 0130 00')
		expect(formatIban('')).toBe('')
	})

	it('erkennt gültige Prüfziffern, auch mit Buchstaben in der Kontonummer', () => {
		expect(isValidIban('DE89 3704 0044 0532 0130 00')).toBe(true)
		expect(isValidIban('GB82 WEST 1234 5698 7654 32')).toBe(true)
		expect(isValidIban('AT61 1904 3002 3457 3201')).toBe(true)
	})

	it('weist falsche Prüfziffern und falsche Formen ab', () => {
		expect(isValidIban('DE89 3704 0044 0532 0130 01')).toBe(false)
		expect(isValidIban('DE00 3704 0044 0532 0130 00')).toBe(false)
		expect(isValidIban('kein iban')).toBe(false)
		expect(isValidIban('')).toBe(false)
	})

	it('meldet einen Zustand für die Anzeige am Feld', () => {
		expect(ibanState('')).toBe('empty')
		expect(ibanState('DE89 3704')).toBe('incomplete')
		expect(ibanState('DE89 3704 0044 0532 0130 00')).toBe('valid')
		expect(ibanState('DE89 3704 0044 0532 0130 01')).toBe('invalid')
	})
})
