import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'
import { createSharedLoader } from '../lib/sharedLoader.js'

/**
 * SEPA-Mandate (Issue #66, Tabelle `vbh_mandates`): geteilter Zustand, analog
 * useCostCenters.js.
 */
const state = reactive({
	mandates: [],
})

const load = createSharedLoader(async (isCurrent) => {
	try {
		const { data } = await api.listMandates()
		if (isCurrent()) { state.mandates = data }
		return data
	} catch (e) {
		showError(errMsg(e, t('Mandate konnten nicht geladen werden')))
		return null
	}
})

/** Ohne `force` teilen parallele Aufrufe eine Abfrage; nach Schreibzugriffen `{ force: true }`. */
function loadMandates(opts) {
	return load(opts)
}

export function useMandates() {
	return { state, loadMandates }
}
