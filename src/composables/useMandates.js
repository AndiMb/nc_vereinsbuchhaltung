import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * SEPA-Mandate (Issue #66, Tabelle `vbh_mandates`): geteilter Zustand, analog
 * useCostCenters.js.
 */
const state = reactive({
	mandates: [],
})

async function loadMandates() {
	try {
		const { data } = await api.listMandates()
		state.mandates = data
		return data
	} catch (e) {
		showError(errMsg(e, 'Mandate konnten nicht geladen werden'))
		return null
	}
}

export function useMandates() {
	return { state, loadMandates }
}
