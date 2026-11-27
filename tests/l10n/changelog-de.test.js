import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { LANGUAGES, NOTICE, renderGermanChangelog, ROOT } from '../../scripts/changelog-de.mjs'

// Nextclouds Dialog „Was ist neu“ (App updatenotification) liest den Abschnitt
// der Version aus CHANGELOG.<sprache>.md, sonst aus CHANGELOG.en.md – nie aus
// CHANGELOG.md. scripts/changelog-de.mjs erzeugt die deutsche Fassung vor jedem
// Build. Den Weg durch Nextcloud selbst prüft tests/e2e/19-whatsnew.spec.mjs.

const source = readFileSync(join(ROOT, 'CHANGELOG.md'), 'utf8')

/** Wie OCA\UpdateNotification\Manager::retrieveChangelogEntry() die Versionen findet. */
function versionsAsNextcloudSeesThem(content) {
	return [...content.matchAll(/^## (?:\[)?(?:v)?(\d+\.\d+(\.\d+)?)/gm)].map((m) => m[1])
}

describe('CHANGELOG.de.md / CHANGELOG.de_DE.md', () => {
	it('gibt es für beide deutschen Sprachcodes von Nextcloud', () => {
		expect(LANGUAGES).toEqual(['de', 'de_DE'])
	})

	it('ist CHANGELOG.md mit einem Hinweis, dass die Datei erzeugt ist', () => {
		const rendered = renderGermanChangelog(source)
		expect(rendered.startsWith(NOTICE)).toBe(true)
		expect(rendered.slice(NOTICE.length)).toBe(source.replace(/\r\n/g, '\n'))
	})

	it('Nextcloud findet darin dieselben Versionen wie in CHANGELOG.md, auf Deutsch', () => {
		const versions = versionsAsNextcloudSeesThem(renderGermanChangelog(source))
		expect(versions.length).toBeGreaterThan(0)
		expect(versions).toEqual(versionsAsNextcloudSeesThem(source))
		// Der jüngste ausgelieferte Abschnitt beginnt mit einer deutschen Einleitung.
		const newest = renderGermanChangelog(source).split(`## [${versions[0]}]`)[1]
		expect(newest).toMatch(/\*\*(Neu|Geändert|Behoben|Verbessert|Sicherheit|Dokumentation):\*\*/)
	})

	it('ist nicht versioniert, landet aber im Release-Paket', () => {
		const gitignore = readFileSync(join(ROOT, '.gitignore'), 'utf8').split('\n')
		const release = readFileSync(join(ROOT, '.github', 'workflows', 'release.yml'), 'utf8')
		for (const lang of LANGUAGES) {
			expect(gitignore).toContain(`/CHANGELOG.${lang}.md`)
		}
		// release.yml baut (prebuild erzeugt die Dateien) und kopiert dann per rsync.
		expect(release).toMatch(/npm run build/)
		expect(release).not.toMatch(/--exclude='[^']*CHANGELOG/)
	})
})
