// Vorlage zum Download im Dialog „Rechnungen aus Tabelle“ (#243,
// InvoiceBatchImportDialog.vue): die Spalten, die BatchSheetParser kennt, mit
// Beispielzeilen für Ansatz A – eine Zeile pro Position.
//
// Format wie die Mitglieder-Vorlage: Semikolon, UTF-8 mit Byte-Order-Mark (so
// öffnet Excel die Umlaute richtig) und CRLF. Wer mit Excel arbeitet, speichert
// die Datei danach gern als .xlsx – beides lässt sich hochladen.

/** Überschriften in der Reihenfolge der Vorlage – jede erkennt der Parser. */
export const BATCH_TEMPLATE_HEADERS = [
	'Rechnung',
	'Name',
	'Straße',
	'PLZ',
	'Ort',
	'E-Mail',
	'Position',
	'Beschreibung',
	'Menge',
	'Einheit',
	'Einzelpreis',
	'Erlöskonto',
	'Leistungsdatum',
]

const BOM = String.fromCharCode(0xFEFF)

/**
 * Die Vorlage als CSV-Text.
 *
 * Die Beispiele zeigen die Gruppierung: Anna hat zwei Positionen, die zweite
 * Zeile lässt die Anschrift leer und gehört damit zur Rechnung darüber. „Rechnung“
 * ist optional und nur nötig, wenn dieselbe Person mehrere Rechnungen bekommt.
 *
 * @return {string}
 */
export function invoiceBatchTemplate() {
	const examples = [
		{ Rechnung: '1', Name: 'Anna Beispiel', Straße: 'Musterweg 12', PLZ: '12345', Ort: 'Musterstadt', 'E-Mail': 'anna@example.org', Position: 'Teilnahme Probenwochenende', Menge: '1', Einheit: 'pauschal', Einzelpreis: '120,00', Leistungsdatum: '14.03.2026' },
		{ Rechnung: '1', Position: 'Übernachtung', Beschreibung: 'Doppelzimmer', Menge: '2', Einheit: 'Tag', Einzelpreis: '35,00', Leistungsdatum: '15.03.2026' },
		{ Rechnung: '2', Name: 'Ben Muster', Straße: 'Lindenallee 5', PLZ: '12345', Ort: 'Musterstadt', Position: 'Teilnahme Probenwochenende (ermäßigt)', Menge: '1', Einheit: 'pauschal', Einzelpreis: '80,00', Leistungsdatum: '14.03.2026' },
	]
	const rows = examples.map((example) => BATCH_TEMPLATE_HEADERS.map((header) => example[header] ?? ''))
	return BOM + [BATCH_TEMPLATE_HEADERS, ...rows].map((cells) => cells.join(';')).join('\r\n')
}

/** Die Vorlage als Daten-URL: kein zusätzlicher Endpunkt nötig. */
export function invoiceBatchTemplateUrl() {
	return 'data:text/csv;charset=utf-8,' + encodeURIComponent(invoiceBatchTemplate())
}
