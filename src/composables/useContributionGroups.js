import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

const state = reactive({
	groups: [],
})

async function loadContributionGroups() {
	try {
		const { data } = await api.listContributionGroups()
		state.groups = data
	} catch (e) { showError(errMsg(e, 'Beitragsgruppen konnten nicht geladen werden')) }
}

export function useContributionGroups() {
	return { state, loadContributionGroups }
}
