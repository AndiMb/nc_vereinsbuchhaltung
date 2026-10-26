import { describe, expect, it } from 'vitest'
import { cleanLabel, columnLabels, labelsForRow } from './stackTables.js'

describe('Beschriftungen gestapelter Tabellen', () => {
	it('liest die Spalten aus der Kopfzeile und zählt mehrspaltige Köpfe mehrfach', () => {
		expect(columnLabels([{ text: ' Konto ', colspan: 1 }, { text: 'Betrag', colspan: 2 }, { text: '', colspan: 1 }])).toEqual(['Konto', 'Betrag', 'Betrag', ''])
	})

	it('entfernt Sortierpfeile und doppelte Leerzeichen', () => {
		expect(cleanLabel('Datum ▲')).toBe('Datum')
		expect(cleanLabel('Nächste \n  Fälligkeit')).toBe('Nächste Fälligkeit')
		expect(cleanLabel(null)).toBe('')
	})

	it('beschriftet Zellen nach Spalte, überspringt Aktionen und mehrspaltige Zellen', () => {
		const columns = ['Name', 'Betrag', '']
		expect(labelsForRow(columns, [{ colspan: 1 }, { colspan: 1 }, { colspan: 1 }])).toEqual(['Name', 'Betrag', null])
		expect(labelsForRow(columns, [{ colspan: 3 }])).toEqual([null])
		expect(labelsForRow(columns, [{ colspan: 1 }, { colspan: 2 }])).toEqual(['Name', null])
	})

	it('verschiebt die Spalten hinter einer mehrspaltigen Zelle richtig', () => {
		expect(labelsForRow(['A', 'B', 'C', 'D'], [{ colspan: 2 }, { colspan: 1 }, { colspan: 1 }])).toEqual([null, 'C', 'D'])
	})
})
