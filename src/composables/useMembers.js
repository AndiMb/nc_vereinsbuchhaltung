import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

/**
 * Mitglieder-Stammdaten (Spec §2.2): geteilter Zustand, analog
 * useMandates.js.
 */
const state = reactive({
	members: [],
})

// Parallele Aufrufe teilen sich eine Abfrage (Muster useAttachmentInbox.js).
let inflight = null

function loadMembers() {
	if (!inflight) {
		inflight = (async () => {
			try {
				const { data } = await api.listMembers()
				state.members = data
				return data
			} catch (e) {
				showError(errMsg(e, t('Mitglieder konnten nicht geladen werden')))
				return null
			} finally {
				inflight = null
			}
		})()
	}
	return inflight
}

export function useMembers() {
	return { state, loadMembers }
}
