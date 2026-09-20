import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Mitglieder-Stammdaten (Spec §2.2): geteilter Zustand, analog
 * useSepaMandates.js/useMembershipFees.js.
 */
const state = reactive({
	members: [],
})

async function loadMembers() {
	try {
		const { data } = await api.listMembers()
		state.members = data
		return data
	} catch (e) {
		showError(errMsg(e, 'Mitglieder konnten nicht geladen werden'))
		return null
	}
}

export function useMembers() {
	return { state, loadMembers }
}
