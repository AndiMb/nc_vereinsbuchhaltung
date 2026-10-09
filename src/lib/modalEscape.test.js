import { describe, expect, it } from 'vitest'
import { escapeNeedsBridge } from './modalEscape.js'

describe('escapeNeedsBridge', () => {
	const ev = (over = {}) => ({ key: 'Escape', targetTag: 'INPUT', ...over })

	it('springt ein, wenn Escape aus einem Textfeld kommt (NcModal ignoriert es dort)', () => {
		expect(escapeNeedsBridge(ev())).toBe(true)
		expect(escapeNeedsBridge(ev({ targetTag: 'TEXTAREA' }))).toBe(true)
		expect(escapeNeedsBridge(ev({ targetTag: 'SELECT' }))).toBe(true)
	})

	it('lässt Knöpfe und sonstige Ziele NcModal selbst behandeln', () => {
		expect(escapeNeedsBridge(ev({ targetTag: 'BUTTON' }))).toBe(false)
		expect(escapeNeedsBridge(ev({ targetTag: 'DIV' }))).toBe(false)
		expect(escapeNeedsBridge(ev({ targetTag: undefined }))).toBe(false)
	})

	it('reagiert nur auf Escape', () => {
		expect(escapeNeedsBridge(ev({ key: 'Enter' }))).toBe(false)
		expect(escapeNeedsBridge(ev({ key: 'a' }))).toBe(false)
	})

	it('lässt aufgeklappte Auswahllisten und Datumsfelder zuerst ihr Popup schließen', () => {
		expect(escapeNeedsBridge(ev({ insidePopup: true }))).toBe(false)
	})

	it('übergeht bereits behandelte Ereignisse und die Eingabe per Eingabemethoden-Editor', () => {
		expect(escapeNeedsBridge(ev({ defaultPrevented: true }))).toBe(false)
		expect(escapeNeedsBridge(ev({ isComposing: true }))).toBe(false)
	})
})
