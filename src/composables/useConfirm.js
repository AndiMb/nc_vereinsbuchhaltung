import { reactive, computed } from 'vue'

// Die Rueckfrage vor einer nicht umkehrbaren Aktion (loeschen, zuruecksetzen,
// wiedereroeffnen).
//
// Vorher lag der Zustand in App.vue, und jede Komponente, die irgendwo eine
// Rueckfrage brauchte, bekam askConfirm als Funktions-Prop durchgereicht -
// SettingsRules, SettingsCostCenters, SettingsPermissions, SettingsXbucImport,
// SettingsYearClose, ReportsTab. Damit hing eine reine Anzeigefrage an einer
// Kette von Prop-Deklarationen, und jede neue Komponente musste sich in diese
// Kette einreihen. Als gemeinsamer Zustand ruft sie jede Komponente direkt auf.
//
// Absichtlich ein Modul-Singleton wie die uebrigen Composables: es gibt genau
// einen Dialog, und er gehoert keiner einzelnen Komponente.

const state = reactive({
	open: false,
	title: '',
	message: '',
	confirmLabel: 'Löschen',
	confirmVariant: 'error',
})

// Der resolve-Handler der offenen Zusage. Bewusst ausserhalb von reactive():
// Vue muesste ihn sonst in einen Proxy huellen, und aufgerufen wuerde am Ende
// nicht mehr die urspruengliche Funktion.
let pending = null

/**
 * Fragt nach und wartet auf die Antwort.
 *
 * @param {string} title Ueberschrift des Dialogs
 * @param {string} message erklaerender Text
 * @param {string} confirmLabel Beschriftung der bestaetigenden Schaltflaeche
 * @param {string} confirmVariant Farbgebung ('error' fuer Loeschen, sonst 'primary')
 * @return {Promise<boolean>} true, wenn bestaetigt wurde
 */
function askConfirm(title, message, confirmLabel = 'Löschen', confirmVariant = 'error') {
	// Eine bereits offene Rueckfrage abraeumen, statt ihre Zusage liegen zu
	// lassen - ein nie aufgeloestes Promise haelt den Aufrufer fuer immer an.
	if (pending) close(false)
	return new Promise(resolve => {
		pending = resolve
		state.open = true
		state.title = title
		state.message = message
		state.confirmLabel = confirmLabel
		state.confirmVariant = confirmVariant
	})
}

function close(result) {
	state.open = false
	const resolve = pending
	pending = null
	resolve?.(result)
}

const buttons = computed(() => [
	{ label: 'Abbrechen', type: 'secondary', callback: () => close(false) },
	{ label: state.confirmLabel, type: state.confirmVariant, callback: () => close(true) },
])

export function useConfirm() {
	return { confirm: state, confirmButtons: buttons, askConfirm, closeConfirm: close }
}
