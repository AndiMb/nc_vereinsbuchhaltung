import { reactive } from 'vue'
import { showError } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { useYears } from './useYears.js'

const state = reactive({
	balances: null,
	prevBalances: null,
	sphereData: null,
})

const years = useYears()

async function loadBalances() {
	try {
		const { data } = await api.balances(years.state.selectedYear)
		state.balances = data
	} catch (e) { showError(errMsg(e, 'Auswertung konnte nicht geladen werden')) }
	// Vorjahr für den KPI-Vergleich (still im Hintergrund, Fehler ignorieren)
	if (years.state.selectedYear) {
		try {
			const { data } = await api.balances(years.state.selectedYear - 1)
			state.prevBalances = data
		} catch (e) { state.prevBalances = null }
	} else {
		state.prevBalances = null
	}
}

/** @returns {Promise<object|null>} der Sphären-Bericht, oder null bei Fehler (bereits als Toast gemeldet) */
async function loadSphereReport() {
	try {
		const { data } = await api.sphereReport(years.state.selectedYear)
		state.sphereData = data
		return data
	} catch (e) {
		showError(errMsg(e, 'Sphären-Bericht konnte nicht geladen werden'))
		return null
	}
}

export function useBalances() {
	return { state, loadBalances, loadSphereReport }
}
