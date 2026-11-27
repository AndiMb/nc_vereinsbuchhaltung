// `npm run changelog:de` (läuft auch vor `npm run build`): Nextclouds Dialog
// „Was ist neu“ (App updatenotification) liest CHANGELOG.<sprache>.md und sonst
// CHANGELOG.en.md, nie CHANGELOG.md. Für deutschsprachige Konten – `de` (Du)
// und `de_DE` (Sie) – entstehen hier die beiden Kopien von CHANGELOG.md. Sie
// sind nicht versioniert; release.yml baut vor dem rsync, so landen sie im Paket.
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
