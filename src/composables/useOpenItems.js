import { reactive, computed } from 'vue'
import { showError } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

const state = reactive({
	openItems: [],
})

const overdueCount = computed(() => state.openItems.filter(o => o.overdue).length)

async function loadOpenItems() {
	try {
		const { data } = await api.listOpenItems()
		state.openItems = data
	} catch (e) { showError(errMsg(e, 'Offene Posten konnten nicht geladen werden')) }
}

export function useOpenItems() {
	return { state, overdueCount, loadOpenItems }
}
