import { reactive } from 'vue'
import api from '../api.js'

/**
 * Auto-Zuordnungsregeln: geteilter Zustand, analog useCostCenters.js. Vorher
 * hielt App.vue die Liste lokal und reichte sie als Prop an SettingsRules.vue
 * durch - seit dem Umzug nach RulesPanel.vue (Unterreiter „Regeln" von
 * BookingsTab.vue, siehe NAVIGATION-KONZEPT.md Abschnitt 4) braucht auch
 * App.vue selbst nur noch lesenden Zugriff (computeSuggestion()).
 */
const state = reactive({
	rules: [],
})

async function loadRules() {
	try {
		const { data } = await api.listRules()
		state.rules = data
		return data
	} catch {
		// Regeln sind ein optionales Komfortfeature - kein Fehlerbanner, wenn
		// der Endpunkt (noch) nichts liefert.
		return null
	}
}

export function useRules() {
	return { state, loadRules }
}
