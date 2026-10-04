import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

const state = reactive({
	claims: [],
})

async function loadClaims() {
	try {
		const { data } = await api.listClaims()
		state.claims = data
	} catch (e) { showError(errMsg(e, t('Forderungen konnten nicht geladen werden'))) }
}

export function useClaims() {
	return { state, loadClaims }
}
