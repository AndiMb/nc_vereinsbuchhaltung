import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { usePeriods } from './usePeriods.js'

const state = reactive({
	balances: null,
	prevBalances: null,
	sphereData: null,
})

const periods = usePeriods()

async function loadBalances() {
	try {
		const { data } = await api.balances(periods.state.selectedPeriodId)
		state.balances = data
	} catch (e) { showError(errMsg(e, 'Auswertung konnte nicht geladen werden')) }

	// Der Zeitraum davor für den Kennzahlen-Vergleich (still im Hintergrund,
	// Fehler ignorieren). Bis 0.32.0 stand hier schlicht `jahr - 1`; welcher
	// Zeitraum der vorherige ist, weiß seit Issue #8 nur die Liste der
	// Geschäftsjahre – beim ersten gibt es gar keinen.
	const previous = periods.previousOf(periods.selectedPeriod.value)
	if (previous) {
		try {
			const { data } = await api.balances(previous.id)
			state.prevBalances = data
		} catch { state.prevBalances = null }
	} else {
		state.prevBalances = null
	}
}

/** @return {Promise<object|null>} der Sphären-Bericht, oder null bei Fehler (bereits als Toast gemeldet) */
async function loadSphereReport() {
	try {
		const { data } = await api.sphereReport(periods.state.selectedPeriodId)
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
