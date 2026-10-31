// Belegvorschau neben der Belegauswahl (#231, ReceiptPreview.vue,
// docs/superpowers/specs/2026-10-31-belegvorschau-design.md): welche
// Darstellung eine Datei bekommt, welche Datei die Pfeiltasten als nächste
// zeigen, und die Angaben für den Kontextstreifen im Ordner-Dialog.

const IMAGE_MIMES = new Set(['image/png', 'image/jpeg', 'image/gif', 'image/webp'])
const IMAGE_EXTENSIONS = new Set(['png', 'jpg', 'jpeg', 'gif', 'webp'])

/**
 * Darstellung einer Datei: PDF im eingebauten Viewer des Browsers, Bild als
 * <img>, sonst keine Vorschau. SVG und HEIC bewusst nicht: SVG kann Skripte
 * tragen, HEIC zeigen die meisten Browser nicht an. Ohne brauchbaren
 * MIME-Typ entscheidet die Dateiendung.
 *
 * @param {string|undefined} mime MIME-Typ der Datei
 * @param {string} [name] Dateiname, für den Rückfall auf die Endung
 * @return {'pdf'|'image'|'none'}
 */
export function previewKind(mime, name = '') {
	const type = (mime || '').toLowerCase()
	if (type === 'application/pdf') { return 'pdf' }
	if (IMAGE_MIMES.has(type)) { return 'image' }
	if (type && type !== 'application/octet-stream') { return 'none' }
	const dot = name.lastIndexOf('.')
	const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : ''
	if (ext === 'pdf') { return 'pdf' }
	return IMAGE_EXTENSIONS.has(ext) ? 'image' : 'none'
}

// Wie weit vorn der PDF-Kopf und wie weit hinten startxref/%%EOF stehen dürfen.
const PDF_HEAD_WINDOW = 1024
const PDF_TAIL_WINDOW = 2048

/**
 * Sieht die Datei wie ein vollständiges PDF aus? Kopf „%PDF-“ am Anfang,
 * „startxref“ und „%%EOF“ am Ende. Grob, aber genug für abgeschnittene und
 * unvollständige Dateien: die würden sonst im PDF-Viewer des Browsers eine
 * Fehlermeldung zeigen, deren „Schließen“ in Edge den ganzen App-Tab
 * schließt. Innen beschädigte, außen intakte PDFs erkennt das nicht.
 *
 * @param {Uint8Array} bytes Inhalt der Datei
 * @return {boolean}
 */
export function looksLikeCompletePdf(bytes) {
	const latin1 = (from, to) => String.fromCharCode(...bytes.subarray(from, to))
	const head = latin1(0, Math.min(bytes.length, PDF_HEAD_WINDOW))
	const tail = latin1(Math.max(0, bytes.length - PDF_TAIL_WINDOW), bytes.length)
	return head.includes('%PDF-') && tail.includes('startxref') && tail.includes('%%EOF')
}

/**
 * Nächste (dir = 1) bzw. vorige (dir = -1) Datei in der sichtbaren Liste,
 * Ordnerzeilen übersprungen (Zeilenformat aus lib/folderTree.js). An den
 * Rändern bleibt es bei der aktuellen; ist sie nicht (mehr) sichtbar,
 * beginnt es vorn bzw. hinten.
 *
 * @param {Array<{kind: string, file?: {fileId: number}}>} rows sichtbare Zeilen
 * @param {number|null} currentId angezeigte Datei
 * @param {1|-1} dir Richtung
 * @return {number|null}
 */
export function adjacentFile(rows, currentId, dir) {
	const ids = rows.filter((r) => r.kind === 'file').map((r) => r.file.fileId)
	if (!ids.length) { return null }
	const at = ids.indexOf(currentId)
	if (at < 0) { return dir > 0 ? ids[0] : ids[ids.length - 1] }
	return ids[Math.min(ids.length - 1, Math.max(0, at + dir))]
}

/**
 * Kontextstreifen für die Belegauswahl zu einem Bankumsatz.
 *
 * @param {{bookingDate: string, amount: number, purpose?: string, counterparty?: string}} tx Bankumsatz
 * @return {{kind: 'tx', date: string, amount: number, purpose: string, counterparty: string}}
 */
export function txContext(tx) {
	return { kind: 'tx', date: tx.bookingDate, amount: tx.amount, purpose: tx.purpose || '', counterparty: tx.counterparty || '' }
}

/**
 * Kontextstreifen für die Belegauswahl zu mehreren Buchungen, in der
 * Reihenfolge der IDs; nicht (mehr) geladene Buchungen fallen weg.
 *
 * @param {Array<{id: number, entryNo: number, date: string, amount: number, description: string}>} journalRows geladene Journalzeilen
 * @param {number[]} journalIds gewählte Buchungen
 * @return {{kind: 'bookings', items: Array<{entryNo: number, date: string, amount: number, description: string}>}}
 */
export function bookingsContext(journalRows, journalIds) {
	const byId = new Map(journalRows.map((r) => [r.id, r]))
	const items = journalIds
		.map((id) => byId.get(id))
		.filter(Boolean)
		.map(({ entryNo, date, amount, description }) => ({ entryNo, date, amount, description }))
	return { kind: 'bookings', items }
}
