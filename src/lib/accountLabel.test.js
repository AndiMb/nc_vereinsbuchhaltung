import { describe, expect, it } from 'vitest'
import { accountDisplayName, accountFilterBy, accountLabel, accountOption } from './accountLabel.js'

const lang = { id: 7, number: '4300', name: 'Einnahmen aus Veranstaltungen der Jugendabteilung', shortName: 'Jugend-Events' }
const plain = { id: 8, number: '4310', name: 'Spenden', shortName: null }

describe('Kontobeschriftung mit Kurzname (#201)', () => {
	it('nimmt den Kurznamen, wenn es einen gibt', () => {
		expect(accountDisplayName(lang)).toBe('Jugend-Events')
		expect(accountLabel(lang)).toBe('4300 Jugend-Events')
	})

	it('nimmt sonst den Namen; Leerzeichen zählen nicht als Kurzname', () => {
		expect(accountLabel(plain)).toBe('4310 Spenden')
		expect(accountDisplayName({ ...plain, shortName: '  ' })).toBe('Spenden')
	})

	it('baut die Option für die Auswahllisten', () => {
		expect(accountOption(lang)).toEqual({ id: 7, label: '4300 Jugend-Events', number: '4300', fullName: 'Einnahmen aus Veranstaltungen der Jugendabteilung' })
	})
})

describe('Suche in den Kontolisten', () => {
	const option = accountOption(lang)

	it('findet den Kurznamen und weiterhin den vollen Namen', () => {
		expect(accountFilterBy(option, option.label, 'events')).toBe(true)
		expect(accountFilterBy(option, option.label, 'jugendabteilung')).toBe(true)
		expect(accountFilterBy(option, option.label, 'mitglied')).toBe(false)
	})

	it('Ziffern suchen am Anfang der Kontonummer', () => {
		expect(accountFilterBy(option, option.label, '43')).toBe(true)
		expect(accountFilterBy(option, option.label, '30')).toBe(false)
	})

	it('Überschriften sind nie ein Treffer, leere Suche passt immer', () => {
		expect(accountFilterBy({ label: 'Einnahmen', $isDisabled: true }, 'Einnahmen', 'einn')).toBe(false)
		expect(accountFilterBy(option, option.label, '  ')).toBe(true)
	})

	it('Optionen ohne vollen Namen (Kategorien) suchen in der Beschriftung', () => {
		expect(accountFilterBy({ id: 'category:Einnahmen', label: 'Einnahmen' }, 'Einnahmen', 'einn')).toBe(true)
	})
})
