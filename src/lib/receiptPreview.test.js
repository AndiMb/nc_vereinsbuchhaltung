import { describe, expect, it } from 'vitest'
import { adjacentFile, bookingsContext, previewKind, txContext } from './receiptPreview.js'

describe('previewKind', () => {
	it('erkennt PDF und gängige Bildtypen am MIME-Typ', () => {
		expect(previewKind('application/pdf')).toBe('pdf')
		expect(previewKind('image/jpeg')).toBe('image')
		expect(previewKind('image/png')).toBe('image')
		expect(previewKind('image/webp')).toBe('image')
	})

	it('zeigt SVG, HEIC und Fremdes nicht an', () => {
		expect(previewKind('image/svg+xml')).toBe('none')
		expect(previewKind('image/heic')).toBe('none')
		expect(previewKind('text/xml')).toBe('none')
	})

	it('fällt ohne brauchbaren MIME-Typ auf die Dateiendung zurück', () => {
		expect(previewKind('', 'scan.JPG')).toBe('image')
		expect(previewKind('application/octet-stream', 'R1.pdf')).toBe('pdf')
		expect(previewKind(undefined, 'x.docx')).toBe('none')
		expect(previewKind(undefined)).toBe('none')
	})
})

describe('adjacentFile', () => {
	const file = (fileId) => ({ kind: 'file', file: { fileId } })
	const folder = { kind: 'folder', node: { path: 'A' } }
	const rows = [folder, file(1), folder, file(2), file(3)]

	it('springt über Ordnerzeilen zur nächsten bzw. vorigen Datei', () => {
		expect(adjacentFile(rows, 1, 1)).toBe(2)
		expect(adjacentFile(rows, 2, -1)).toBe(1)
	})

	it('bleibt an den Rändern stehen', () => {
		expect(adjacentFile(rows, 3, 1)).toBe(3)
		expect(adjacentFile(rows, 1, -1)).toBe(1)
	})

	it('beginnt vorn bzw. hinten, wenn die aktuelle Datei nicht (mehr) sichtbar ist', () => {
		expect(adjacentFile(rows, 99, 1)).toBe(1)
		expect(adjacentFile(rows, 99, -1)).toBe(3)
		expect(adjacentFile(rows, null, 1)).toBe(1)
	})

	it('liefert null ohne Dateien', () => {
		expect(adjacentFile([], null, 1)).toBe(null)
		expect(adjacentFile([folder], 5, -1)).toBe(null)
	})
})

describe('txContext', () => {
	it('übernimmt Datum, Betrag, Verwendungszweck und Gegenseite des Umsatzes', () => {
		expect(txContext({ id: 7, bookingDate: '2026-09-12', amount: -49.95, purpose: 'Rechnung 4711', counterparty: 'Telekom' }))
			.toEqual({ kind: 'tx', date: '2026-09-12', amount: -49.95, purpose: 'Rechnung 4711', counterparty: 'Telekom' })
	})
})

describe('bookingsContext', () => {
	const rows = [
		{ id: 1, entryNo: 11, date: '2026-01-01', amount: 10, description: 'A' },
		{ id: 2, entryNo: 12, date: '2026-01-02', amount: 20, description: 'B' },
		{ id: 3, entryNo: 13, date: '2026-01-03', amount: 30, description: 'C' },
	]

	it('liefert die Buchungen in der Reihenfolge der IDs und übergeht unbekannte', () => {
		expect(bookingsContext(rows, [3, 99, 1])).toEqual({
			kind: 'bookings',
			items: [
				{ entryNo: 13, date: '2026-01-03', amount: 30, description: 'C' },
				{ entryNo: 11, date: '2026-01-01', amount: 10, description: 'A' },
			],
		})
	})
})
