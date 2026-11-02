import { computed, reactive } from 'vue'
import api from '../api.js'
import { takeBootState } from '../lib/bootState.js'

// Singleton-Zustand: EIN gemeinsamer Datenbestand je Installation
// (Application::BOOK), alle Komponenten teilen sich denselben reaktiven
// Zustand – deshalb reicht dieses Modul-Singleton statt Vuex/Pinia.
const state = reactive({
	me: null,
})

const canRead = computed(() => !!(state.me && state.me.canRead))
const canWrite = computed(() => !!(state.me && state.me.canWrite))
const isAdmin = computed(() => !!(state.me && state.me.isAdmin))
// Auslagenerstattung: die Verwaltungssicht unter „Buchungen“ gibt es, solange die Funktion an ist oder Anträge bestehen.
const reimbursementsStaffVisible = computed(() => !!(state.me && state.me.reimbursement && state.me.reimbursement.staffVisible))

async function loadMe() {
	// Beim Start bringt die Seite den Wert mit (Initial State, Issue #264).
	const boot = takeBootState('me')
	if (boot) {
		state.me = boot
		return state.me
	}
	try {
		const { data } = await api.me()
		state.me = data
	} catch {
		state.me = { role: 'none', canRead: false, canWrite: false, isAdmin: false }
	}
	return state.me
}

export function useAuth() {
	return { state, canRead, canWrite, isAdmin, reimbursementsStaffVisible, loadMe }
}
