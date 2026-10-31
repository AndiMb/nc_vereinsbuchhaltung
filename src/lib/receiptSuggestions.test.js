import { mdiHelpCircleOutline, mdiPaperclipCheck } from '@mdi/js'
import { describe, expect, it } from 'vitest'
import { levelIcon, pendingHint, reasonLine, reasonText, suggestionsVisible } from './receiptSuggestions.js'

const t = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => String(vars[k]))
const money = (v) => `${v.toFixed(2).replace('.', ',')} €`
const date = (s) => s.split('-').reverse().join('.')

describe('reasonText', () => {
	it('nennt die Rechnungsnummer mit Fundort', () => {
		expect(reasonText({ kind: 'ref', value: '28/26', where: 'name' }, t, money, date)).toBe('Rechnungsnr. 28/26 im Dateinamen')
		expect(reasonText({ kind: 'ref', value: '28/26', where: 'text' }, t, money, date)).toBe('Rechnungsnr. 28/26 im Beleg')
		expect(reasonText({ kind: 'ref', value: '28/26', where: 'einvoice' }, t, money, date)).toBe('Rechnungsnr. 28/26 in der E-Rechnung')
	})

	it('formatiert Betrag (Cent) und Datum', () => {
		expect(reasonText({ kind: 'amount', cents: 11900 }, t, money, date)).toBe('Betrag 119,00 € im Beleg')
		expect(reasonText({ kind: 'date', date: '2026-10-15' }, t, money, date)).toBe('Datum 15.10.2026')
	})

	it('kennt nur bekannte Arten', () => {
		expect(reasonText({ kind: 'x' }, t, money, date)).toBe('')
	})
})

describe('reasonLine', () => {
	it('verbindet die Gründe mit Mittelpunkt', () => {
		const line = reasonLine([
			{ kind: 'ref', value: '28/26', where: 'name' },
			{ kind: 'amount', cents: 11900 },
			{ kind: 'date', date: '2026-10-15' },
		], t, money, date)
		expect(line).toBe('Rechnungsnr. 28/26 im Dateinamen · Betrag 119,00 € im Beleg · Datum 15.10.2026')
	})

	it('zeigt denselben Ausschnitt nicht doppelt (überlappende Kerne)', () => {
		const line = reasonLine([
			{ kind: 'ref', value: 'R-2026-4711', where: 'name' },
			{ kind: 'ref', value: 'R-2026-4711', where: 'name' },
		], t, money, date)
		expect(line).toBe('Rechnungsnr. R-2026-4711 im Dateinamen')
	})

	it('zeigt verschiedene Ausschnitte beide', () => {
		const line = reasonLine([
			{ kind: 'ref', value: 'R-2026-4711', where: 'name' },
			{ kind: 'ref', value: '4711', where: 'name' },
		], t, money, date)
		expect(line).toBe('Rechnungsnr. R-2026-4711 im Dateinamen · Rechnungsnr. 4711 im Dateinamen')
	})

	it('ist ohne Gründe leer', () => {
		expect(reasonLine([], t, money, date)).toBe('')
		expect(reasonLine(undefined, t, money, date)).toBe('')
	})
})

describe('levelIcon', () => {
	it('sicher: Büroklammer mit Haken', () => {
		expect(levelIcon('sure', t)).toEqual({ path: mdiPaperclipCheck, className: 'vbh-suggest--sure', label: 'sicher' })
	})

	it('möglich: Fragezeichen', () => {
		expect(levelIcon('possible', t)).toEqual({ path: mdiHelpCircleOutline, className: 'vbh-suggest--possible', label: 'möglich' })
	})
})

describe('pendingHint', () => {
	const n = (singular, plural, count) => (count === 1 ? singular : plural).replace('%n', String(count))

	it('pendingHintNurBeiOffenenDateien', () => {
		expect(pendingHint(0, n)).toBe('')
		expect(pendingHint(3, n)).toContain('3')
		expect(pendingHint(1, n)).toBe('1 Beleg wird noch gelesen')
	})
})

describe('suggestionsVisible', () => {
	it('zeigt den Abschnitt auch ohne Vorschläge, solange noch gelesen wird', () => {
		expect(suggestionsVisible(0, 4)).toBe(true)
		expect(suggestionsVisible(2, 0)).toBe(true)
		expect(suggestionsVisible(0, 0)).toBe(false)
	})
})
