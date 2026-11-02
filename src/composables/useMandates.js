import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

/**
 * SEPA-Mandate (Issue #66, Tabelle `vbh_mandates`): geteilter Zustand, analog
 * useCostCenters.js.
 */
const state = reactive({
	mandates: [],
})

// Parallele Aufrufe teilen sich eine Abfrage (Muster useAttachmentInbox.js).
let inflight = null

function loadMandates() {
	if (!inflight) {
		inflight = (async () => {
			try {
				const { data } = await api.listMandates()
				state.mandates = data
				return data
			} catch (e) {
				showError(errMsg(e, t('Mandate konnten nicht geladen werden')))
				return null
			} finally {
				inflight = null
			}
		})()
	}
	return inflight
}

export function useMandates() {
	return { state, loadMandates }
}
