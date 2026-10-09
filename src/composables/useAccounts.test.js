import { describe, expect, it, vi } from 'vitest'

// buildAccountOptions gruppiert die Konto-Autocompletes. Fuer den Journal-
// Filter werden die Kategorie-Ueberschriften zu waehlbaren Optionen; in allen
// anderen Pickern bleiben sie deaktivierte Trenner.

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../api.js', () => ({ default: {} }))

const { buildAccountOptions } = await import('./useAccounts.js')

const t = (s) => s

const accounts = [
	{ id: 1, number: '1000', name: 'Kasse', category: 'Geldkonten', active: true },
	{ id: 2, number: '1200', name: 'Bankkonto', category: 'Geldkonten', active: true },
	{ id: 3, number: '4000', name: 'Mitgliedsbeiträge', category: 'Einnahmen', active: true },
	{ id: 4, number: '4100', name: 'Spenden', category: 'Einnahmen', active: true },
	{ id: 5, number: '5000', name: 'Raumkosten', category: 'Ausgaben', active: true },
	{ id: 6, number: '5100', name: 'Versicherungen', category: 'Ausgaben', active: false },
	{ id: 7, number: '9000', name: 'Ohne Kategorie', category: null, active: true },
]

describe('buildAccountOptions', () => {
	it('gruppiert aktive Konten nach Kategorie mit deaktivierten Ueberschriften', () => {
		const opts = buildAccountOptions(accounts, {}, t)
		expect(opts.map((o) => o.label)).toEqual([
			'Geldkonten',
			'1000 Kasse',
			'1200 Bankkonto',
			'Einnahmen',
			'4000 Mitgliedsbeiträge',
			'4100 Spenden',
			'Ausgaben',
			'5000 Raumkosten',
			'Sonstige',
			'9000 Ohne Kategorie',
		])
		const headers = opts.filter((o) => o.id === null)
		expect(headers).toHaveLength(4)
		expect(headers.every((o) => o.$isDisabled)).toBe(true)
		expect(opts.some((o) => o.isCategory)).toBe(false)
	})

	it('zieht haeufig verwendete Konten in eine eigene Gruppe und laesst leere Kategorien weg', () => {
		const opts = buildAccountOptions(accounts, { 1: 3, 2: 9 }, t)
		expect(opts.map((o) => o.label)).toEqual([
			'★ Häufig verwendet',
			'1200 Bankkonto',
			'1000 Kasse',
			'Einnahmen',
			'4000 Mitgliedsbeiträge',
			'4100 Spenden',
			'Ausgaben',
			'5000 Raumkosten',
			'Sonstige',
			'9000 Ohne Kategorie',
		])
	})

	it('macht Kategorien auf Wunsch zu waehlbaren Optionen mit eindeutiger ID', () => {
		const opts = buildAccountOptions(accounts, {}, t, { selectableCategories: true })
		const cats = opts.filter((o) => o.isCategory)
		expect(cats.map((o) => o.category)).toEqual(['Geldkonten', 'Einnahmen', 'Ausgaben', 'Sonstige'])
		expect(cats.every((o) => !o.$isDisabled)).toBe(true)
		expect(new Set(cats.map((o) => o.id)).size).toBe(4)
		expect(cats[1]).toMatchObject({ id: 'category:Einnahmen', label: 'Einnahmen' })
		// Konten behalten ihre numerische ID, damit der Setter beides unterscheiden kann.
		expect(opts.find((o) => o.label === '4000 Mitgliedsbeiträge').id).toBe(3)
	})

	it('behaelt im Filter-Modus auch Kategorien, deren Konten alle "haeufig verwendet" sind', () => {
		const opts = buildAccountOptions(accounts, { 1: 3, 2: 9 }, t, { selectableCategories: true })
		expect(opts.map((o) => o.label)).toEqual([
			'★ Häufig verwendet',
			'1200 Bankkonto',
			'1000 Kasse',
			'Geldkonten',
			'Einnahmen',
			'4000 Mitgliedsbeiträge',
			'4100 Spenden',
			'Ausgaben',
			'5000 Raumkosten',
			'Sonstige',
			'9000 Ohne Kategorie',
		])
		expect(opts.find((o) => o.label === '★ Häufig verwendet').$isDisabled).toBe(true)
		expect(opts.find((o) => o.label === 'Geldkonten').isCategory).toBe(true)
	})
})
