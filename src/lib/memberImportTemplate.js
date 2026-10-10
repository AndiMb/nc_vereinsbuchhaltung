// Vorlage zum Download unter „Liste einlesen" (MemberImportDialog.vue): alle
// Spalten, die MemberCsvParser kennt, mit drei Beispielzeilen. Als Modul
// herausgelöst, damit sich Aufbau und Datumsangaben ohne Dialog prüfen lassen.
//
// Format der Datei: Semikolon, UTF-8 mit Byte-Order-Mark (so öffnet Excel die
// Umlaute richtig) und CRLF. Die Beispiele erfinden keine Beitragsgruppen:
// „Vollmitglied" und „Ermäßigt" sind die üblichen Namen; wer andere hat,
// ersetzt sie (eine unbekannte Gruppe ist im Prüflauf eine Warnung, kein Fehler).

/** Die Überschriften in der Reihenfolge der Vorlage – jede erkennt der Parser. */
export const TEMPLATE_HEADERS = [
	'Vorname',
	'Nachname',
	'Organisation',
	'Mitgliedsnummer',
	'Eintritt',
	'Straße',
	'PLZ',
	'Ort',
	'Telefon',
	'E-Mail',
	'IBAN',
	'BIC',
	'Kontoinhaber',
	'Mandat am',
	'Mandatsreferenz',
	'Beitragsgruppe',
	'Betrag',
	'Frequenz',
	'Start',
]

/** Byte-Order-Mark; als Rechnung statt Zeichen im Quelltext, damit man es sieht. */
const BOM = String.fromCharCode(0xFEFF)

function pad(n) {
	return String(n).padStart(2, '0')
}

/**
 * Der Erste des Folgemonats als TT.MM.JJJJ. Der Beginn einer Zuweisung darf nicht
 * in der Vergangenheit liegen; ein fest eingetragenes Datum würde die Vorlage
 * mit der Zeit unbrauchbar machen.
 *
 * @param {Date} today
 * @return {string}
 */
export function firstOfNextMonth(today) {
	const next = new Date(today.getFullYear(), today.getMonth() + 1, 1)
	return `01.${pad(next.getMonth() + 1)}.${next.getFullYear()}`
}

/**
 * Die Vorlage als CSV-Text: mit Byte-Order-Mark, Semikolon und CRLF.
 *
 * @param {Date} [today] „Heute", für den Beitragsbeginn der Beispiele
 * @return {string}
 */
export function memberImportTemplate(today = new Date()) {
	const start = firstOfNextMonth(today)
	// Je Beispiel nur die gefüllten Zellen; der Rest bleibt leer.
	const examples = [
		// Person mit Mandat und Beitrag (Monatsbeitrag 15 €, monatlich eingezogen)
		{
			Vorname: 'Anna',
			Nachname: 'Beispiel',
			Mitgliedsnummer: '1001',
			Eintritt: '01.03.2019',
			Straße: 'Musterweg 12',
			PLZ: '12345',
			Ort: 'Musterstadt',
			Telefon: '0123 456789',
			'E-Mail': 'anna.beispiel@example.org',
			IBAN: 'DE02 1203 0000 0000 2020 51',
			'Mandat am': '15.01.2025',
			Beitragsgruppe: 'Vollmitglied',
			Betrag: '15,00',
			Frequenz: 'monatlich',
			Start: start,
		},
		// Organisation, nur Stammdaten
		{
			Organisation: 'Musikhaus Beispiel GmbH',
			Mitgliedsnummer: '1002',
			Eintritt: '01.01.2021',
			Straße: 'Hauptstraße 1',
			PLZ: '12345',
			Ort: 'Musterstadt',
			'E-Mail': 'info@musikhaus.example',
		},
		// Mitglied ohne Mandat, zahlt per Überweisung (5 € im Monat = 60 € im Jahr)
		{
			Vorname: 'Ben',
			Nachname: 'Muster',
			Mitgliedsnummer: '1003',
			Eintritt: '15.09.2023',
			Straße: 'Lindenallee 5',
			PLZ: '12345',
			Ort: 'Musterstadt',
			Beitragsgruppe: 'Ermäßigt',
			Betrag: '5,00',
			Frequenz: 'jährlich',
			Start: start,
		},
	]
	const rows = examples.map((example) => TEMPLATE_HEADERS.map((header) => example[header] ?? ''))
	return BOM + [TEMPLATE_HEADERS, ...rows].map((cells) => cells.join(';')).join('\r\n')
}

/**
 * Die Vorlage als Daten-URL: kein zusätzlicher Endpunkt nötig.
 *
 * @param {Date} [today]
 * @return {string}
 */
export function memberImportTemplateUrl(today = new Date()) {
	return 'data:text/csv;charset=utf-8,' + encodeURIComponent(memberImportTemplate(today))
}
