// `npm run changelog:de` (läuft auch vor `npm run build`): schreibt die
// deutsche Fassung des Änderungsverlaufs für Nextclouds eigenen Dialog
// „Was ist neu“ (App updatenotification).
//
// Nach einem App-Update meldet Nextcloud „Vereinsbuchhaltung wurde auf Version …
// aktualisiert“; der Knopf daneben zeigt den Abschnitt der Version aus
// `CHANGELOG.<sprache>.md`, sonst aus `CHANGELOG.en.md` – nie aus `CHANGELOG.md`
// (OCA\UpdateNotification\Manager::getChangelogFile()). Ohne diese Dateien
// bekamen deutschsprachige Konten den englischen Text. Die Sprache ist die des
// Kontos: `de` (Du) oder `de_DE` (Sie); beide bekommen denselben Text.
//
// Quelle bleibt CHANGELOG.md. Die beiden Dateien sind abgeleitet und nicht
// versioniert (.gitignore): `npm run build` erzeugt sie vor jedem Release und
// vor den E2E-Tests der CI, und rsync in release.yml nimmt sie mit ins Paket.
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/** Sprachcodes, unter denen Nextcloud deutschsprachige Konten führt. */
export const LANGUAGES = ['de', 'de_DE']

export const NOTICE = '<!-- Erzeugt aus CHANGELOG.md (scripts/changelog-de.mjs, npm run build) – nicht bearbeiten. -->\n'

/**
 * Inhalt von CHANGELOG.<sprache>.md zu einem gelesenen CHANGELOG.md.
 *
 * @param {string} changelog Inhalt von CHANGELOG.md
 * @return {string}
 */
export function renderGermanChangelog(changelog) {
	return NOTICE + changelog.replace(/\r\n/g, '\n')
}

/** Schreibt die Dateien neu; liefert ihre Namen. */
export function writeGermanChangelogs(root = ROOT) {
	const content = renderGermanChangelog(readFileSync(join(root, 'CHANGELOG.md'), 'utf8'))
	return LANGUAGES.map((lang) => {
		const name = `CHANGELOG.${lang}.md`
		writeFileSync(join(root, name), content)
		return name
	})
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	console.log(`geschrieben: ${writeGermanChangelogs().join(', ')}`)
}
