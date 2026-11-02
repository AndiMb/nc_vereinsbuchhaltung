// `npm run l10n:js` (läuft auch vor `npm run build`): schreibt zu jeder
// l10n/<sprache>.json das Skript l10n/<sprache>.js, wie Nextcloud es für die
// Übersetzungen einer App erwartet (Issue #264).
//
// Nextcloud bindet beim Laden der Seite selbst `l10n/<sprache>.js` der App ein
// (OCP\Util::addTranslations, das auch Util::addScript auslöst) – die Sprache
// bestimmt der Server, auch „de_DE“ gegenüber „de“. Die .json-Dateien bleiben
// die Quelle (Pflege, Katalogtests, PHP-Seite über IL10N); die .js-Dateien sind
// davon abgeleitet und liegen eingecheckt daneben. `npm run build` erzeugt sie
// vorher ohnehin neu, tests/l10n/js-bundles.test.js meldet einen vergessenen Lauf.
//
// Bis 0.50 lud die Oberfläche das Bündel über einen eigenen Endpunkt
// (/api/l10n/<sprache>) nach: Nextclouds .htaccess liefert .json-Dateien aus dem
// App-Verzeichnis nicht aus, .js schon.
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const APP_ID = 'vereinsbuchhaltung'
const DEFAULT_PLURAL_FORM = 'nplurals=2; plural=(n != 1);'
export const L10N_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', 'l10n')

/**
 * Der Inhalt von l10n/<sprache>.js zu einem gelesenen l10n/<sprache>.json.
 *
 * Eingerückt statt in einer Zeile, damit eine geänderte Übersetzung im Diff
 * als einzelne Zeile erscheint.
 *
 * @param {{translations: object, pluralForm?: string}} bundle Inhalt der .json
 * @return {string} das Skript, mit Zeilenumbruch am Ende
 */
export function renderBundle(bundle) {
	const translations = JSON.stringify(bundle.translations ?? {}, null, '\t')
	const pluralForm = JSON.stringify(bundle.pluralForm ?? DEFAULT_PLURAL_FORM)
	return `OC.L10N.register(${JSON.stringify(APP_ID)}, ${translations}, ${pluralForm});\n`
}

/** Schreibt alle .js-Dateien neu; liefert die Sprachcodes. */
export function writeBundles(dir = L10N_DIR) {
	const languages = readdirSync(dir).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5))
	for (const lang of languages) {
		const bundle = JSON.parse(readFileSync(join(dir, `${lang}.json`), 'utf8'))
		writeFileSync(join(dir, `${lang}.js`), renderBundle(bundle))
	}
	return languages
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	const languages = writeBundles()
	console.log(`l10n/*.js geschrieben: ${languages.join(', ')}`)
}
