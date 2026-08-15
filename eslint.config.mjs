import { recommendedJavascript } from '@nextcloud/eslint-config'
import pluginVue from 'eslint-plugin-vue'
import pluginJsdoc from 'eslint-plugin-jsdoc'
import pluginStylistic from '@stylistic/eslint-plugin'

export default [
	...recommendedJavascript,
	{
		// Eigene Regeln in einem eigenen Konfigurationsobjekt: in der Flat-Config
		// muessen Plugins innerhalb desselben Objekts registriert sein, in dem
		// ihre Regeln ueberschrieben werden - ererben von recommendedJavascript
		// reicht dafuer nicht.
		plugins: {
			vue: pluginVue,
			jsdoc: pluginJsdoc,
			'@stylistic': pluginStylistic,
		},
		rules: {
			'vue/no-unused-vars': 'warn',

			// Git speichert die Dateien mit LF (verifiziert per `git show`); auf
			// Windows-Checkouts mit core.autocrlf=true liegen sie im Arbeitsbaum
			// aber als CRLF vor, ohne dass das je committet wuerde. Die Regel
			// wuerde also nur lokale Windows-Entwicklung gegen ein Artefakt der
			// Checkout-Konvertierung kaempfen lassen, das Git ohnehin schon regelt.
			'@stylistic/linebreak-style': 'off',

			// Das Projekt schreibt bewusst kompakte Einzeiler (if (x) { y },
			// catch (e) { /* Kommentar */ }, kurze Arrow-Bodies) statt einer
			// Anweisung pro Zeile - durchgaengiger Stil in allen Composables und
			// Komponenten. Die Regel wuerde hunderte Stellen rein mechanisch
			// umbrechen, ohne dass sich an der Semantik etwas aendert.
			'@stylistic/max-statements-per-line': 'off',

			// Event-Namen sind im ganzen Projekt bewusst kebab-case
			// ('update:booking-view', 'go-open-items' usw.) - funktional
			// gleichwertig zu camelCase (Vue matcht beides), und eine
			// Umbenennung aller Emitter/Listener-Paare waere ein reiner
			// Stil-Umbau ohne Bezug zur Vue-3-Migration.
			'vue/custom-event-name-casing': 'off',

			// Dokumentiert wird in diesem Projekt in Prosa oberhalb der Funktion
			// (auf Deutsch, mit Begruendung) statt in JSDoc-Tags. Die Regeln wuerden
			// nur leere @param-Gerueste erzwingen, die nichts erklaeren.
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/require-param': 'off',
			'jsdoc/require-param-type': 'off',
			'jsdoc/require-param-description': 'off',
		},
	},
]
