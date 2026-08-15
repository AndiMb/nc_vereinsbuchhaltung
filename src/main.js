import { createApp } from 'vue'
import App from './App.vue'
import { usePermissions } from './composables/usePermissions.js'
import { loadAppTranslations, n, t } from './lib/l10n.js'

// Globale .vbh-* Utility-Styles (frueher scoped in App.vue). Global, damit auch
// ausgelagerte Kindkomponenten sie nutzen koennen; alle Selektoren sind
// .vbh-*-praefigiert (bzw. .vbh-table-qualifiziert) und lecken daher nicht in
// die Nextcloud-UI.
import './styles.css'

// Diagnose fuer intermittierende, bislang nicht reproduzierbare Fehler (siehe z.B. den seit
// v0.10.47 bekannten Patch-Fehler beim Speichern einer Berechtigung): app.config.errorHandler
// faengt Fehler aus render()/Lifecycle-Hooks/Watchern ab, aber nicht alles, was ausserhalb des
// Vue-Callstacks passiert (z.B. asynchrone Handler ohne eigenes try/catch) - das landet als
// "window error" oder - da Vues nextTick intern auf Promises basiert - als "unhandledrejection".
// Alle drei Wege werden hier erfasst und inkl. der letzten Berechtigungs-Zustandswechsel in
// localStorage("vbh_last_crash") gesichert, damit die Daten auch ueberleben, wenn niemand die
// Konsole offen hatte.
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
	} catch { /* Diagnose darf niemals selbst einen Fehler werfen */ }
}
window.addEventListener('error', (e) => recordUnexpectedError('window-error', e.error))
window.addEventListener('unhandledrejection', (e) => recordUnexpectedError('unhandledrejection', e.reason))

// Uebersetzungen fuer die aktuelle Sprache laden, bevor gemountet wird - sonst
// blitzt beim ersten Render kurz der deutsche Quelltext auf und wird dann durch
// die uebersetzte Fassung ersetzt.
loadAppTranslations().finally(() => {
	const app = createApp(App)
	app.mixin({ methods: { t, n } })
	app.config.errorHandler = (err) => recordUnexpectedError('vue-error-handler', err)
	app.mount('#vereinsbuchhaltung-app')
})
