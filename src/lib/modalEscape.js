// Escape schliesst jeden Dialog - mit dem Fokus in einem Textfeld, auf einem
// Knopf oder auf dem Dialog selbst. NcModal (@nextcloud/vue 9.11) registriert
// Escape ueber useHotKey, und das greift nicht verlaesslich:
//  - shouldIgnoreEvent() verwirft Tasten aus <input>, <textarea> und <select>
//    noch BEVOR die Option allowInModal gilt - dabei oeffnen unsere Dialoge
//    genau mit dem Fokus im ersten Feld (focusOnOpen);
//  - der Handler schliesst nur, wenn der Fokusfang des Dialogs der oberste ist.
//    Der wird erst nach der Oeffnen-Animation aktiv; ein Escape davor, oder
//    wenn der Fokus auf der Maske oder noch hinter dem Dialog liegt, bleibt
//    wirkungslos.
// Dieser Bruecken-Listener klickt deshalb den Schliessen-Knopf des obersten
// Dialogs; er respektiert damit `noClose` und das close-Ereignis der Komponente.
// Danach stoppt er das Ereignis, damit NcModal nicht zusaetzlich schliesst.
//
// Dafuer muss er vor NcModal an der Reihe sein. useHotKey lauscht in der
// Capture-Phase am window; die Bruecke tut dasselbe und wird beim App-Start
// registriert, also vor jedem Dialog. Liefe sie spaeter, schloesse NcModal
// bei einem Escape waehrend der Oeffnen-Animation eines Dialogs den Dialog
// darunter: dessen Fokusfang ist dann noch der oberste.
//
// Ausnahmen, die Escape selbst behandeln: aufgeklappte Auswahllisten und
// Datumsfelder (erst das Popup, mit dem naechsten Escape der Dialog) sowie
// Felder mit `data-vbh-own-escape`, die damit etwa eine Bearbeitung abbrechen.
// In der Capture-Phase laeuft die Bruecke vor deren eigenem Handler und kann
// nicht an `defaultPrevented` ablesen, dass er Escape schon verbraucht hat.

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

const POPUP_SELECTOR = '.v-select, .vs__dropdown-menu, .mx-datepicker, .v-popper__popper, [aria-expanded="true"], [data-vbh-own-escape]'
const CLOSE_SELECTOR = '.modal-container__close, .header-close'

/**
 * Haengt den Bruecken-Listener ans Fenster (einmal beim App-Start, vor dem
 * ersten Dialog).
 *
 * @param {Document} [doc] Dokument, Standard das der Seite
 */
export function installModalEscape(doc = document) {
	doc.defaultView.addEventListener('keydown', (event) => {
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
		if (!top) { return }
		// Liegt der Fokus noch hinter dem Dialog - auf der Seite oder auf dem Knopf im Dialog darunter, der ihn
		// geoeffnet hat (der Fokusfang lief noch nicht an) -, schliesst Escape trotzdem den obersten.
		const closeButton = top.querySelector(CLOSE_SELECTOR)
		if (!closeButton) { return }
		event.preventDefault()
		event.stopImmediatePropagation()
		closeButton.click()
	}, true)
}
