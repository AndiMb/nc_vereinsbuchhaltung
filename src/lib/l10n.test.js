import { describe, expect, it } from 'vitest'
import { n, t } from './l10n.js'

// @nextcloud/l10n kodiert Platzhalterwerte standardmaessig als HTML. Die
// Texte landen aber in Vue-Templates, die selbst escapen - ein "&" im
// Kontonamen stand deshalb als "&amp;" sichtbar im Finanzplan. Der Wrapper
// schaltet das Escaping ab; hier wird das festgenagelt. (DOMPurify ist in
// der Node-Testumgebung ohne DOM ohnehin inaktiv, geprueft wird das reine
// Escaping.)
describe('t()', () => {
	it('laesst ein & im Platzhalterwert unveraendert', () => {
		expect(t('Notiz zu {number} {name}', { number: '5930', name: 'Anschaffung & Wartung Technik' }))
			.toBe('Notiz zu 5930 Anschaffung & Wartung Technik')
	})

	it('kodiert auch Anfuehrungszeichen und spitze Klammern nicht', () => {
		expect(t('Konto "{number} {name}" löschen?', { number: '4000', name: 'Beiträge <Mitglieder>' }))
			.toBe('Konto "4000 Beiträge <Mitglieder>" löschen?')
	})

	it('gibt Texte ohne Platzhalter unveraendert zurueck', () => {
		expect(t('Finanzplan & Soll-Ist-Vergleich')).toBe('Finanzplan & Soll-Ist-Vergleich')
	})
})

describe('n()', () => {
	it('laesst Platzhalterwerte in Pluralformen unveraendert', () => {
		expect(n('{count} Konto in „{group}"', '{count} Konten in „{group}"', 2, { count: 2, group: 'Technik & Wartung' }))
			.toBe('2 Konten in „Technik & Wartung"')
	})
})
