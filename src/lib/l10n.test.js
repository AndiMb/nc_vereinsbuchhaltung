import { register, setLanguage, unregister } from '@nextcloud/l10n'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CONTEXT_SEPARATOR, loadAppTranslations, t, tc, tRaw } from './l10n.js'

// generateUrl() braucht eine Nextcloud-Seite (window.OC); hier genügt der Pfad.
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

// Nutzerdaten und @nextcloud/l10n (Issue #106): die Bibliothek escaped Variablen
// als HTML, unser Text landet aber als Text in der Seite. Die Tests laufen gegen
// die echte Bibliothek, nicht gegen einen Nachbau – genau ihr Verhalten ist der
// Anlass.

const NAME = 'Echo & Söhne <b>fett</b>'

describe('t() mit Nutzerdaten (der Fehlerfall, den tRaw() vermeidet)', () => {
	it('escaped „&" und „<" in einer Variable als HTML', () => {
		// Dokumentiert das Verhalten der Bibliothek: genau so soll Nutzerdaten nie ankommen.
		expect(t('Hallo {name}', { name: NAME })).toBe('Hallo Echo &amp; Söhne &lt;b&gt;fett&lt;/b&gt;')
	})
})

describe('tRaw()', () => {
	afterEach(() => unregister('vereinsbuchhaltung'))

	it('setzt Nutzerdaten unverändert ein, auch mit „&" und „<"', () => {
		expect(tRaw('Hallo {name}', { name: NAME })).toBe(`Hallo ${NAME}`)
	})

	it('übersetzt den Satz und setzt die Nutzerdaten danach ein', () => {
		register('vereinsbuchhaltung', { 'Mandat von {inhaber} über {betrag}': 'Mandate of {inhaber} for {betrag}' })

		expect(tRaw('Mandat von {inhaber} über {betrag}', { inhaber: NAME }, { betrag: '10,00 €' }))
			.toBe(`Mandate of ${NAME} for 10,00 €`)
	})

	it('setzt einen Platzhalter an mehreren Stellen ein', () => {
		expect(tRaw('{name} und noch einmal {name}', { name: 'A & B' })).toBe('A & B und noch einmal A & B')
	})

	it('löst Klammern im Wert nicht ein zweites Mal auf', () => {
		expect(tRaw('Hallo {name}, {datum}', { name: '{datum}' }, { datum: '1.1.2026' })).toBe('Hallo {datum}, 1.1.2026')
	})

	it('lässt unbekannte Platzhalter stehen und macht aus fehlenden Werten nichts Kaputtes', () => {
		expect(tRaw('Hallo {name} {unbekannt}', { name: null })).toBe('Hallo  {unbekannt}')
	})

	it('schickt keinen Nutzerwert durch die Bibliothek (ein Wert, der wie ein Platzhalter aussieht, bleibt Text)', () => {
		expect(tRaw('{a}', { a: '%n' }, {})).toBe('%n')
	})
})

describe('loadAppTranslations()', () => {
	beforeEach(() => {
		vi.stubGlobal('fetch', vi.fn(async () => ({
			ok: true,
			json: async () => ({ translations: { 'Guten Tag': 'Hallo' } }),
		})))
	})

	afterEach(() => {
		unregister('vereinsbuchhaltung')
		vi.unstubAllGlobals()
	})

	it('lädt für förmliches Deutsch („de_DE", der Quelltext) nichts', async () => {
		setLanguage('de_DE')
		await loadAppTranslations()
		expect(fetch).not.toHaveBeenCalled()
		expect(t('Guten Tag')).toBe('Guten Tag')
	})

	it('lädt für informelles Deutsch („de") die Du-Fassung', async () => {
		setLanguage('de')
		await loadAppTranslations()
		expect(fetch).toHaveBeenCalledOnce()
		expect(String(fetch.mock.calls[0][0])).toMatch(/\/api\/l10n\/de$/)
		expect(t('Guten Tag')).toBe('Hallo')
	})

	it('lädt für Englisch das Übersetzungsbündel', async () => {
		setLanguage('en')
		await loadAppTranslations()
		expect(String(fetch.mock.calls[0][0])).toMatch(/\/api\/l10n\/en$/)
	})
})

describe('tc()', () => {
	afterEach(() => unregister('vereinsbuchhaltung'))

	it('nimmt den Eintrag mit Kontext, wenn es ihn gibt', () => {
		register('vereinsbuchhaltung', {
			Aktiv: 'Asset',
			[`Zustand${CONTEXT_SEPARATOR}Aktiv`]: 'Active',
		})
		expect(tc('Zustand', 'Aktiv')).toBe('Active')
		expect(t('Aktiv')).toBe('Asset')
	})

	it('zeigt ohne Übersetzung das schlichte Wort, nicht den Schlüssel mit Kontext', () => {
		expect(tc('Zustand', 'Aktiv')).toBe('Aktiv')
	})

	it('fällt auf den Eintrag ohne Kontext zurück, wenn nur der existiert', () => {
		register('vereinsbuchhaltung', { Aktiv: 'Asset' })
		expect(tc('Zustand', 'Aktiv')).toBe('Asset')
	})

	it('setzt Platzhalter ein', () => {
		register('vereinsbuchhaltung', { [`Zustand${CONTEXT_SEPARATOR}seit {datum}`]: 'since {datum}' })
		expect(tc('Zustand', 'seit {datum}', { datum: '1.1.2026' })).toBe('since 1.1.2026')
		expect(tc('Zustand', 'bis {datum}', { datum: '1.1.2026' })).toBe('bis 1.1.2026')
	})
})
