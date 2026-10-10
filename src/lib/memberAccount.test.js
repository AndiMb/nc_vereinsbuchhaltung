import { describe, expect, it } from 'vitest'
import { linkedAccountText } from './memberAccount.js'

// Issue #106: Nutzerdaten stehen nie als Variablen in t() (Escaping als HTML).
describe('linkedAccountText', () => {
	it('zeigt den Benutzernamen unverändert, auch mit „&", „<" und „\'"', () => {
		expect(linkedAccountText('anna')).toBe('Verknüpft mit „anna".')
		expect(linkedAccountText('o\'brien&söhne<x>')).toBe('Verknüpft mit „o\'brien&söhne<x>".')
	})
})
