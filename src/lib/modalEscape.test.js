import { describe, expect, it, vi } from 'vitest'
import { escapeClosesModal, installModalEscape } from './modalEscape.js'

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

describe('installModalEscape', () => {
	// Ein Dialog-Stapel aus Akte (unten) und Rückfrage (oben); window und
	// document als einfache EventTargets, ein Tastendruck läuft wie im Browser
	// erst durch die Capture-Phase am window, dann durch das document.
	function setup() {
		const mask = () => {
			const close = { click: vi.fn() }
			return { close, contains: () => false, querySelector: () => close, checkVisibility: () => true }
		}
		const akte = mask()
		const rueckfrage = mask()
		const masks = [akte, rueckfrage]
		const win = new EventTarget()
		const doc = Object.assign(new EventTarget(), { querySelectorAll: () => [...masks], defaultView: win })
		installModalEscape(doc)
		const press = () => {
			const event = (type) => Object.defineProperty(new Event(type, { cancelable: true }), 'key', { value: 'Escape' })
			win.dispatchEvent(event('keydown'))
			doc.dispatchEvent(event('keydown'))
		}
		return { akte, rueckfrage, masks, win, press }
	}

	it('schließt den obersten Dialog über dessen Schließen-Knopf', () => {
		const { akte, rueckfrage, press } = setup()
		press()
		expect(rueckfrage.close.click).toHaveBeenCalledTimes(1)
		expect(akte.close.click).not.toHaveBeenCalled()
	})

	// @nextcloud/vue 9.14: NcModal schließt in der Capture-Phase am window, und
	// Vue entfernt den Dialog ohne Ausblend-Übergang noch im selben Tastendruck.
	// Die Akte darunter ist dann nicht „der oberste Dialog“ dieses Tastendrucks.
	it('schließt nicht zusätzlich den Dialog darunter, wenn NcModal den obersten schon entfernt hat', () => {
		const { akte, masks, win, press } = setup()
		win.addEventListener('keydown', () => masks.pop(), true)
		press()
		expect(akte.close.click).not.toHaveBeenCalled()
	})
})
