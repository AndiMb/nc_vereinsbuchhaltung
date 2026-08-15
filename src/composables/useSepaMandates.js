import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * SEPA-Lastschriftmandate: geteilter Zustand, analog useCostCenters.js.
 * Rein optionales Zusatzmodul – bleibt leer, solange niemand ein Mandat anlegt.
 */
const state = reactive({
	sepaMandates: [],
})

async function loadSepaMandates() {
	try {
		const { data } = await api.listSepaMandates()
		state.sepaMandates = data
		return data
	} catch (e) {
		showError(errMsg(e, 'SEPA-Mandate konnten nicht geladen werden'))
		return null
	}
}

export function useSepaMandates() {
	return { state, loadSepaMandates }
}
