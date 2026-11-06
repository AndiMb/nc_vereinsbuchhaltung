import { loadState } from '@nextcloud/initial-state'

// „Heute“ des Vereins (Issue #263): Der Server rechnet fachliche Tagesdaten in
// der Vereinszone (Server-Zeitzone, sonst Europe/Berlin, siehe ClubClock.php).
// Die Oberfläche muss denselben Tag nehmen – `new Date().toISOString()` wäre
// UTC und zwischen 00:00 und 02:00 Uhr deutscher Zeit noch „gestern“.
//
// Den Zonennamen liefert der Server in `me.clubTimeZone` (jedes Konto) und
// `settings.club_time_zone`. Bis eine Antwort da ist, gilt der Initial State
// der Seite, sonst Europe/Berlin.
//
// Gespeicherte Zeitstempel (`created_at` usw.) bleiben UTC und laufen nicht
// hierüber.

const FALLBACK_ZONE = 'Europe/Berlin'
const APP_ID = 'vereinsbuchhaltung'

let configuredZone = null

function zoneFromPage() {
	for (const key of ['me', 'settings']) {
		try {
			const state = loadState(APP_ID, key, null)
			const name = state?.clubTimeZone ?? state?.club_time_zone
			if (name) { return name }
		} catch { /* Seite ohne Initial State (Einstellungsseite, Tests) */ }
	}
	return null
}

function isValidZone(name) {
	try {
		new Intl.DateTimeFormat('en-CA', { timeZone: name })
		return true
	} catch {
		return false
	}
}

/**
 * Merkt sich die Vereinszone aus einer Serverantwort. Leere oder unbekannte
 * Namen werden ignoriert.
 *
 * @param {?string} name IANA-Name, z. B. 'Europe/Berlin'
 */
export function setClubTimeZone(name) {
	if (typeof name === 'string' && name !== '' && isValidZone(name)) {
		configuredZone = name
	}
}

/** @return {string} die bekannte Vereinszone, sonst Europe/Berlin */
export function clubTimeZone() {
	if (configuredZone === null) {
		const fromPage = zoneFromPage()
		if (fromPage && isValidZone(fromPage)) { configuredZone = fromPage }
	}
	return configuredZone ?? FALLBACK_ZONE
}

/**
 * Der Kalendertag des Vereins als JJJJ-MM-TT.
 *
 * @param {Date} [now] Zeitpunkt (Standard: jetzt)
 * @param {string} [timeZone] IANA-Zone (Standard: die Vereinszone)
 * @return {string}
 */
export function clubToday(now = new Date(), timeZone = clubTimeZone()) {
	let format
	try {
		format = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' })
	} catch {
		format = new Intl.DateTimeFormat('en-CA', { timeZone: FALLBACK_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' })
	}
	// en-CA formatiert als JJJJ-MM-TT; über die Teile, damit keine Lokale-Eigenheit stört.
	const parts = Object.fromEntries(format.formatToParts(now).map((p) => [p.type, p.value]))
	return `${parts.year}-${parts.month}-${parts.day}`
}

/** Nur für Tests: vergisst die gesetzte Zone. */
export function resetClubTimeZoneForTests() {
	configuredZone = null
}
