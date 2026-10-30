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
	function setup({ target } = {}) {
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
		// Das Ziel des Tastendrucks lässt sich auf einem nackten EventTarget nicht
		// setzen; `target` ersetzt es für die Prüfung auf eigene Escape-Behandlung.
		const press = () => {
			const event = (type) => {
				const e = Object.defineProperty(new Event(type, { cancelable: true }), 'key', { value: 'Escape' })
				return target ? Object.defineProperty(e, 'target', { value: target }) : e
			}
			const down = event('keydown')
			win.dispatchEvent(down)
			if (!down.cancelBubble) { doc.dispatchEvent(event('keydown')) }
		}
		return { akte, rueckfrage, masks, win, press }
	}

	it('schließt den obersten Dialog über dessen Schließen-Knopf', () => {
		const { akte, rueckfrage, press } = setup()
		press()
		expect(rueckfrage.close.click).toHaveBeenCalledTimes(1)
		expect(akte.close.click).not.toHaveBeenCalled()
	})

	// NcModal lauscht ebenfalls in der Capture-Phase am window, registriert sich
	// aber erst beim Öffnen. Blendet die Rückfrage noch ein, ist ihr Fokusfang
	// nicht aktiv, und die Akte hielte sich für den obersten Dialog und
	// schlösse sich (E2E 57, 4.8). Die Brücke läuft vorher und behält das Wort.
	it('lässt NcModal nicht zusätzlich schließen', () => {
		const { rueckfrage, win, press } = setup()
		const ncModal = vi.fn()
		win.addEventListener('keydown', ncModal, true)
		press()
		expect(rueckfrage.close.click).toHaveBeenCalledTimes(1)
		expect(ncModal).not.toHaveBeenCalled()
	})

	// Der Fokus liegt noch auf dem Knopf in der Akte, der die Rückfrage geöffnet hat.
	it('schließt die Rückfrage, auch wenn der Fokus noch in der Akte darunter liegt', () => {
		const opener = { closest: (sel) => (sel === '.modal-mask' ? opener : null) }
		const { akte, rueckfrage, press } = setup({ target: opener })
		press()
		expect(rueckfrage.close.click).toHaveBeenCalledTimes(1)
		expect(akte.close.click).not.toHaveBeenCalled()
	})

	// Felder, die Escape selbst auswerten (Bearbeiten abbrechen), behalten es;
	// NcModal übergeht Escape aus Eingabefeldern ohnehin.
	it('überlässt Escape Feldern mit eigener Escape-Behandlung', () => {
		const field = { closest: (sel) => (sel.includes('[data-vbh-own-escape]') ? field : null) }
		const { akte, rueckfrage, press } = setup({ target: field })
		press()
		expect(rueckfrage.close.click).not.toHaveBeenCalled()
		expect(akte.close.click).not.toHaveBeenCalled()
	})
})
