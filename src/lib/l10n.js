import { getLanguage, register, translate, translatePlural } from '@nextcloud/l10n'
// Uebersetzungs-Helfer fuer die Vue-Oberflaeche.
//
// Die Quelltexte im Code sind bewusst Deutsch (Herkunftssprache der App), nicht
// Englisch wie sonst im Nextcloud-Oekosystem ueblich. @nextcloud/l10n geht in
// loadTranslations() aber davon aus, dass Englisch die Quellsprache ist, und
// laedt fuer getLanguage() === 'en' gar kein Uebersetzungsbundle nach - fuer
// uns waere das genau die falsche Sprache. Deshalb wird hier selbst geladen
// (register() statt loadTranslations()) und nur die Quellsprache uebersprungen.
import { generateUrl } from '@nextcloud/router'

const APP_ID = 'vereinsbuchhaltung'
const SOURCE_LANGUAGE = 'de'

export async function loadAppTranslations() {
	if (getLanguage() === SOURCE_LANGUAGE) { return }
	try {
		// Ueber einen eigenen Endpunkt statt direkt ueber generateFilePath():
		// Nextclouds .htaccess liefert aus dem App-Verzeichnis nur Dateien mit
		// bestimmten Endungen aus, .json gehoert nicht dazu. Der direkte Abruf
		// lieferte deshalb immer eine 404-HTML-Seite, und die Oberflaeche blieb
		// in jeder Sprache deutsch (siehe L10nController).
		const response = await fetch(generateUrl(`/apps/${APP_ID}/api/l10n/${getLanguage()}`))
		if (!response.ok) { return }
		const bundle = await response.json()
		if (bundle && typeof bundle.translations === 'object') {
			register(APP_ID, bundle.translations)
		}
	} catch {
		// Kein Netzwerk oder keine Uebersetzungsdatei fuer die Sprache -> es bleibt
		// bei den deutschen Quelltexten, kein Fehler wert.
	}
}

export function t(text, vars, count) {
	return translate(APP_ID, text, vars, count)
}

export function n(textSingular, textPlural, count, vars) {
	return translatePlural(APP_ID, textSingular, textPlural, count, vars)
}
