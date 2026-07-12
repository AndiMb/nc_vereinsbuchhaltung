import { reactive } from 'vue'
import api from '../api.js'

const state = reactive({
	syncRevision: null,
})

// 'busy' wird als Parameter übergeben statt hier gehalten, weil es App.vue-weit
// als allgemeines Ladeflag genutzt wird (Import, Reset, Demo-Seed, ...), nicht nur
// beim Sync. Bei busy=true wird syncRevision bewusst NICHT aktualisiert, damit der
// nächste Poll die Änderung erneut erkennt.
async function checkRemoteRevision(init, busy) {
	let rev
	try {
		rev = (await api.revision()).data.revision
	} catch (e) {
		return 'error'
	}
	if (init || state.syncRevision === null) {
		state.syncRevision = rev
		return 'unchanged'
	}
	if (rev === state.syncRevision) return 'unchanged'
	if (busy) return 'busy'
	state.syncRevision = rev
	return 'changed'
}

export function useSync() {
	return { state, checkRemoteRevision }
}
