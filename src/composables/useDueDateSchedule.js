import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

/**
 * Der Terminplan (Spec §2.2/§3.5, Issue #70): je Turnus ein
 * Standard-Einzugstag (als Tage-Versatz zum Periodenbeginn, siehe
 * DueDateScheduleService-Klassendoc) + überschreibbare Zeilen je
 * Periodenindex, plus die Fristen vor dem Einzug (Vorwarnfenster D-21,
 * Vorabinfo-Vorlauf D-14, Freigabe-Vorlauf D-5), die die Nextcloud-Einstellungen pflegen.
 */
const state = reactive({
	schedule: {},
	prenotificationLeadDays: 14,
	warningLeadDays: 21,
	releaseLeadDays: 5,
})

async function loadDueDateSchedule() {
	try {
		const { data } = await api.loadDueDateSchedule()
		state.schedule = data.schedule
		state.prenotificationLeadDays = data.prenotificationLeadDays
		state.warningLeadDays = data.warningLeadDays
		state.releaseLeadDays = data.releaseLeadDays ?? 5
	} catch (e) { showError(errMsg(e, t('Terminplan konnte nicht geladen werden'))) }
}

export function useDueDateSchedule() {
	return { state, loadDueDateSchedule }
}
