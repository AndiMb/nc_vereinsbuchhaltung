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

// Laufende Nummern gegen überholte Antworten – dieselbe Sperre wie in
// useJournal.loadJournal(): mehrere gleichzeitige Abfragen (Watcher, mounted,
// Reiterwechsel) antworten nicht zwingend in der Reihenfolge, in der sie
// gestellt wurden, und eine späte Antwort zum vorigen Zeitraum darf die
// Auswertung des gewählten nicht überschreiben.
let balancesSeq = 0
let sphereSeq = 0

async function loadBalances() {
	const seq = ++balancesSeq
	try {
		const { data } = await api.balances(periods.state.selectedPeriodId)
		if (seq !== balancesSeq) { return }
		state.balances = data
	} catch (e) {
		if (seq !== balancesSeq) { return }
		showError(errMsg(e, 'Auswertung konnte nicht geladen werden'))
	}

	// Der Zeitraum davor für den Kennzahlen-Vergleich (still im Hintergrund,
	// Fehler ignorieren). Bis 0.32.0 stand hier schlicht `jahr - 1`; welcher
	// Zeitraum der vorherige ist, weiß seit Issue #8 nur die Liste der
	// Geschäftsjahre – beim ersten gibt es gar keinen.
	const previous = periods.previousOf(periods.selectedPeriod.value)
	if (previous) {
		try {
			const { data } = await api.balances(previous.id)
			if (seq !== balancesSeq) { return }
			state.prevBalances = data
		} catch { if (seq === balancesSeq) { state.prevBalances = null } }
	} else {
		state.prevBalances = null
	}
}

/** @return {Promise<object|null>} der Sphären-Bericht, oder null bei Fehler (bereits als Toast gemeldet) */
async function loadSphereReport() {
	const seq = ++sphereSeq
	try {
		const { data } = await api.sphereReport(periods.state.selectedPeriodId)
		if (seq !== sphereSeq) { return null }
		state.sphereData = data
		return data
	} catch (e) {
		if (seq !== sphereSeq) { return null }
		showError(errMsg(e, 'Sphären-Bericht konnte nicht geladen werden'))
		return null
	}
}

export function useBalances() {
	return { state, loadBalances, loadSphereReport }
}
