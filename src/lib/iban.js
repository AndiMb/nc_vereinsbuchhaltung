// IBAN-Helfer für die Eingabe: Schreibweise in Vierergruppen und die Prüfziffer
// nach ISO 13616 (Modulo 97). Der Server prüft nur die Form
// (IbanValidator); die Prüfziffer fängt hier Tippfehler schon beim Eingeben ab.

/** Großbuchstaben, ohne Leerzeichen und Trennstriche. */
export function normalizeIban(value) {
	return String(value || '').replace(/[\s-]+/g, '').toUpperCase()
}

/** „DE89370400440532013000“ → „DE89 3704 0044 0532 0130 00“. */
export function formatIban(value) {
	return normalizeIban(value).replace(/(.{4})/g, '$1 ').trim()
}

/**
 * Ob Form und Prüfziffer stimmen. Eine leere Eingabe ist weder gültig noch ungültig
 * (siehe ibanState()).
 */
export function isValidIban(value) {
	const iban = normalizeIban(value)
	if (!/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(iban)) { return false }
	const rearranged = iban.slice(4) + iban.slice(0, 4)
	let remainder = 0
	for (const char of rearranged) {
		// Buchstaben zählen als Zahlen ab 10 (A = 10 … Z = 35)
		const digits = /\d/.test(char) ? char : String(char.charCodeAt(0) - 55)
		for (const digit of digits) { remainder = (remainder * 10 + Number(digit)) % 97 }
	}
	return remainder === 1
}

/**
 * Zustand für die Anzeige am Feld: 'empty', 'incomplete' (noch zu kurz für eine
 * Prüfung), 'valid' oder 'invalid'. „Zu kurz“ ist keine Fehlermeldung wert,
 * solange jemand noch tippt.
 */
export function ibanState(value) {
	const iban = normalizeIban(value)
	if (iban === '') { return 'empty' }
	if (iban.length < 15) { return 'incomplete' }
	return isValidIban(iban) ? 'valid' : 'invalid'
}
