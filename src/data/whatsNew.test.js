import { describe, expect, it } from 'vitest'
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
