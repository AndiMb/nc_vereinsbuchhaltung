import { reactive } from 'vue'
import { showError } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

const state = reactive({
	permissions: [],
	groups: [],
	users: [],
})

async function loadPermissions() {
	try {
		const [p, g, u] = await Promise.all([api.listPermissions(), api.listGroups(), api.listUsers()])
		state.permissions = p.data
		state.groups = g.data
		state.users = u.data
	} catch (e) { showError(errMsg(e, 'Berechtigungen konnten nicht geladen werden')) }
}

export function usePermissions() {
	return { state, loadPermissions }
}
