// Belegdateien eines Auslagenantrags: dieselben Grenzen wie auf dem Server
// (ReimbursementFileService, SelfReimbursementController), damit eine zu
// große oder falsche Datei schon beim Auswählen auffällt statt erst nach dem Hochladen.
import { t, tRaw } from './l10n.js'

export const RECEIPT_MAX_SIZE = 20 * 1024 * 1024
export const RECEIPT_MAX_FILES = 10
export const RECEIPT_ACCEPT = 'application/pdf,image/jpeg,image/png,image/gif,image/webp'

const ALLOWED_TYPES = RECEIPT_ACCEPT.split(',')
const EXTENSION_TYPES = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' }

/** Dateityp der Datei; manche Browser (und Mobilgeräte) melden ihn leer, dann zählt die Endung. */
export function receiptType(file) {
	if (file.type) { return file.type }
	return EXTENSION_TYPES[String(file.name).split('.').pop().toLowerCase()] || ''
}

export function isImageReceipt(file) {
	return receiptType(file).startsWith('image/')
}

/** Dieselbe Auswahl nicht doppelt sammeln. */
export function receiptKey(file) {
	return `${file.name}|${file.size}|${file.lastModified}`
}

/**
 * Nimmt neue Dateien zur bisherigen Auswahl hinzu.
 *
 * @param {File[]} current die bisherige Auswahl
 * @param {File[]} incoming die neu gewählten Dateien
 * @return {{files: File[], errors: string[]}} die neue Auswahl und je abgelehnter Datei ein Satz
 */
export function addReceipts(current, incoming) {
	const files = [...current]
	const errors = []
	const known = new Set(files.map(receiptKey))
	for (const file of incoming) {
		if (known.has(receiptKey(file))) { continue }
		if (!ALLOWED_TYPES.includes(receiptType(file))) {
			errors.push(tRaw('„{name}“ ist weder PDF noch Bild.', { name: file.name }))
		} else if (file.size > RECEIPT_MAX_SIZE) {
			errors.push(tRaw('„{name}“ ist zu groß (höchstens 20 MB).', { name: file.name }))
		} else if (file.size === 0) {
			errors.push(tRaw('„{name}“ ist leer.', { name: file.name }))
		} else if (files.length >= RECEIPT_MAX_FILES) {
			errors.push(t('Mehr als {max} Belege je Antrag sind nicht möglich.', { max: RECEIPT_MAX_FILES }))
			break
		} else {
			files.push(file)
			known.add(receiptKey(file))
		}
	}
	return { files, errors }
}
