// Zustandslose Formatier- und Label-Helfer, aus App.vue ausgelagert (Schritt 2
// der Modularisierung). Reine Funktionen ohne Vue-/DOM-Bezug: sie werden in
// App.vue unveraendert als Komponenten-Methoden eingebunden (das Template ruft
// formatMoney(...) etc. wie bisher auf) und sind hier unabhaengig testbar.
import { getLocale } from '@nextcloud/l10n'
import { t } from './l10n.js'

// Einmal anlegen: ein neues Intl.NumberFormat je Aufruf war bei langen
// Buchungslisten der teuerste Teil des Zeilen-Renderings.
const EUR = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })

export function formatMoney(v) {
	return EUR.format(v || 0)
}

const DAY_FORMATS = new Map()

function dayFormat(locale) {
	if (!DAY_FORMATS.has(locale)) {
		const options = { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }
		let format
		try {
			format = new Intl.DateTimeFormat(locale, options)
		} catch {
			format = new Intl.DateTimeFormat('de-DE', options)
		}
		DAY_FORMATS.set(locale, format)
	}
	return DAY_FORMATS.get(locale)
}

/**
 * Ein Kalendertag (ISO „2026-01-31“) in der Schreibweise des Gebietsschemas der Person:
 * 31.01.2026 auf Deutsch, 31/01/2026 für en_GB. Ein Tag ist kein Zeitpunkt und wird
 * nicht in eine Zeitzone umgerechnet. Unlesbares bleibt roh stehen.
 */
export function formatDate(s) {
	if (!s) { return '' }
	const d = String(s).slice(0, 10)
	const m = d.match(/^(\d{4})-(\d{2})-(\d{2})$/)
	if (!m) { return d }
	const time = Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
	// 31. Februar o. ä. rollt in den Folgemonat: dann lieber roh anzeigen.
	if (new Date(time).getUTCDate() !== Number(m[3])) { return d }
	return dayFormat(userLocale()).format(time)
}

export function formatFileSize(bytes) {
	if (bytes < 1024) { return bytes + ' B' }
	if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(1) + ' KB' }
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

/**
 * Das Gebietsschema der Person (Nextcloud-Einstellung „Gebietsschema“, z. B. de_AT),
 * als BCP-47-Tag für Intl; ohne Nextcloud-Umgebung (Tests) Deutsch.
 */
function userLocale() {
	try {
		return String(getLocale() || 'de-DE').replace('_', '-')
	} catch {
		return 'de-DE'
	}
}

/**
 * Zeitstempel für die Anzeige im Gebietsschema der Person, ohne Sekunden. Angaben mit
 * Zeitzone (der Server schreibt ISO 8601 mit Offset) erscheinen in der Zeitzone des
 * Browsers; Angaben ohne Zeitzone gelten als dort geschrieben.
 */
export function formatDateTime(s) {
	if (!s) { return '' }
	const raw = String(s)
	if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
		// Nur ein Tag: kein Zeitpunkt, daher nicht in die Zeitzone umrechnen.
		return new Intl.DateTimeFormat(userLocale(), { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(raw))
	}
	const date = new Date(raw)
	if (Number.isNaN(date.getTime())) {
		return raw.replace('T', ' ').slice(0, 16)
	}
	try {
		return new Intl.DateTimeFormat(userLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(date)
	} catch {
		return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
	}
}

export function typeLabel(accountType) {
	return {
		income: t('Ertrag'),
		expense: t('Aufwand'),
		asset: t('Aktiv'),
		liability: t('Passiv'),
		equity: t('Eigenkapital'),
	}[accountType] || accountType
}

export function roleLabel(r) {
	return {
		verwalter: t('Verwalter'),
		buchhalter: t('Buchhalter'),
		revisor: t('Revisor'),
	}[r] || r
}

export function amountClass(v) {
	return v < 0 ? 'neg' : ''
}

// Gut ist mehr Einnahme oder weniger Ausgabe – für jede Größe, die als
// „Ist minus Plan“ oder „Plan minus Vergleich“ gerechnet ist.
export function trendClass(type, value) {
	if (!value) { return '' }
	const good = type === 'income' ? value > 0 : value < 0
	return good ? 'good' : 'bad'
}

export function budgetDiffClass(row) {
	return trendClass(row.type, row.diff)
}

// Betrag mit Vorzeichen, damit eine Veränderung nicht nur an der Farbe
// erkennbar ist: 100 → „+100,00 €“.
export function formatSigned(v) {
	return (v > 0 ? '+' : '') + formatMoney(v)
}

const PERCENT = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1, signDisplay: 'exceptZero' })

// Prozentwert mit Vorzeichen; null (kein Bezugswert) → „—“.
export function formatPercent(v) {
	return v === null || v === undefined ? '—' : PERCENT.format(v) + ' %'
}

export function errMsg(e, fallback) {
	// Die Schnittstellen der App antworten mit `message`, die OCS-Schnittstelle von Nextcloud (Konto anlegen) mit `ocs.meta.message`.
	const data = e && e.response && e.response.data
	return (data && (data.message || (data.ocs && data.ocs.meta && data.ocs.meta.message))) || fallback
}

// --- Betragsfelder -------------------------------------------------------
//
// <input type="number"> kann keine Tausendertrennzeichen anzeigen: laut
// HTML-Spezifikation muss sein Wert eine "valid floating-point number" sein,
// "20.000,00" verwirft der Browser also. Betragsfelder sind deshalb
// Textfelder (AmountInput.vue), die ihren Wert selbst formatieren und wieder
// einlesen - die drei Helfer dafuer stehen hier, damit sie ohne Vue testbar
// bleiben.

/** Rundet auf ganze Cent; alles andere kann das Backend ohnehin nicht speichern. */
export function roundCents(v) {
	return Math.round(Number(v) * 100) / 100
}

/**
 * Anzeigeform eines Betragsfelds, solange es nicht bearbeitet wird:
 * "20.000,00 €" - dieselbe Schreibweise wie in den Tabellenspalten daneben.
 * Leere Werte bleiben leer, damit der Platzhalter sichtbar bleibt.
 *
 * @param {number|string|null} v Betrag
 * @param {boolean} withCurrency Waehrungszeichen anhaengen
 * @return {string} formatierter Betrag oder ''
 */
export function formatAmountInput(v, withCurrency = true) {
	if (v === '' || v === null || v === undefined) { return '' }
	const n = Number(v)
	if (!Number.isFinite(n)) { return '' }
	return withCurrency
		? formatMoney(n)
		: new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)
}

/**
 * Bearbeitungsform eines Betragsfelds: der nackte Wert mit Dezimalkomma,
 * ohne Gruppierung und ohne Waehrungszeichen. Wer tippt, soll nicht gegen
 * eine mitlaufende Maske arbeiten muessen.
 *
 * @param {number|string|null} v Betrag
 * @return {string} z. B. '20000' oder '20000,5'
 */
export function amountInputRaw(v) {
	if (v === '' || v === null || v === undefined) { return '' }
	const n = Number(v)
	if (!Number.isFinite(n)) { return '' }
	return String(n).replace('.', ',')
}

/**
 * Liest, was Nutzer tatsaechlich eintippen: '20000', '20000,5', '20000.5',
 * '20.000,00', '20 000', mit oder ohne '€'.
 *
 * Punkt und Komma sind zusammen eindeutig (das rechte der beiden trennt die
 * Nachkommastellen). Allein gelesen folgt der Parser der deutschen
 * Schreibweise: Komma trennt Nachkommastellen ('1,5' = 1,50 €), ein Punkt vor
 * genau drei Ziffern gruppiert ('1.500' = 1.500,00 €). Nur so wird aus dem
 * Screenshot von Issue #34 wieder der gemeinte Betrag.
 *
 * @param {string|number|null} s Eingabe
 * @return {number|null} Betrag in Euro, oder null wenn unlesbar
 */
export function parseAmountInput(s) {
	if (typeof s === 'number') { return Number.isFinite(s) ? s : null }
	let str = String(s ?? '')
		.replace(/[\s\u00a0\u202f]/g, '')
		.replace(/€|EUR/gi, '')
	if (!str) { return null }

	let sign = 1
	if (/^[-−–—]/.test(str)) { sign = -1; str = str.slice(1) } else if (str.startsWith('+')) { str = str.slice(1) }
	if (!/^[\d.,]+$/.test(str)) { return null }

	const lastComma = str.lastIndexOf(',')
	const lastDot = str.lastIndexOf('.')
	let digits

	if (lastComma >= 0 && lastDot >= 0) {
		// Beide vorhanden: das rechte Zeichen trennt die Nachkommastellen.
		const cut = Math.max(lastComma, lastDot)
		const group = cut === lastComma ? '.' : ','
		digits = str.slice(0, cut).split(group).join('') + '.' + str.slice(cut + 1)
	} else if (lastComma >= 0) {
		// Nur Kommas: eines trennt die Nachkommastellen, mehrere gruppieren.
		digits = str.indexOf(',') === lastComma
			? str.replace(',', '.')
			: (/^\d{1,3}(,\d{3})+$/.test(str) ? str.split(',').join('') : null)
	} else if (lastDot >= 0) {
		// Nur Punkte: gruppieren, wenn hinter jedem genau drei Ziffern stehen.
		digits = /^\d{1,3}(\.\d{3})+$/.test(str) ? str.split('.').join('') : str
	} else {
		digits = str
	}

	if (digits === null || !/^\d*\.?\d*$/.test(digits) || !/\d/.test(digits)) { return null }
	const n = Number(digits)
	return Number.isFinite(n) ? sign * n : null
}
