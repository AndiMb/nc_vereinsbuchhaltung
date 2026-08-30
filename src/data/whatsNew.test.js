import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { describe, expect, it } from 'vitest'
import { isNewerVersion } from '../lib/version.js'
import { buildWhatsNewEntries, filterWhatsNewEntries } from './whatsNew.js'

const INFO_XML = fileURLToPath(new URL('../../appinfo/info.xml', import.meta.url))
const APP_VERSION = readFileSync(INFO_XML, 'utf8').match(/<version>([^<]+)<\/version>/)[1]

describe('filterWhatsNewEntries', () => {
	const entries = buildWhatsNewEntries()

	it('lässt Einträge ohne roles für jede Rolle durch', () => {
		const generalEntry = entries.find((e) => !e.roles)
		expect(generalEntry).toBeTruthy()
		expect(filterWhatsNewEntries(entries, 'revisor', '')).toContain(generalEntry)
	})

	it('filtert rollenspezifische Einträge korrekt', () => {
		const verwalterOnly = entries.find((e) => e.roles && e.roles.length === 1 && e.roles[0] === 'verwalter')
		expect(verwalterOnly).toBeTruthy()
		expect(filterWhatsNewEntries(entries, 'verwalter', '')).toContain(verwalterOnly)
		expect(filterWhatsNewEntries(entries, 'revisor', '')).not.toContain(verwalterOnly)
	})

	it('zeigt bei gesetzter sinceVersion nur neuere Einträge', () => {
		const filtered = filterWhatsNewEntries(entries, 'verwalter', '0.25.0')
		expect(filtered.every((e) => e.version !== '0.25.0')).toBe(true)
		expect(filterWhatsNewEntries(entries, 'verwalter', '')).not.toHaveLength(0)
	})

	it('leerer sinceVersion-String zeigt alles (ungefiltert)', () => {
		const erwartet = entries.filter((e) => !e.roles || e.roles.includes('verwalter')).length
		expect(filterWhatsNewEntries(entries, 'verwalter', '').length).toBe(erwartet)
	})
})

describe('Obergrenze laufende App-Version', () => {
	const entries = buildWhatsNewEntries()

	it('blendet Einträge oberhalb der laufenden Version aus', () => {
		const filtered = filterWhatsNewEntries(entries, 'verwalter', '', '0.25.0')
		expect(filtered.some((e) => isNewerVersion(e.version, '0.25.0'))).toBe(false)
		expect(filtered.some((e) => e.version === '0.25.0')).toBe(true)
	})

	it('greift auch ungefiltert (Hilfe-Link, sinceVersion leer)', () => {
		const filtered = filterWhatsNewEntries(entries, 'verwalter', '', '0.20.0')
		expect(filtered.some((e) => isNewerVersion(e.version, '0.20.0'))).toBe(false)
		expect(filtered.some((e) => e.version === '0.20.0')).toBe(true)
	})

	it('leere currentVersion setzt keine Obergrenze', () => {
		expect(filterWhatsNewEntries(entries, 'verwalter', '', ''))
			.toEqual(filterWhatsNewEntries(entries, 'verwalter', ''))
	})

	// Der Splash-Screen muss sich wegklicken lassen: "Verstanden" schreibt die
	// laufende Version in whatsnew_last_seen_version. Bliebe danach noch ein
	// Eintrag übrig, käme das Popup bei jedem Laden wieder - genau das passiert,
	// wenn ein Eintrag für eine noch nicht ausgelieferte Version vorbereitet
	// wird (appinfo/info.xml noch niedriger) und die Obergrenze fehlt.
	it('nach dem Wegklicken bleibt für keine Rolle etwas übrig', () => {
		for (const rolle of ['verwalter', 'buchhalter', 'revisor']) {
			const uebrig = filterWhatsNewEntries(entries, rolle, APP_VERSION, APP_VERSION)
			expect(uebrig, `Rolle ${rolle} sieht den Splash-Screen erneut`).toHaveLength(0)
		}
	})
})
