import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'
import { createSharedLoader } from '../lib/sharedLoader.js'

const state = reactive({
	assignments: [],
})

const load = createSharedLoader(async (isCurrent) => {
	try {
		const { data } = await api.listAssignments()
		if (isCurrent()) { state.assignments = data }
		return undefined
	} catch (e) {
		showError(errMsg(e, t('Zuweisungen konnten nicht geladen werden')))
		return undefined
	}
})

/** Ohne `force` teilen parallele Aufrufe eine Abfrage; nach Schreibzugriffen `{ force: true }`. */
function loadAssignments(opts) {
	return load(opts)
}

export function useAssignments() {
	return { state, loadAssignments }
}
