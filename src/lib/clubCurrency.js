import { loadState } from '@nextcloud/initial-state'
import { ref } from 'vue'

// Vereinswährung (Issue #202): Gegenstück zu lib/Service/ClubCurrency.php.
// Das Zahlenformat bleibt deutsch, nur das Währungszeichen wechselt.
//
// Den Code liefert der Server in `me.clubCurrency` (jedes Konto) und
// `settings.club_currency`. Bis eine Antwort da ist, gilt der Initial State
// der Seite, sonst Euro. Der Code liegt in einem ref: Was vor dem Eintreffen
// der Einstellungen gerendert wurde, rechnet Vue danach neu.

const APP_ID = 'vereinsbuchhaltung'
const DEFAULT_CODE = 'EUR'

// Code → Zeichen; dieselbe Liste wie ClubCurrency::SUPPORTED.
const SUPPORTED = {
	EUR: '€',
	CHF: 'CHF',
	GBP: '£',
	DKK: 'DKK',
	SEK: 'SEK',
	NOK: 'NOK',
	PLN: 'PLN',
	CZK: 'CZK',
}

/** Erkennt jedes Zeichen und jeden Code der Liste in einer Eingabe (ohne Wortgrenzen: Leerraum ist dort oft schon entfernt). */
export const CURRENCY_TOKEN_RE = new RegExp(['€', '£', ...Object.keys(SUPPORTED)].join('|'), 'i')

const configured = ref(null)

function normalize(code) {
	const upper = typeof code === 'string' ? code.trim().toUpperCase() : ''
	return Object.hasOwn(SUPPORTED, upper) ? upper : null
}

function codeFromPage() {
	for (const key of ['me', 'settings']) {
		try {
			const state = loadState(APP_ID, key, null)
			const code = normalize(state?.clubCurrency ?? state?.club_currency)
			if (code) { return code }
		} catch { /* Seite ohne Initial State (Einstellungsseite, Tests) */ }
	}
	return null
}

/**
 * Merkt sich die Vereinswährung aus einer Serverantwort. Leer oder unbekannt
 * bedeutet Euro – wie auf dem Server.
 *
 * @param {?string} code ISO-Code, z. B. 'CHF'
 */
export function setClubCurrency(code) {
	configured.value = normalize(code) ?? DEFAULT_CODE
}

/** @return {string} ISO-Code der Vereinswährung */
export function currencyCode() {
	if (configured.value === null) {
		configured.value = codeFromPage() ?? DEFAULT_CODE
	}
	return configured.value
}

/** @return {string} Zeichen für Beschriftungen, z. B. „€“ oder „CHF“ */
export function currencySymbol() {
	return SUPPORTED[currencyCode()]
}

/** @return {boolean} SEPA-Lastschrift und GiroCode gibt es nur in Euro. */
export function isEuro() {
	return currencyCode() === DEFAULT_CODE
}

/**
 * Entfernt Währungszeichen und -codes aus einer Eingabe.
 *
 * @param {string} s Eingabe
 * @return {string}
 */
export function stripCurrencyTokens(s) {
	return s.replace(new RegExp(CURRENCY_TOKEN_RE.source, 'gi'), '')
}

/**
 * Teil der Einstellungs-Nutzlast für die Vereinswährung: nur bei echter
 * Änderung. Sonst blockierte eine abgelehnte Umstellung (Mandate offen) das
 * Speichern jeder anderen Einstellungskarte.
 *
 * @param {string} selected Auswahl im Formular
 * @param {string} saved Stand des Servers
 * @return {{club_currency?: string}}
 */
export function currencySettingsPayload(selected, saved) {
	return selected && selected !== saved ? { club_currency: selected } : {}
}

/** Nur für Tests: vergisst die gesetzte Währung. */
export function resetClubCurrencyForTests() {
	configured.value = null
}
