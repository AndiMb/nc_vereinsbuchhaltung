import { describe, expect, it } from 'vitest'
import { importBlocker, missingByKind, outcomeUnknown, passwordProblem, wipeWord } from './backup.js'

describe('passwordProblem', () => {
	it('verlangt mindestens zehn Zeichen', () => {
		expect(passwordProblem('123456789', '123456789')).toBe('short')
		expect(passwordProblem('1234567890', '1234567890')).toBe('')
		expect(passwordProblem('', '')).toBe('short')
	})

	it('zählt Zeichen, nicht Bytes – wie der Server', () => {
		expect(passwordProblem('äöüäöüäöüä', 'äöüäöüäöüä')).toBe('')
		expect(passwordProblem('äöüäö', 'äöüäö')).toBe('short')
	})

	it('verlangt zweimal dasselbe Passwort', () => {
		expect(passwordProblem('Sehr-Geheim-2026', 'Sehr-Geheim-2027')).toBe('mismatch')
		expect(passwordProblem('Sehr-Geheim-2026', '')).toBe('mismatch')
	})

	it('meldet die Länge vor der Abweichung', () => {
		expect(passwordProblem('kurz', 'anders')).toBe('short')
	})

	it('nimmt die Mindestlänge vom Server', () => {
		expect(passwordProblem('1234567890', '1234567890', 12)).toBe('short')
	})
})

describe('importBlocker', () => {
	const ok = { compatible: true, targetEmpty: true }

	it('lässt eine passende Sicherung in einen leeren Bestand', () => {
		expect(importBlocker(ok)).toBe('')
	})

	it('sperrt bei anderer App-Version', () => {
		expect(importBlocker({ ...ok, compatible: false })).toBe('version')
	})

	it('sperrt bei nicht leerem Bestand', () => {
		expect(importBlocker({ ...ok, targetEmpty: false })).toBe('notEmpty')
	})

	it('nennt die Version zuerst – sie lässt sich hier nicht beheben', () => {
		expect(importBlocker({ compatible: false, targetEmpty: false })).toBe('version')
	})

	it('sperrt, solange nichts geprüft ist', () => {
		expect(importBlocker(null)).toBe('unchecked')
	})
})

describe('wipeWord', () => {
	it('ist der Vereinsname', () => {
		expect(wipeWord('SC Muster e.V.')).toBe('SC Muster e.V.')
		expect(wipeWord('  SC Muster e.V.  ')).toBe('SC Muster e.V.')
	})

	it('ist ohne Vereinsnamen das feste Wort', () => {
		expect(wipeWord('')).toBe('LÖSCHEN')
		expect(wipeWord('   ')).toBe('LÖSCHEN')
		expect(wipeWord(null)).toBe('LÖSCHEN')
	})
})

describe('missingByKind', () => {
	it('zählt fehlende Dateien je Art in fester Reihenfolge', () => {
		expect(missingByKind([
			{ kind: 'mandates', id: 3 },
			{ kind: 'attachments', id: 6 },
			{ kind: 'attachments', id: 9 },
		])).toEqual([
			{ kind: 'attachments', count: 2, labels: [], more: 2 },
			{ kind: 'mandates', count: 1, labels: [], more: 1 },
		])
	})

	it('nennt die Dateien beim Namen, soweit die Sicherung ihn kennt', () => {
		expect(missingByKind([
			{ kind: 'attachments', id: 6, label: 'quittung-baumarkt.pdf' },
			{ kind: 'attachments', id: 9, label: '' },
			{ kind: 'mandates', id: 3, label: 'MREF-0003' },
		])).toEqual([
			{ kind: 'attachments', count: 2, labels: ['quittung-baumarkt.pdf'], more: 1 },
			{ kind: 'mandates', count: 1, labels: ['MREF-0003'], more: 0 },
		])
	})

	it('zeigt höchstens zehn Namen je Art und zählt den Rest', () => {
		const missing = Array.from({ length: 13 }, (_, i) => ({ kind: 'invoices', id: i + 1, label: `RE-${i + 1}.pdf` }))
		const [invoices] = missingByKind(missing)
		expect(invoices.count).toBe(13)
		expect(invoices.labels).toHaveLength(10)
		expect(invoices.labels[0]).toBe('RE-1.pdf')
		expect(invoices.more).toBe(3)
	})

	it('ist leer, wenn nichts fehlt', () => {
		expect(missingByKind([])).toEqual([])
		expect(missingByKind(undefined)).toEqual([])
	})
})

describe('outcomeUnknown', () => {
	it('weiß bei einer Antwort des Servers, woran es lag', () => {
		expect(outcomeUnknown({ response: { status: 400, data: { message: 'Die Buchhaltung ist nicht leer.' } } })).toBe(false)
		expect(outcomeUnknown({ response: { status: 500, data: { message: 'Das Einspielen ist fehlgeschlagen.' } } })).toBe(false)
		expect(outcomeUnknown({ response: { status: 403, data: {} } })).toBe(false)
	})

	it('weiß es nicht, wenn die Verbindung abbrach oder ein Proxy die Geduld verlor', () => {
		expect(outcomeUnknown({ request: {}, message: 'Network Error' })).toBe(true)
		expect(outcomeUnknown({ code: 'ECONNABORTED' })).toBe(true)
		expect(outcomeUnknown({ response: { status: 502 } })).toBe(true)
		expect(outcomeUnknown({ response: { status: 503 } })).toBe(true)
		expect(outcomeUnknown({ response: { status: 504 } })).toBe(true)
		expect(outcomeUnknown(null)).toBe(true)
	})
})
