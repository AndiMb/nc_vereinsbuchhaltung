import { describe, expect, it } from 'vitest'
import { accountMissing, accountOptions, DAYS_MAX, DAYS_MIN, daysError, leadDaysExample, leadDaysOrderHints, monthOptions, nextMonthStart, saveErrorMessage, shiftIsoDate } from './sepaSettings.js'

// Die Pruefungen der Einstellungskarten spiegeln die des Servers. Hier steht,
// was ohne laufende Nextcloud pruefbar ist.

describe('monthOptions', () => {
	it('liefert zwölf Monate von 1 bis 12 in deutscher Schreibweise', () => {
		const months = monthOptions('de')

		expect(months).toHaveLength(12)
		expect(months.map((m) => m.value)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])
		expect(months[0].label).toBe('Januar')
		expect(months[9].label).toBe('Oktober')
	})

	it('folgt der gewünschten Sprache', () => {
		expect(monthOptions('en')[2].label).toBe('March')
	})

	it('versteht Nextclouds Schreibweise mit Unterstrich (de_DE)', () => {
		expect(monthOptions('de_DE')[2].label).toBe('März')
	})

	it('fällt bei einem unbrauchbaren Sprachcode auf Deutsch zurück, statt zu werfen', () => {
		expect(monthOptions('kein sprachcode')[2].label).toBe('März')
	})
})

describe('daysError', () => {
	it('akzeptiert die Grenzen und alles dazwischen', () => {
		expect(daysError(DAYS_MIN, 'Mahnabstand')).toBeNull()
		expect(daysError(14, 'Mahnabstand')).toBeNull()
		expect(daysError(DAYS_MAX, 'Mahnabstand')).toBeNull()
		expect(daysError('21', 'Mahnabstand')).toBeNull()
	})

	it('nennt bei einem Wert außerhalb das Feld und den erlaubten Bereich', () => {
		const message = daysError(0, 'Mahnabstand')

		expect(message).toContain('Mahnabstand')
		expect(message).toContain(String(DAYS_MIN))
		expect(message).toContain(String(DAYS_MAX))
		expect(daysError(DAYS_MAX + 1, 'Mahnabstand')).toContain('Mahnabstand')
		expect(daysError(-3, 'Mahnabstand')).toContain('Mahnabstand')
	})

	it('weist leere und nicht ganzzahlige Eingaben ab', () => {
		expect(daysError('', 'Freigabe-Vorlauf')).toContain('ganze Zahl')
		expect(daysError(null, 'Freigabe-Vorlauf')).toContain('ganze Zahl')
		expect(daysError(undefined, 'Freigabe-Vorlauf')).toContain('ganze Zahl')
		expect(daysError(2.5, 'Freigabe-Vorlauf')).toContain('ganze Zahl')
		expect(daysError('abc', 'Freigabe-Vorlauf')).toContain('ganze Zahl')
	})
})

describe('accountOptions', () => {
	const accounts = [
		{ id: 1, number: '1200', name: 'Girokonto', type: 'asset', isBank: true, active: true },
		{ id: 2, number: '4000', name: 'Mitgliedsbeiträge', type: 'income', isBank: false, active: true },
		{ id: 3, number: '4100', name: 'Spenden', type: 'income', isBank: false, active: true },
		{ id: 4, number: '6900', name: 'Bankgebühren', type: 'expense', isBank: false, active: true },
		{ id: 5, number: '6990', name: 'Alte Gebühren', type: 'expense', isBank: false, active: false },
		{ id: 6, number: '4050', name: 'Beitrag alt', type: 'income', isBank: false, active: false },
	]

	it('bietet nur aktive Nicht-Geldkonten der verlangten Kontoart an', () => {
		expect(accountOptions(accounts, 'income', null).map((o) => o.id)).toEqual([2, 3])
		expect(accountOptions(accounts, 'expense', null).map((o) => o.id)).toEqual([4])
	})

	it('beschriftet mit Kontonummer und Name', () => {
		expect(accountOptions(accounts, 'expense', null)[0].label).toBe('6900 · Bankgebühren')
	})

	it('sortiert nach Kontonummer, nicht nach Reihenfolge der Liste', () => {
		const shuffled = [accounts[2], accounts[1]]

		expect(accountOptions(shuffled, 'income', null).map((o) => o.id)).toEqual([2, 3])
	})

	it('behält ein gespeichertes, nicht mehr passendes Konto mit Vermerk in der Liste', () => {
		const options = accountOptions(accounts, 'expense', 5)

		expect(options.map((o) => o.id)).toEqual([4, 5])
		expect(options.find((o) => o.id === 5).unsuitable).toBe(true)
		expect(options.find((o) => o.id === 4).unsuitable).toBe(false)
	})

	it('führt ein gespeichertes, passendes Konto nicht doppelt auf', () => {
		expect(accountOptions(accounts, 'expense', 4).map((o) => o.id)).toEqual([4])
	})

	it('ignoriert ein gespeichertes Konto, das es nicht mehr gibt', () => {
		expect(accountOptions(accounts, 'expense', 99).map((o) => o.id)).toEqual([4])
	})
})

describe('accountMissing', () => {
	const accounts = [{ id: 1 }]

	it('erkennt ein gespeichertes, aber gelöschtes Konto', () => {
		expect(accountMissing(accounts, 99)).toBe(true)
		expect(accountMissing(accounts, 1)).toBe(false)
	})

	it('meldet nichts, solange kein Konto gewählt ist oder die Konten noch nicht geladen sind', () => {
		expect(accountMissing(accounts, null)).toBe(false)
		expect(accountMissing([], 99)).toBe(false)
	})
})

describe('saveErrorMessage', () => {
	it('zeigt die Meldung des Servers', () => {
		const e = { response: { status: 400, data: { message: 'Das Konto für Rücklastschriftgebühren muss ein Aufwandskonto sein.' } } }

		expect(saveErrorMessage(e)).toBe('Das Konto für Rücklastschriftgebühren muss ein Aufwandskonto sein.')
	})

	it('fällt auf den HTTP-Status zurück, wenn der Server keine Meldung mitgibt', () => {
		expect(saveErrorMessage({ response: { status: 500, data: {} } })).toBe('Speichern fehlgeschlagen (HTTP 500)')
	})

	it('nennt einen Netzwerkfehler als solchen', () => {
		expect(saveErrorMessage(new Error('Network Error'))).toBe('Speichern fehlgeschlagen (HTTP Netzwerkfehler)')
	})
})

describe('shiftIsoDate / nextMonthStart', () => {
	it('verschiebt über Monats- und Jahresgrenzen', () => {
		expect(shiftIsoDate('2026-11-01', -14)).toBe('2026-10-18')
		expect(shiftIsoDate('2027-01-05', -10)).toBe('2026-12-26')
		expect(shiftIsoDate('2026-03-01', -1)).toBe('2026-02-28')
	})

	it('liefert den nächsten Monatsersten nach dem Stichtag', () => {
		expect(nextMonthStart('2026-10-09')).toBe('2026-11-01')
		expect(nextMonthStart('2026-10-01')).toBe('2026-11-01')
		expect(nextMonthStart('2026-12-31')).toBe('2027-01-01')
	})
})

describe('leadDaysExample', () => {
	it('rechnet die drei Meilensteine vom Einzugstermin zurück', () => {
		expect(leadDaysExample('2026-11-01', { warningLeadDays: 21, prenotificationLeadDays: 14, releaseLeadDays: 5 })).toEqual({
			due: '2026-11-01',
			warning: '2026-10-11',
			prenotification: '2026-10-18',
			release: '2026-10-27',
		})
	})

	it('nimmt auch Zahlen aus Eingabefeldern als Text', () => {
		expect(leadDaysExample('2026-11-01', { warningLeadDays: '30', prenotificationLeadDays: '14', releaseLeadDays: '5' }).warning).toBe('2026-10-02')
	})
})

describe('leadDaysOrderHints', () => {
	it('schweigt bei der üblichen Reihenfolge, auch bei gleichen Werten', () => {
		expect(leadDaysOrderHints({ warningLeadDays: 21, prenotificationLeadDays: 14, releaseLeadDays: 5 })).toEqual([])
		expect(leadDaysOrderHints({ warningLeadDays: 14, prenotificationLeadDays: 14, releaseLeadDays: 14 })).toEqual([])
	})

	it('weist auf ein zu kurzes Vorwarnfenster und einen zu kurzen Vorabinfo-Vorlauf hin', () => {
		expect(leadDaysOrderHints({ warningLeadDays: 7, prenotificationLeadDays: 14, releaseLeadDays: 5 })).toHaveLength(1)
		expect(leadDaysOrderHints({ warningLeadDays: 21, prenotificationLeadDays: 3, releaseLeadDays: 5 })).toHaveLength(1)
		expect(leadDaysOrderHints({ warningLeadDays: 2, prenotificationLeadDays: 3, releaseLeadDays: 5 })).toHaveLength(2)
	})
})
