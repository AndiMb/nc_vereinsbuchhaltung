import Vue from 'vue'
import App from './App.vue'
import { usePermissions } from './composables/usePermissions.js'
// Globale .vbh-* Utility-Styles (frueher scoped in App.vue). Global, damit auch
// ausgelagerte Kindkomponenten sie nutzen koennen; alle Selektoren sind
// .vbh-*-praefigiert (bzw. .vbh-table-qualifiziert) und lecken daher nicht in
// die Nextcloud-UI. NcButton-/NcSelect-piercende ::v-deep-Regeln bleiben scoped
// in App.vue.
import './styles.css'

// Diagnose fuer intermittierende, bislang nicht reproduzierbare Fehler (siehe z.B. den seit
// v0.10.47 bekannten Patch-Fehler beim Speichern einer Berechtigung): Vue 2 faengt Fehler aus
// dem internen Patch-/Diff-Zyklus NICHT ueber errorCaptured/Vue.config.errorHandler ab (das
// deckt nur render()/Lifecycle-Hooks ab), sie landen als "window error" oder - da Vues
// nextTick intern auf Promises basiert - als "unhandledrejection". Beides wird hier erfasst
// und inkl. der letzten Berechtigungs-Zustandswechsel in localStorage("vbh_last_crash")
// gesichert, damit die Daten auch ueberleben, wenn niemand die Konsole offen hatte.
function recordUnexpectedError(kind, err) {
	try {
		const entry = {
			time: new Date().toISOString(),
			kind,
			message: err && err.message ? err.message : String(err),
			stack: err && err.stack ? err.stack : null,
			permissionsHistory: usePermissions().debugHistory,
		}
		// eslint-disable-next-line no-console
		console.error('[Vereinsbuchhaltung] Unerwarteter Fehler erfasst (Details auch in localStorage "vbh_last_crash"):', entry)
		window.localStorage.setItem('vbh_last_crash', JSON.stringify(entry))
	} catch (e) { /* Diagnose darf niemals selbst einen Fehler werfen */ }
}
window.addEventListener('error', e => recordUnexpectedError('window-error', e.error))
window.addEventListener('unhandledrejection', e => recordUnexpectedError('unhandledrejection', e.reason))

Vue.mixin({ methods: { t, n } })

const View = Vue.extend(App)
new View().$mount('#vereinsbuchhaltung-app')
