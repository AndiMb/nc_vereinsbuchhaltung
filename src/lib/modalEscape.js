// Escape schliesst jeden Dialog - auch mit dem Cursor in einem Textfeld.
// NcModal (@nextcloud/vue 9.11) registriert Escape ueber useHotKey, und dessen
// shouldIgnoreEvent() verwirft Tasten aus <input>, <textarea> und <select>
// noch BEVOR die Option allowInModal gilt. Unsere Dialoge oeffnen aber genau
// mit dem Fokus im ersten Feld (focusOnOpen): Escape direkt nach dem Oeffnen
// bliebe wirkungslos. Dieser Bruecken-Listener klickt in dem Fall den
// Schliessen-Knopf des obersten Dialogs; er respektiert damit `noClose` und
// das close-Ereignis der Komponente.
//
// Ausnahmen, die Escape selbst behandeln: aufgeklappte Auswahllisten und
// Datumsfelder (erst das Popup, mit dem naechsten Escape der Dialog).

const TEXT_TARGETS = ['INPUT', 'TEXTAREA', 'SELECT']

/**
 * Ob ein Tastendruck den obersten Dialog schliessen soll, weil NcModal ihn
 * aus einem Eingabefeld heraus ignoriert.
 *
 * @param {{ key: string, defaultPrevented?: boolean, isComposing?: boolean, targetTag?: string, insidePopup?: boolean }} ev reduzierte Ereignisdaten
 * @return {boolean}
 */
export function escapeNeedsBridge(ev) {
	return ev.key === 'Escape'
		&& !ev.defaultPrevented
		&& !ev.isComposing
		&& TEXT_TARGETS.includes(ev.targetTag ?? '')
		&& !ev.insidePopup
}

const POPUP_SELECTOR = '.v-select, .vs__dropdown-menu, .mx-datepicker, [aria-expanded="true"]'
const CLOSE_SELECTOR = '.modal-container__close, .header-close'

/**
 * Haengt den Bruecken-Listener ans Dokument (einmal beim App-Start).
 *
 * @param {Document} [doc] Dokument, Standard das der Seite
 */
export function installModalEscape(doc = document) {
	doc.addEventListener('keydown', (event) => {
		const target = event.target
		const needsBridge = escapeNeedsBridge({
			key: event.key,
			defaultPrevented: event.defaultPrevented,
			isComposing: event.isComposing,
			targetTag: target?.tagName,
			insidePopup: Boolean(target?.closest?.(POPUP_SELECTOR)),
		})
		if (!needsBridge) { return }

		const masks = [...doc.querySelectorAll('.modal-mask')].filter((mask) => mask.checkVisibility?.() ?? true)
		const top = masks.at(-1)
		if (!top?.contains(target)) { return }
		const closeButton = top.querySelector(CLOSE_SELECTOR)
		if (!closeButton) { return }
		event.preventDefault()
		closeButton.click()
	})
}
