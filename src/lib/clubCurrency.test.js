import { afterEach, describe, expect, it } from 'vitest'
import { computed } from 'vue'
import { CURRENCY_TOKEN_RE, currencyCode, currencySettingsPayload, currencySymbol, isEuro, resetClubCurrencyForTests, setClubCurrency } from './clubCurrency.js'
import { formatMoney } from './format.js'

// Vereinswährung (Issue #202): Code und Zeichen kommen vom Server (me/settings).
describe('clubCurrency', () => {
	afterEach(() => resetClubCurrencyForTests())

	it('ist ohne Angabe Euro', () => {
		expect(currencyCode()).toBe('EUR')
		expect(currencySymbol()).toBe('€')
		expect(isEuro()).toBe(true)
	})

	it('übernimmt einen bekannten Code, auch klein geschrieben', () => {
		setClubCurrency('chf')
		expect(currencyCode()).toBe('CHF')
		expect(currencySymbol()).toBe('CHF')
		expect(isEuro()).toBe(false)
		setClubCurrency('GBP')
		expect(currencySymbol()).toBe('£')
	})

	it('fällt bei unbekanntem oder leerem Code auf Euro zurück', () => {
		setClubCurrency('XYZ')
		expect(currencyCode()).toBe('EUR')
		setClubCurrency('CHF')
		setClubCurrency(null)
		expect(currencyCode()).toBe('EUR')
	})

	it('erkennt Währungszeichen und Codes in Eingaben', () => {
		for (const token of ['CHF', '£', 'eur', '€', 'czk']) {
			expect('12,50 ' + token).toMatch(CURRENCY_TOKEN_RE)
		}
		expect('12,50').not.toMatch(CURRENCY_TOKEN_RE)
	})

	it('aktualisiert schon berechnete Beträge, wenn die Währung später ankommt', () => {
		const shown = computed(() => formatMoney(1))
		expect(shown.value).toMatch(/€$/)
		setClubCurrency('CHF')
		expect(shown.value).toMatch(/CHF$/)
	})
})

describe('currencySettingsPayload', () => {
	// Eine abgelehnte Umstellung (Mandate offen) darf das Speichern der übrigen Karten nicht blockieren.
	it('sendet die Währung nur, wenn sie sich geändert hat', () => {
		expect(currencySettingsPayload('EUR', 'EUR')).toEqual({})
		expect(currencySettingsPayload('CHF', 'EUR')).toEqual({ club_currency: 'CHF' })
	})
})
