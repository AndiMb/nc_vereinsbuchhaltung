import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'
import { createSharedLoader } from '../lib/sharedLoader.js'

/**
 * Mitglieder-Stammdaten (Spec §2.2): geteilter Zustand, analog
 * useMandates.js.
 */
const state = reactive({
	members: [],
})

const load = createSharedLoader(async (isCurrent) => {
	try {
		const { data } = await api.listMembers()
		if (isCurrent()) { state.members = data }
		return data
	} catch (e) {
		showError(errMsg(e, t('Mitglieder konnten nicht geladen werden')))
		return null
	}
})

/** Ohne `force` teilen parallele Aufrufe eine Abfrage; nach Schreibzugriffen `{ force: true }`. */
function loadMembers(opts) {
	return load(opts)
}

export function useMembers() {
	return { state, loadMembers }
}
