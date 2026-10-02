import { showError } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

const state = reactive({
	assignments: [],
})

async function loadAssignments() {
	try {
		const { data } = await api.listAssignments()
		state.assignments = data
	} catch (e) { showError(errMsg(e, 'Zuweisungen konnten nicht geladen werden')) }
}

export function useAssignments() {
	return { state, loadAssignments }
}
