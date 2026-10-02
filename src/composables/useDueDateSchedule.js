import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Der Terminplan (Spec §2.2/§3.5, Issue #70): je Turnus ein
 * Standard-Einzugstag (als Tage-Versatz zum Periodenbeginn, siehe
 * DueDateScheduleService-Klassendoc) + überschreibbare Zeilen je
 * Periodenindex, plus die beiden Cron-Abstände (Vorwarnfenster D-21,
 * Vorabinfo-Vorlauf D-14).
 */
const state = reactive({
	schedule: {},
	prenotificationLeadDays: 14,
	warningLeadDays: 21,
})

async function loadDueDateSchedule() {
	try {
		const { data } = await api.loadDueDateSchedule()
		state.schedule = data.schedule
		state.prenotificationLeadDays = data.prenotificationLeadDays
		state.warningLeadDays = data.warningLeadDays
	} catch (e) { showError(errMsg(e, 'Terminplan konnte nicht geladen werden')) }
}

export function useDueDateSchedule() {
	return { state, loadDueDateSchedule }
}
