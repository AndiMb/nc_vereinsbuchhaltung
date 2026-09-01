import { nextTick } from 'vue'

// Laesst ein <textarea> mit seinem Inhalt wachsen und wieder schrumpfen.
//
// Gebraucht fuer den Buchungstext: als <input> lief laengerer Text seitlich
// aus dem Sichtbaren heraus. Ein <textarea> bricht um, hat aber eine feste
// Zeilenzahl - die Hoehe muss deshalb aus dem Inhalt gemessen werden.
//
// Jede Messung kostet ein erzwungenes Layout (Hoehe auf 'auto', dann
// scrollHeight lesen), und die drei Ausloeser unten feuern teils gemeinsam.
// Deshalb merkt sich die Direktive Wert und Breite der letzten Messung und
// steigt aus, wenn sich beides nicht geaendert hat - alle Ausloeser ausser dem
// ersten werden damit zu einem Vergleich.

function anpassen(el) {
	const s = el && el._autogrow
	// Im geschlossenen Dialog ist das Feld nicht gelayoutet und scrollHeight 0.
	// Dann nichts anfassen und auch nichts merken - sonst stuende beim naechsten
	// Oeffnen die Hoehe 0 fest, bis jemand tippt.
	if (!s || !el.clientWidth) { return }
	if (el.value === s.wert && el.clientWidth === s.breite) { return }
	el.style.height = 'auto'
	// scrollHeight misst den Inhalt samt Innenabstand, aber ohne Rahmen; bei
	// box-sizing: border-box (setzt .vbh-autogrow) zaehlt der zur Hoehe.
	el.style.height = (el.scrollHeight + s.rahmen) + 'px'
	s.wert = el.value
	s.breite = el.clientWidth
}

export const autogrow = {
	mounted(el) {
		const cs = getComputedStyle(el)
		const fit = () => anpassen(el)
		el._autogrow = {
			// Aus statischen Regeln, aendert sich zu Lebzeiten des Feldes nicht.
			rahmen: parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth),
			// Faengt die Aenderungen ohne input-Ereignis ab: das Fenster wird
			// schmaler (derselbe Text braucht mehr Zeilen). Dass er auch auf die
			// selbst gesetzte Hoehe anspringt, faengt der Vergleich oben ab.
			ro: new ResizeObserver(fit),
		}
		el._autogrow.ro.observe(el)
		el.addEventListener('input', fit)
		fit()
	},

	// Faengt zwei Faelle ab, die kein input-Ereignis haben: der Wert kommt von
	// aussen (Buchung zum Bearbeiten geoeffnet) und der Dialog wird sichtbar.
	// nextTick, weil NcModal die Sichtbarkeit per v-show erst im selben Durchlauf
	// umschaltet - vorher waere das Feld noch ungelayoutet und die Messung 0.
	updated(el) {
		nextTick(() => anpassen(el))
	},

	unmounted(el) {
		if (el._autogrow) { el._autogrow.ro.disconnect() }
	},
}
