import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

const state = reactive({
	permissions: [],
	groups: [],
	users: [],
})

// Rollierender Puffer der letzten Zustandswechsel (nur Metadaten, keine Rollen/Klarnamen) -
// Diagnosehilfe fuer den seit v0.10.47 bekannten, intermittierenden und bislang nicht
// reproduzierbaren Patch-Fehler beim Speichern/Entfernen einer Berechtigung. Wird von
// main.js in den localStorage geschrieben, sobald ein unerwarteter Fehler auftritt.
const debugHistory = []
function recordHistory(label) {
	debugHistory.push({
		t: Date.now(),
		label,
		permissionIds: state.permissions.map((p) => p.id),
	})
	if (debugHistory.length > 20) { debugHistory.shift() }
}

async function loadPermissions() {
	try {
		const [p, g, u] = await Promise.all([api.listPermissions(), api.listGroups(), api.listUsers()])
		state.permissions = p.data
		state.groups = g.data
		state.users = u.data
		recordHistory('loadPermissions')
	} catch (e) { showError(errMsg(e, 'Berechtigungen konnten nicht geladen werden')) }
}

export function usePermissions() {
	return { state, loadPermissions, debugHistory }
}
