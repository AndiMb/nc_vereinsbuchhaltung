import { reactive } from 'vue'
import api from '../api.js'

// Der Wächter-Ordner für Belege: die Zahlen für die Übersicht. Wie die
// übrigen Composables ein Modul-Singleton (ein Bestand je Installation).
const state = reactive({
	inbox: { folderMissing: false, capped: false, unassigned: 0, missing: 0 },
})

// Jeder Aufruf kostet serverseitig einen Ordnerscan – parallele Anfragen
// (Fensterfokus plus Nachladen) teilen sich deshalb eine.
let inflight = null

function loadInboxSummary() {
	if (!inflight) {
		inflight = api.attachmentInboxSummary()
			.then(({ data }) => { state.inbox = data })
			.catch(() => { /* die Kacheln bleiben dann einfach aus */ })
			.finally(() => { inflight = null })
	}
	return inflight
}

export function useAttachmentInbox() {
	return { state, loadInboxSummary }
}
