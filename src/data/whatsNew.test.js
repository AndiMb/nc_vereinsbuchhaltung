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
