import { describe, expect, it } from 'vitest'
import { suggestUserId } from './userId.js'

describe('suggestUserId()', () => {
	it('macht aus Vor- und Nachname einen Kontonamen', () => {
		expect(suggestUserId('Anna', 'Koch')).toBe('anna.koch')
	})

	it('schreibt Umlaute und ß aus', () => {
		expect(suggestUserId('Jürgen', 'Weiß-Müller')).toBe('juergen.weiss-mueller')
	})

	it('entfernt Akzente und Sonderzeichen', () => {
		expect(suggestUserId('José', "O'Neil")).toBe('jose.o-neil')
	})

	it('lässt leere Teile aus', () => {
		expect(suggestUserId('', 'Koch')).toBe('koch')
		expect(suggestUserId(null, undefined)).toBe('')
	})
})
