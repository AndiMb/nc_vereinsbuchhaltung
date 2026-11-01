import { reactive } from 'vue'
import api from '../api.js'

const state = reactive({
	syncRevision: null,
	// Zeitpunkt, zu dem der eigene Stand zuletzt nachweislich mit dem Server
	// uebereinstimmte. Alles, was seitdem geschrieben wurde, kann die jetzt
	// erkannte Aenderung erklaeren - daran haengt, ob sie als eigene gilt.
	syncSeenAt: 0,
	// Der syncSeenAt-Wert von *vor* der zuletzt erkannten Aenderung.
	syncChangedSince: 0,
})

// 'busy' wird als Parameter übergeben statt hier gehalten, weil es App.vue-weit
// als allgemeines Ladeflag genutzt wird (Import, Reset, Demo-Seed, ...), nicht nur
// beim Sync. Bei busy=true wird syncRevision bewusst NICHT aktualisiert, damit der
// nächste Poll die Änderung erneut erkennt.
async function checkRemoteRevision(init, busy) {
	let rev
	try {
		rev = (await api.revision()).data.revision
	} catch {
		return 'error'
	}
	if (init || state.syncRevision === null) {
		state.syncRevision = rev
		state.syncSeenAt = Date.now()
		return 'unchanged'
	}
	if (rev === state.syncRevision) {
		state.syncSeenAt = Date.now()
		return 'unchanged'
	}
	// Bei busy bleibt auch syncSeenAt stehen: der naechste Poll soll dieselbe
	// Aenderung erneut erkennen - und dann noch wissen, seit wann sie aussteht.
	if (busy) { return 'busy' }
	state.syncRevision = rev
	state.syncChangedSince = state.syncSeenAt
	state.syncSeenAt = Date.now()
	return 'changed'
}

/**
 * Nach einem eigenen Schreibzugriff: Lag vor ihm genau der Stand, den dieser
 * Browser zuletzt kannte, stammt der neue Stand allein von uns – die Handler
 * laden selbst nach, der nächste Abgleich muss das nicht noch einmal tun
 * (bisher lud er die ganze Ansicht ein zweites Mal). Lag ein anderer Stand
 * davor, hat zwischendurch jemand anderes geschrieben: dann bleibt der
 * bekannte Stand stehen und der Abgleich lädt wie gehabt nach.
 *
 * Restrisiko: Schreiben zwei Browser im selben Augenblick, lesen beide
 * denselben Vorstand, und der zweite übernimmt seinen Stand, ohne die
 * Änderung des ersten zu sehen. Seine Ansicht hängt dann bis zur nächsten
 * Änderung oder zum Neuladen nach; Schreibkonflikte fängt weiterhin die
 * Versionsprüfung (HTTP 409) ab.
 *
 * @param {string} previous Stand vor dem Schreibzugriff
 * @param {string} current Stand danach
 */
function adoptOwnWrite(previous, current) {
	if (state.syncRevision === null || previous !== state.syncRevision) { return }
	state.syncRevision = current
	state.syncSeenAt = Date.now()
}

api.onRevisionBump(adoptOwnWrite)

export function useSync() {
	return { state, checkRemoteRevision, adoptOwnWrite }
}
