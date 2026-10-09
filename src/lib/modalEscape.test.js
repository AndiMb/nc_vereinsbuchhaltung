import { describe, expect, it } from 'vitest'
import { escapeClosesModal } from './modalEscape.js'

describe('escapeClosesModal', () => {
	const ev = (over = {}) => ({ key: 'Escape', ...over })

	it('schließt bei Escape, egal ob der Fokus im Textfeld, auf einem Knopf oder auf dem Dialog liegt', () => {
		expect(escapeClosesModal(ev())).toBe(true)
	})

	it('reagiert nur auf Escape', () => {
		expect(escapeClosesModal(ev({ key: 'Enter' }))).toBe(false)
		expect(escapeClosesModal(ev({ key: 'a' }))).toBe(false)
	})

	it('lässt aufgeklappte Auswahllisten und Datumsfelder zuerst ihr Popup schließen', () => {
		expect(escapeClosesModal(ev({ insidePopup: true }))).toBe(false)
	})

	it('übergeht bereits behandelte Ereignisse und die Eingabe per Eingabemethoden-Editor', () => {
		expect(escapeClosesModal(ev({ defaultPrevented: true }))).toBe(false)
		expect(escapeClosesModal(ev({ isComposing: true }))).toBe(false)
	})
})
