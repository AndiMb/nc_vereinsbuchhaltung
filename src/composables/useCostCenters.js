import { showError } from '@nextcloud/dialogs'
import { computed, reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Frei definierbare Kostenstellen (Modus „manual"): geteilter Zustand, damit
 * Konto-Dialog und Einstellungen dieselbe Liste sehen, ohne sie durch mehrere
 * Ebenen als Prop zu reichen.
 */
const state = reactive({
	costCenters: [],
})

const costCentersById = computed(() => {
	const map = {}
	for (const cc of state.costCenters) { map[cc.id] = cc }
	return map
})

/** @return {Promise<Array|null>} die geladenen Kostenstellen, oder null bei Fehler (bereits als Toast gemeldet) */
async function loadCostCenters() {
	try {
		const { data } = await api.listCostCenters()
		state.costCenters = data
		return data
	} catch (e) {
		showError(errMsg(e, 'Auswertungsgruppen konnten nicht geladen werden'))
		return null
	}
}

export function useCostCenters() {
	return { state, costCentersById, loadCostCenters }
}
