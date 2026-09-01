import { createApp } from 'vue'
import SettingsApp from './SettingsApp.vue'
import { loadAppTranslations, n, t } from './lib/l10n.js'

import '@nextcloud/dialogs/style.css'
import './toast-position.css'
// Globale .vbh-* Utility-Styles, siehe main.js - dieselbe Begruendung gilt
// hier: die Einstellungsseite nutzt dieselben Komponenten (SettingsClub.vue
// & Co.) und damit dieselben Klassen.
import './styles.css'

// Uebersetzungen fuer die aktuelle Sprache laden, bevor gemountet wird - sonst
// blitzt beim ersten Render kurz der deutsche Quelltext auf und wird dann durch
// die uebersetzte Fassung ersetzt.
loadAppTranslations().finally(() => {
	const app = createApp(SettingsApp)
	app.mixin({ methods: { t, n } })
	app.mount('#vereinsbuchhaltung-settings')
})
