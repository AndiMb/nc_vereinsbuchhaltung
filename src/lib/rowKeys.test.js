import { describe, expect, it } from 'vitest'
import { newUid, statementRowKeys } from './rowKeys.js'

describe('statementRowKeys', () => {
	it('unterscheidet Zeilen derselben Buchung auf verschiedenen Konten', () => {
		expect(statementRowKeys([{ journalId: 5, accountId: 1 }, { journalId: 5, accountId: 2 }])).toEqual(['5-1', '5-2'])
	})

	it('haelt mehrere Zeilen einer Buchung auf demselben Konto auseinander', () => {
		const keys = statementRowKeys([{ journalId: 5, accountId: 1 }, { journalId: 6, accountId: 1 }, { journalId: 5, accountId: 1 }])
		expect(keys).toEqual(['5-1', '6-1', '5-1#2'])
		expect(new Set(keys).size).toBe(3)
	})

	it('verträgt fehlende Zeilen', () => {
		expect(statementRowKeys(null)).toEqual([])
	})
})

describe('newUid', () => {
	it('liefert jedes Mal einen neuen Wert', () => {
		expect(newUid()).not.toBe(newUid())
	})
})
