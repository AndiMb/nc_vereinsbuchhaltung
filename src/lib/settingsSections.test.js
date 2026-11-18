import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { settingsSections, tocGroups } from './settingsSections.js'

// Die Anker der Einstellungsseite sind Schnittstelle (Checkliste, Prüfliste,
// Dashboard, Terminplan, E2E-Tests). Die Tests halten fest, dass keiner beim
// Umsortieren verloren geht und dass Seite und Inhaltsverzeichnis dieselbe
// Reihenfolge haben.

const settingsApp = readFileSync(new URL('../SettingsApp.vue', import.meta.url), 'utf8')

describe('settingsSections', () => {
	it('enthält alle Anker, die es vor der Neuordnung gab', () => {
		const ids = settingsSections().map((s) => s.id)
		for (const id of ['verein', 'darstellung', 'ampel', 'belege', 'bankdaten', 'beitraege-sepa', 'rechnungen', 'auslagen', 'kostenstellen', 'mandats-rechtstext', 'willkommens-mail', 'berechtigungen', 'geschaeftsjahr', 'sicherung', 'daten']) {
			expect(ids).toContain(id)
		}
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('steht auf der Seite in derselben Reihenfolge wie im Inhaltsverzeichnis', () => {
		const onPage = [...settingsApp.matchAll(/id="settings-section_([a-z-]+)"/g)].map((m) => m[1])
		expect(onPage).toEqual(settingsSections().map((s) => s.id))
	})

	it('verweist je Abschnitt auf ein Handbuch-Kapitel', () => {
		for (const s of settingsSections()) {
			expect(s.help, s.id).toMatch(/^section-\d+(-\d+)?$/)
		}
	})

	it('hält „Darstellung“ und „Ampel“ beieinander (18-settings.spec prüft das)', () => {
		const ids = settingsSections().map((s) => s.id)
		expect(ids.indexOf('ampel')).toBe(ids.indexOf('darstellung') + 1)
	})
})

describe('tocGroups', () => {
	it('gruppiert in Seitenreihenfolge und lässt leere Gruppen weg', () => {
		const groups = tocGroups(settingsSections())
		expect(groups.map((g) => g.entries[0].id)).toEqual(['verein', 'darstellung', 'beitraege-sepa', 'daten'])
		expect(groups.flatMap((g) => g.entries.map((e) => e.id))).toEqual(settingsSections().map((s) => s.id))

		const onlyData = tocGroups(settingsSections(), (id) => ['daten', 'sicherung'].includes(id))
		expect(onlyData).toHaveLength(1)
		expect(onlyData[0].entries.map((e) => e.id)).toEqual(['daten', 'sicherung'])
	})
})
