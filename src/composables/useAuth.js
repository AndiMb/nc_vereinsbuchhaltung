import { reactive, computed } from 'vue'
import api from '../api.js'

// Singleton-Zustand: EIN gemeinsamer Datenbestand je Installation
// (Application::BOOK), alle Komponenten teilen sich denselben reaktiven
// Zustand – deshalb reicht dieses Modul-Singleton statt Vuex/Pinia.
const state = reactive({
	me: null,
})

const canRead = computed(() => !!(state.me && state.me.canRead))
const canWrite = computed(() => !!(state.me && state.me.canWrite))
const isAdmin = computed(() => !!(state.me && state.me.isAdmin))

async function loadMe() {
	try {
		const { data } = await api.me()
		state.me = data
	} catch (e) {
		state.me = { role: 'none', canRead: false, canWrite: false, isAdmin: false }
	}
	return state.me
}

export function useAuth() {
	return { state, canRead, canWrite, isAdmin, loadMe }
}
