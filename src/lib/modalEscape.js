// Escape schliesst jeden Dialog - mit dem Fokus in einem Textfeld, auf einem
// Knopf oder auf dem Dialog selbst. NcModal (@nextcloud/vue 9.11) registriert
// Escape ueber useHotKey, und das greift nicht verlaesslich:
//  - shouldIgnoreEvent() verwirft Tasten aus <input>, <textarea> und <select>
//    noch BEVOR die Option allowInModal gilt - dabei oeffnen unsere Dialoge
//    genau mit dem Fokus im ersten Feld (focusOnOpen);
//  - der Handler schliesst nur, wenn der Fokusfang des Dialogs der oberste ist.
//    Der wird erst nach der Oeffnen-Animation aktiv; ein Escape davor, oder
//    wenn der Fokus auf der Maske liegt, bleibt wirkungslos.
// Dieser Bruecken-Listener klickt deshalb den Schliessen-Knopf des obersten
// Dialogs; er respektiert damit `noClose` und das close-Ereignis der Komponente.
// Danach stoppt er das Ereignis, damit NcModal nicht zusaetzlich schliesst.
//
// Ausnahmen, die Escape selbst behandeln: aufgeklappte Auswahllisten und
// Datumsfelder (erst das Popup, mit dem naechsten Escape der Dialog).

/**
 * Ob dieser Tastendruck den obersten Dialog schliessen soll.
 *
 * @param {{ key: string, defaultPrevented?: boolean, isComposing?: boolean, insidePopup?: boolean }} ev reduzierte Ereignisdaten
 * @return {boolean}
 */
export function escapeClosesModal(ev) {
	return ev.key === 'Escape'
		&& !ev.defaultPrevented
		&& !ev.isComposing
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
		const wanted = escapeClosesModal({
			key: event.key,
			defaultPrevented: event.defaultPrevented,
			isComposing: event.isComposing,
			insidePopup: Boolean(target?.closest?.(POPUP_SELECTOR)),
		})
		if (!wanted) { return }

		const masks = [...doc.querySelectorAll('.modal-mask')].filter((mask) => mask.checkVisibility?.() ?? true)
		const top = masks.at(-1)
		if (!top?.contains(target)) { return }
		const closeButton = top.querySelector(CLOSE_SELECTOR)
		if (!closeButton) { return }
		event.preventDefault()
		event.stopImmediatePropagation()
		closeButton.click()
	})
}
