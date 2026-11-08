// Beschriftung von Konten in Listen und Auswahlfeldern (#201).
//
// Ein Konto kann einen Kurznamen tragen; der steht dann überall dort, wo der
// Platz knapp ist (Auswahllisten, Journalzeilen). Der volle Name bleibt in
// Kontenplan, Kontodetail und Berichten. Gesucht wird in beiden.

/**
 * @param {{name: string, shortName?: string|null}} acc Konto
 * @return {string} Kurzname, sonst Name
 */
export function accountDisplayName(acc) {
	const short = String(acc.shortName || '').trim()
	return short || acc.name
}

/**
 * @param {{number: string, name: string, shortName?: string|null}} acc Konto
 * @return {string} „4300 Jugend-Events“
 */
export function accountLabel(acc) {
	return `${acc.number} ${accountDisplayName(acc)}`
}

/**
 * Option für NcSelect; `fullName` hält den langen Namen für die Suche.
 *
 * @param {{id: number, number: string, name: string, shortName?: string|null}} acc Konto
 * @return {{id: number, label: string, number: string, fullName: string}}
 */
export function accountOption(acc) {
	return { id: acc.id, label: accountLabel(acc), number: acc.number, fullName: acc.name }
}

/**
 * Suchfilter der Konto-Auswahlfelder: Ziffern suchen am Anfang der
 * Kontonummer, Text in Beschriftung und vollem Namen. Überschriften
 * ($isDisabled) sind nie ein Treffer.
 *
 * @param {object} option Option, siehe accountOption()
 * @param {string} label angezeigte Beschriftung
 * @param {string} search Suchtext
 * @return {boolean}
 */
export function accountFilterBy(option, label, search) {
	const s = String(search || '').trim().toLowerCase()
	if (!s) { return true }
	if (option && option.$isDisabled) { return false }
	if (/^[\d\s]+$/.test(s)) {
		const digits = s.replace(/\s+/g, '')
		const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
		return num.startsWith(digits)
	}
	return `${label || ''} ${(option && option.fullName) || ''}`.toLowerCase().includes(s)
}
