import { reactive } from 'vue'
import { showError } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * SEPA-Sammeleinzüge: geteilter Zustand für Vorschau (fällige offene Posten
 * mit Mandat) und bereits erzeugte Einzüge.
 */
const state = reactive({
	sepaPreview: [],
	sepaBatches: [],
})

async function loadSepaPreview() {
	try {
		const { data } = await api.previewSepaExport()
		state.sepaPreview = data
		return data
	} catch (e) {
		showError(errMsg(e, 'Vorschau konnte nicht geladen werden'))
		return null
	}
}

async function loadSepaBatches() {
	try {
		const { data } = await api.listSepaBatches()
		state.sepaBatches = data
		return data
	} catch (e) {
		showError(errMsg(e, 'SEPA-Einzüge konnten nicht geladen werden'))
		return null
	}
}

export function useSepaBatches() {
	return { state, loadSepaPreview, loadSepaBatches }
}
