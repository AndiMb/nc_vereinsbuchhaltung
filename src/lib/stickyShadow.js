// Schatten an einer per CSS festgehaltenen Knopfleiste (position: sticky;
// bottom: 0), solange darunter noch Inhalt liegt.
//
// Gebraucht im Buchungsdialog: Speichern/Abbrechen sollen immer sichtbar
// sein, auch wenn das Formular laenger ist als der Dialog. Ohne Schatten sieht
// man der Leiste nicht an, dass Inhalt unter ihr weiterlaeuft.
//
// Gemessen wird ueber einen unsichtbaren Marker direkt VOR der Leiste: er
// steht dort, wo die Leiste ohne sticky stuende. Ist er sichtbar, ist bis
// ganz nach unten gescrollt - kein Schatten. Liegt er ausserhalb des
// sichtbaren Bereichs, schwebt die Leiste ueber dem Inhalt - Schatten an.
// root: null genuegt, der IntersectionObserver beruecksichtigt das Abschneiden
// durch scrollende Vorfahren (hier der Inhalt von NcModal) von selbst.

const CLASS = 'is-stuck'

export const stickyShadow = {
	mounted(el) {
		if (typeof IntersectionObserver === 'undefined') { return }
		const marker = document.createElement('div')
		marker.setAttribute('aria-hidden', 'true')
		marker.style.height = '1px'
		marker.style.marginBottom = '-1px'
		el.parentNode.insertBefore(marker, el)
		const observer = new IntersectionObserver(([entry]) => {
			el.classList.toggle(CLASS, !entry.isIntersecting)
		})
		observer.observe(marker)
		el._stickyShadow = { marker, observer }
	},

	unmounted(el) {
		const s = el._stickyShadow
		if (!s) { return }
		s.observer.disconnect()
		s.marker.remove()
		delete el._stickyShadow
	},
}
