import { reactive } from 'vue'
import { showError } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Mitgliedsbeiträge mit Zahlungsfrequenz: geteilter Zustand, analog
 * useSepaMandates.js. Rein optionales Zusatzmodul.
 */
const state = reactive({
	membershipFees: [],
})

async function loadMembershipFees() {
	try {
		const { data } = await api.listMembershipFees()
		state.membershipFees = data
		return data
	} catch (e) {
		showError(errMsg(e, 'Mitgliedsbeiträge konnten nicht geladen werden'))
		return null
	}
}

export function useMembershipFees() {
	return { state, loadMembershipFees }
}
