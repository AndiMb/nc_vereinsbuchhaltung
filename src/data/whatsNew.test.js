import { describe, expect, it } from 'vitest'
import { compareVersions } from '../lib/version.js'
import { buildWhatsNewEntries, filterWhatsNewEntries } from './whatsNew.js'

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

// Bewusst mit erfundenen Einträgen statt mit der echten Liste: die Obergrenze
// greift nur, solange ein Eintrag ueber der laufenden Version liegt, und in
// der echten Liste ist das genau bis zum naechsten Release-Commit so. Ein
// Test gegen appinfo/info.xml wuerde ab dann still nichts mehr pruefen.
describe('Obergrenze laufende App-Version', () => {
	const LAEUFT = '0.28.0'
	const entries = [
		{ version: '0.29.0', items: ['schon vorbereitet, noch nicht ausgeliefert'] },
		{ version: '0.28.0', items: ['ausgeliefert'] },
		{ version: '0.27.0', items: ['aelter'] },
	]

	it('blendet Einträge oberhalb der laufenden Version aus', () => {
		const sichtbar = filterWhatsNewEntries(entries, 'verwalter', '', LAEUFT)
		expect(sichtbar.map((e) => e.version)).toEqual(['0.28.0', '0.27.0'])
	})

	it('leere currentVersion setzt keine Obergrenze', () => {
		expect(filterWhatsNewEntries(entries, 'verwalter', '', '')).toHaveLength(3)
	})

	// Der Splash-Screen muss sich wegklicken lassen: „Verstanden" schreibt die
	// laufende Version in whatsnew_last_seen_version. Bliebe danach ein Eintrag
	// uebrig, kaeme das Popup bei jedem Laden wieder - genau das passierte, als
	// ein Eintrag fuer eine noch nicht ausgelieferte Version vorbereitet wurde.
	it('nach dem Wegklicken bleibt nichts übrig', () => {
		expect(filterWhatsNewEntries(entries, 'verwalter', LAEUFT, LAEUFT)).toHaveLength(0)
	})
})

// Gegen die ECHTE Liste, aber ohne appinfo/info.xml zu lesen (siehe Kommentar
// oben: ein Test gegen die eingecheckte Version prüfte ab dem Release-Commit
// still nichts mehr). Statt dessen wird jede Version der Liste und eine Version
// knapp darunter einmal als „laufende Version" durchgespielt: so liegt jeder
// Eintrag der Liste einmal oberhalb der laufenden Version – genau der Fall, der
// die E2E-Suite blockiert, wenn er durchrutscht –, und das bleibt so, egal wie
// die Liste wächst.
describe('echte Liste', () => {
	const entries = buildWhatsNewEntries()
	const versionen = [...new Set(entries.map((e) => e.version))]
	const rollen = ['verwalter', 'buchhalter', 'revisor']

	it('jeder Eintrag hat eine Version x.y.z, Texte und gültige Rollen', () => {
		for (const entry of entries) {
			expect(entry.version, 'Version').toMatch(/^\d+\.\d+\.\d+$/)
			expect(entry.items.length, `Texte von ${entry.version}`).toBeGreaterThan(0)
			for (const item of entry.items) {
				expect(typeof item).toBe('string')
				expect(item.trim()).not.toBe('')
			}
			for (const rolle of entry.roles ?? []) {
				expect(rollen, `Rolle in ${entry.version}`).toContain(rolle)
			}
		}
	})

	it('steht neueste Version zuerst', () => {
		for (let i = 1; i < entries.length; i++) {
			expect(compareVersions(entries[i - 1].version, entries[i].version), `${entries[i - 1].version} vor ${entries[i].version}`).toBeGreaterThanOrEqual(0)
		}
	})

	// Eine Version knapp unterhalb: so sieht die laufende App aus, solange der
	// Release-Commit info.xml noch nicht gehoben hat, der Eintrag aber schon
	// in der Liste steht (z. B. 0.34.4 laeuft, 0.35.0 ist vorbereitet).
	function knappDarunter(version) {
		const [major, minor, patch] = version.split('.').map(Number)
		if (patch > 0) { return `${major}.${minor}.${patch - 1}` }
		if (minor > 0) { return `${major}.${minor - 1}.999` }
		return `${Math.max(major - 1, 0)}.999.999`
	}
	const laufende = [...new Set(versionen.flatMap((v) => [v, knappDarunter(v)]))]

	// „Verstanden" schreibt die laufende Version als zuletzt gesehen. Egal, welche
	// Version läuft – auch eine unterhalb des neuesten Eintrags: danach darf für
	// keine Rolle etwas übrig bleiben, sonst legt sich der Splash bei jedem Laden
	// wieder vor die Oberfläche und blockiert die E2E-Suite.
	it.each(laufende)('läuft %s: nach dem Wegklicken bleibt für keine Rolle etwas übrig', (laufend) => {
		for (const rolle of rollen) {
			expect(filterWhatsNewEntries(entries, rolle, laufend, laufend), `Rolle ${rolle}`).toHaveLength(0)
		}
	})

	// Auch der ungefilterte Hilfe-Link („Was ist neu in Version …", leere
	// sinceVersion) zeigt nie etwas oberhalb der laufenden Version.
	it.each(laufende)('läuft %s: der Hilfe-Link zeigt nichts darüber', (laufend) => {
		for (const rolle of rollen) {
			for (const entry of filterWhatsNewEntries(entries, rolle, '', laufend)) {
				expect(compareVersions(entry.version, laufend), `${entry.version} bei ${laufend}`).toBeLessThanOrEqual(0)
			}
		}
	})
})
