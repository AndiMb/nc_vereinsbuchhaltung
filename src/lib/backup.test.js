import { describe, expect, it } from 'vitest'
import { importBlocker, missingByKind, passwordProblem, wipeWord } from './backup.js'

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
			{ kind: 'attachments', count: 2 },
			{ kind: 'mandates', count: 1 },
		])
	})

	it('ist leer, wenn nichts fehlt', () => {
		expect(missingByKind([])).toEqual([])
		expect(missingByKind(undefined)).toEqual([])
	})
})
