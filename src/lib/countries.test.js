import { describe, expect, it } from 'vitest'
import { COUNTRY_CODES, countryName, countryOptions, defaultCountryCode, FALLBACK_COUNTRY, regionFromLocale } from './countries.js'

describe('COUNTRY_CODES', () => {
	it('enthält alle 249 ISO-Codes ohne Dubletten', () => {
		expect(COUNTRY_CODES).toHaveLength(249)
		expect(new Set(COUNTRY_CODES).size).toBe(249)
		expect(COUNTRY_CODES.every((c) => /^[A-Z]{2}$/.test(c))).toBe(true)
	})

	it('jeder Code hat einen Namen in Deutsch und Englisch', () => {
		for (const language of ['de', 'en']) {
			for (const code of COUNTRY_CODES) {
				expect(countryName(code, language), `${code} (${language})`).not.toBe(code)
			}
		}
	})
})

describe('countryOptions', () => {
	it('nennt die Namen in der Sprache des Nutzers', () => {
		const de = countryOptions('de')
		expect(de.find((o) => o.id === 'DE')?.label).toBe('Deutschland')
		expect(de.find((o) => o.id === 'AT')?.label).toBe('Österreich')
		expect(countryOptions('en').find((o) => o.id === 'DE')?.label).toBe('Germany')
		expect(countryOptions('de_DE').find((o) => o.id === 'CH')?.label).toBe('Schweiz')
	})

	it('sortiert nach dem Namen, nicht nach dem Code', () => {
		const labels = countryOptions('de').map((o) => o.label)
		const sorted = [...labels].sort(new Intl.Collator('de').compare)
		expect(labels).toEqual(sorted)
		expect(labels.indexOf('Ägypten')).toBeLessThan(labels.indexOf('Belgien'))
	})

	it('liefert für jedes Land genau einen Eintrag', () => {
		expect(countryOptions('de')).toHaveLength(249)
	})
})

describe('countryName', () => {
	it('lässt leere und unbekannte Werte nicht abstürzen', () => {
		expect(countryName('', 'de')).toBe('')
		expect(countryName(null, 'de')).toBe('')
		expect(countryName('!!', 'de')).toBe('!!')
	})
})

describe('regionFromLocale', () => {
	it('liest die Region aus Nextcloud- und Browser-Locales', () => {
		expect(regionFromLocale('de_DE')).toBe('DE')
		expect(regionFromLocale('de_AT')).toBe('AT')
		expect(regionFromLocale('en-GB')).toBe('GB')
		expect(regionFromLocale('de-CH-1996')).toBe('CH')
	})

	it('liefert null, wenn keine Region dabei ist oder sie nicht existiert', () => {
		expect(regionFromLocale('de')).toBeNull()
		expect(regionFromLocale('')).toBeNull()
		expect(regionFromLocale(undefined)).toBeNull()
		expect(regionFromLocale('de_ZZ')).toBeNull()
	})
})

describe('defaultCountryCode', () => {
	it('nimmt die erste Locale mit Region', () => {
		expect(defaultCountryCode('de_AT', 'de-DE')).toBe('AT')
		expect(defaultCountryCode('de', 'de-CH')).toBe('CH')
	})

	it('fällt auf Deutschland zurück', () => {
		expect(defaultCountryCode('de', 'en')).toBe(FALLBACK_COUNTRY)
		expect(defaultCountryCode()).toBe('DE')
	})
})
