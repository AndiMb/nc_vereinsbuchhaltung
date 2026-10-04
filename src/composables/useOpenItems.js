import { showError } from '@nextcloud/dialogs'
import { computed, reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'
import { isClaimItem } from '../lib/openItems.js'

// Dieselbe Liste enthält freie Posten und die Forderungen des Beitragsmoduls
// (memberId/type gesetzt); `isClaim` unterscheidet sie für die Ansicht - nur die
// freien lassen sich in der generischen Sicht bearbeiten (Issue #121).
const state = reactive({
	openItems: [],
})

const overdueCount = computed(() => state.openItems.filter((o) => o.overdue).length)

async function loadOpenItems() {
	try {
		const { data } = await api.listOpenItems()
		state.openItems = data
	} catch (e) { showError(errMsg(e, t('Offene Posten konnten nicht geladen werden'))) }
}

export function useOpenItems() {
	return { state, overdueCount, loadOpenItems, isClaim: isClaimItem }
}
