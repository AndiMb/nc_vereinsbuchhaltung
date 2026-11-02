import { createApp } from 'vue'
import SettingsApp from './SettingsApp.vue'
import { n, t, tc, tRaw } from './lib/l10n.js'
import { installStackTables } from './lib/stackTables.js'

import './publicPath.js'
import './toast-position.css'
// Globale .vbh-* Utility-Styles, siehe main.js - dieselbe Begruendung gilt
// hier: die Einstellungsseite nutzt dieselben Komponenten (SettingsClub.vue
// & Co.) und damit dieselben Klassen.
import './styles.css'

installStackTables()

// Die Uebersetzungen sind schon da: Nextcloud bindet l10n/<sprache>.js vor
// diesem Skript ein (siehe lib/l10n.js).
const app = createApp(SettingsApp)
app.mixin({ methods: { t, n, tc, tRaw } })
app.mount('#vereinsbuchhaltung-settings')
