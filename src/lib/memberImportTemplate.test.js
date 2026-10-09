import { describe, expect, it } from 'vitest'
import { firstOfNextMonth, memberImportTemplate, memberImportTemplateUrl, TEMPLATE_HEADERS } from './memberImportTemplate.js'

// Die Vorlage zum Download unter „Liste einlesen": Format der Datei (BOM,
// Semikolon, CRLF), gleich breite Zeilen und ein Beitragsbeginn, der nie in der
// Vergangenheit liegt. Ob der Parser jede Überschrift erkennt, prüft
// tests/unit/MemberCsvParserTest.php gegen dieselbe Spaltenliste.

const TODAY = new Date(2026, 9, 9) // 9. Oktober 2026
const BOM = String.fromCharCode(0xFEFF)

function lines(csv) {
	return csv.replace(BOM, '').split('\r\n').map((line) => line.split(';'))
}

describe('memberImportTemplate', () => {
	it('beginnt mit Byte-Order-Mark und trennt Zeilen mit CRLF, Zellen mit Semikolon', () => {
		const csv = memberImportTemplate(TODAY)
		expect(csv.startsWith(BOM)).toBe(true)
		expect(csv).toContain('\r\n')
		expect(csv.replace(/\r\n/g, '')).not.toContain('\n')
		expect(lines(csv)[0]).toEqual(TEMPLATE_HEADERS)
	})

	it('hat Kopfzeile und drei Beispielzeilen mit gleich vielen Spalten', () => {
		const rows = lines(memberImportTemplate(TODAY))
		expect(rows).toHaveLength(4)
		for (const row of rows) { expect(row).toHaveLength(TEMPLATE_HEADERS.length) }
	})

	it('nennt alle Spalten, auch die Stammdaten und das Eintrittsdatum', () => {
		for (const header of ['Vorname', 'Nachname', 'Organisation', 'Eintritt', 'Straße', 'PLZ', 'Ort', 'Telefon', 'Mandat am', 'Beitragsgruppe', 'Start']) {
			expect(TEMPLATE_HEADERS).toContain(header)
		}
	})

	it('zeigt eine Person mit Mandat und Beitrag, eine Organisation und ein Mitglied ohne Mandat', () => {
		const [header, person, organization, withoutMandate] = lines(memberImportTemplate(TODAY))
		const cell = (row, name) => row[header.indexOf(name)]

		expect(cell(person, 'Nachname')).not.toBe('')
		expect(cell(person, 'IBAN')).not.toBe('')
		expect(cell(person, 'Mandat am')).not.toBe('')
		expect(cell(person, 'Betrag')).not.toBe('')

		expect(cell(organization, 'Organisation')).not.toBe('')
		expect(cell(organization, 'Vorname')).toBe('')
		expect(cell(organization, 'IBAN')).toBe('')

		expect(cell(withoutMandate, 'Nachname')).not.toBe('')
		expect(cell(withoutMandate, 'IBAN')).toBe('')
		expect(cell(withoutMandate, 'Betrag')).not.toBe('')
	})

	it('zeigt einen Eintritt in der Vergangenheit', () => {
		const [header, person] = lines(memberImportTemplate(TODAY))
		expect(person[header.indexOf('Eintritt')]).toBe('01.03.2019')
	})

	it('erfindet keine Beitragsgruppen', () => {
		expect(memberImportTemplate(TODAY)).not.toContain('Chormitglieder')
	})

	it('setzt den Beitragsbeginn auf den Ersten des Folgemonats', () => {
		const [header, person, , withoutMandate] = lines(memberImportTemplate(TODAY))
		expect(person[header.indexOf('Start')]).toBe('01.11.2026')
		expect(withoutMandate[header.indexOf('Start')]).toBe('01.11.2026')
	})

	it('liegt der Beitragsbeginn auch zum Jahreswechsel nach „heute"', () => {
		expect(firstOfNextMonth(new Date(2026, 11, 31))).toBe('01.01.2027')
		expect(firstOfNextMonth(new Date(2026, 0, 1))).toBe('01.02.2026')
	})

	it('liefert eine Daten-URL, die sich zurück in die Vorlage dekodieren lässt', () => {
		const url = memberImportTemplateUrl(TODAY)
		expect(url.startsWith('data:text/csv;charset=utf-8,')).toBe(true)
		expect(decodeURIComponent(url.slice('data:text/csv;charset=utf-8,'.length))).toBe(memberImportTemplate(TODAY))
	})
})
