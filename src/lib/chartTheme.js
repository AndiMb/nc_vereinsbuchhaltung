// Diagrammfarben aus Nextclouds Design-Variablen statt aus einer Theme-Erkennung.
//
// Vorher stand in beiden Diagrammen
// `document.documentElement.classList.contains('theme--dark')`. Diese Klasse
// setzt Nextcloud nirgends - das Theme haengt als data-themes am <body>, das
// <html> traegt nur ng-csp. Der Ausdruck war damit dauerhaft false, beide
// Diagramme zeichneten immer in Schwarz, und im dunklen Design lagen schwarze
// Achsenbeschriftungen und Gitterlinien auf fast schwarzem Grund.
//
// Statt die Erkennung zu reparieren liest dieses Modul die Farben direkt aus
// den CSS-Variablen. Damit entfaellt die Fallunterscheidung ganz: Hell,
// Dunkel, Hoher Kontrast und ueber die Theming-App gesetzte Vereinsfarben
// kommen alle denselben Weg. Noetig ist das, weil Chart.js auf <canvas> malt
// und dort keine CSS-Farben erbt - sie muessen als Werte hineingereicht
// werden.

import { color } from 'chart.js/helpers'

// Greift nur, wenn eine Variable fehlt (aeltere Server, Tests ohne DOM). Es
// sind die Werte, die vor der Umstellung fest im Code standen, damit ein
// solcher Ausfall auf das alte Bild zurueckfaellt statt auf gar keines.
const FALLBACKS = {
	'--color-main-text': '#222222',
	'--color-text-maxcontrast': '#6b6b6b',
	'--color-border': '#ededed',
	'--color-element-success': '#2d7d46',
	'--color-element-error': '#c73c3c',
	'--color-primary-element': '#4664c7',
}

/**
 * Legt eine Deckkraft auf eine Farbe und gibt sie als rgba() zurueck.
 *
 * Nextcloud liefert seine Farbvariablen als Hex, gebraucht werden aber auch
 * halbtransparente Flaechen (Balkenfuellung, Flaeche unter der Linie).
 * color-mix() scheidet aus: Chart.js reicht Farbstrings an @kurkle/color
 * weiter, das die Funktion nicht kennt und dann still auf Schwarz faellt.
 * Genau dieses @kurkle/color ist es auch, das hier rechnet - chart.js gibt es
 * als helpers.color weiter, ein eigener Parser waere dieselbe Arbeit doppelt.
 * Was es nicht lesen kann, kommt unveraendert zurueck: lieber deckend als
 * unsichtbar.
 */
export function withAlpha(farbe, deckkraft) {
	const wert = String(farbe || '').trim()
	const c = color(wert)
	return c && c.valid ? c.alpha(deckkraft).rgbString() : wert
}

/**
 * Baut die Farbtafel aus einer Lesefunktion fuer CSS-Variablen. Getrennt von
 * chartTheme(), damit sie ohne DOM pruefbar bleibt.
 */
export function buildChartTheme(read) {
	const v = (name) => ((read(name) || '').trim() || FALLBACKS[name])
	return {
		// Legende: normale Textfarbe. Achsen: die gedaempfte Variante, die
		// Nextcloud fuer Nebentext vorhaelt und die den Kontrastvorgaben in
		// beiden Designs genuegt.
		text: v('--color-main-text'),
		mutedText: v('--color-text-maxcontrast'),
		grid: v('--color-border'),
		// Fuer Linien, Symbole und Balken auf normalem Grund gelten die
		// --color-element-*-Toene, nicht --color-success/-error (das sind
		// Flaechentoene fuer Hinterlegungen) - siehe Kopfkommentar styles.css.
		success: v('--color-element-success'),
		error: v('--color-element-error'),
		accent: v('--color-primary-element'),
	}
}

export function chartTheme() {
	// Die Variablen haengen am <body> (data-themes), von dort erbt der Rest.
	const cs = getComputedStyle(document.body)
	return buildChartTheme((name) => cs.getPropertyValue(name))
}

/**
 * Ruft callback auf, wenn sich das Design aendert - einmal fuer den Wechsel im
 * Betriebssystem (Nextclouds Standarddesign folgt ihm) und einmal fuer die
 * ausdrueckliche Wahl in den Nextcloud-Einstellungen, die data-themes am
 * <body> umschreibt. Gibt die Abmeldefunktion zurueck; ohne sie liefe der
 * Beobachter nach dem Zerstoeren der Komponente weiter.
 */
export function onThemeChange(callback) {
	const media = window.matchMedia('(prefers-color-scheme: dark)')
	const observer = new MutationObserver(callback)
	observer.observe(document.body, { attributes: true, attributeFilter: ['data-themes'] })
	media.addEventListener('change', callback)
	return () => {
		observer.disconnect()
		media.removeEventListener('change', callback)
	}
}
