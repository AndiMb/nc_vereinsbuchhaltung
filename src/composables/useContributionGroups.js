import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

const state = reactive({
	groups: [],
})

// Parallele Aufrufe teilen sich eine Abfrage (Muster useAttachmentInbox.js).
let inflight = null

function loadContributionGroups() {
	if (!inflight) {
		inflight = (async () => {
			try {
				const { data } = await api.listContributionGroups()
				state.groups = data
			} catch (e) {
				showError(errMsg(e, t('Beitragsgruppen konnten nicht geladen werden')))
			} finally {
				inflight = null
			}
		})()
	}
	return inflight
}

export function useContributionGroups() {
	return { state, loadContributionGroups }
}
