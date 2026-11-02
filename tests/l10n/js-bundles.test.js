import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { renderBundle } from '../../scripts/l10n-js.mjs'

// Nextcloud lädt die Übersetzungen einer App als Skript l10n/<sprache>.js
// (Issue #264). Die Quelle bleibt l10n/<sprache>.json; das Skript entsteht mit
// `npm run l10n:js` und liegt eingecheckt daneben. Wer eine Übersetzung in der
// .json ergänzt und das Skript vergisst, sähe sie in der Oberfläche nicht –
// dieser Test macht das sichtbar.

const L10N_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'l10n')
const languages = readdirSync(L10N_DIR).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5))

describe('l10n/*.js', () => {
	it('findet überhaupt Übersetzungen', () => {
		expect(languages).toEqual(expect.arrayContaining(['de', 'de_DE', 'en']))
	})

	it.each(languages)('jede l10n/*.json hat ein aktuelles .js-Gegenstück (%s)', (lang) => {
		const expected = renderBundle(JSON.parse(readFileSync(join(L10N_DIR, `${lang}.json`), 'utf8')))
		let actual
		try {
			actual = readFileSync(join(L10N_DIR, `${lang}.js`), 'utf8')
		} catch {
			actual = null
		}
		// Windows-Arbeitskopien tragen CRLF; eingecheckt ist LF.
		expect(actual?.replace(/\r\n/g, '\n'), `l10n/${lang}.js fehlt oder ist veraltet – npm run l10n:js`).toBe(expected)
	})

	it('registriert die Texte so, wie Nextcloud es erwartet', () => {
		const calls = []
		const OC = { L10N: { register: (...args) => calls.push(args) } }
		new Function('OC', renderBundle({ translations: { Konto: 'Account' }, pluralForm: 'nplurals=2; plural=(n != 1);' }))(OC)
		expect(calls).toEqual([['vereinsbuchhaltung', { Konto: 'Account' }, 'nplurals=2; plural=(n != 1);']])
	})
})
