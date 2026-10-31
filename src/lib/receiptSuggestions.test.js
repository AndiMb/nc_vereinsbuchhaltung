import { mdiHelpCircleOutline, mdiPaperclipCheck } from '@mdi/js'
import { describe, expect, it } from 'vitest'
import { levelIcon, pendingHint, reasonLine, reasonText, suggestionsVisible, suggestionTitle, sureSuggestionPairs } from './receiptSuggestions.js'

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

describe('suggestionTitle', () => {
	const n = (singular, plural, count) => (count === 1 ? singular : plural).replace('%n', String(count))
	const names = { 7: 'RG-28-26.pdf' }
	it('nennt bei sicherem Vorschlag die Datei', () => {
		expect(suggestionTitle({ level: 'sure', fileIds: [7] }, names, n)).toBe('Passender Beleg gefunden: RG-28-26.pdf')
	})
	it('zählt bei möglichen Vorschlägen', () => {
		expect(suggestionTitle({ level: 'possible', fileIds: [7, 8] }, names, n)).toContain('2')
		expect(suggestionTitle({ level: 'possible', fileIds: [7] }, names, n)).toContain('1')
	})
	it('toleriert fehlenden Eintrag', () => {
		expect(suggestionTitle(null, names, n)).toBe('')
	})
})

describe('sureSuggestionPairs', () => {
	const rows = [
		{ id: 1, entryNo: 1, date: '2026-01-02', description: 'A', amount: 119 },
		{ id: 2, entryNo: 2, date: '2026-01-03', description: 'B', amount: 50 },
		{ id: 3, entryNo: 3, date: '2026-01-04', description: 'C', amount: 10 },
	]
	const items = {
		1: { level: 'sure', fileIds: [7], reasons: { 7: [{ kind: 'amount', cents: 11900 }] } },
		2: { level: 'possible', fileIds: [8], reasons: { 8: [] } },
		3: { level: 'sure', fileIds: [9], reasons: { 9: [] } },
	}
	const names = { 7: 'a.pdf', 8: 'b.pdf', 9: 'c.pdf' }

	it('nimmt nur sichere Einträge mit bekannter Buchung', () => {
		const pairs = sureSuggestionPairs(items, names, rows.slice(0, 2))
		expect(pairs).toEqual([{
			journalId: 1,
			entryNo: 1,
			date: '2026-01-02',
			description: 'A',
			amount: 119,
			fileId: 7,
			fileName: 'a.pdf',
			reasons: [{ kind: 'amount', cents: 11900 }],
		}])
	})
	it('toleriert leeres Array vom Server und fehlende Angaben', () => {
		expect(sureSuggestionPairs([], names, rows)).toEqual([])
		expect(sureSuggestionPairs(null, names, rows)).toEqual([])
	})
	it('behandelt Schlüssel als Text und sortiert nach Buchungsliste', () => {
		const pairs = sureSuggestionPairs(items, names, [rows[2], rows[0]])
		expect(pairs.map((p) => p.journalId)).toEqual([3, 1])
	})
})
