// Zustandslose Helfer der Einstellungskarten des Beitrags-/SEPA-Moduls
// (SettingsSepa*.vue, Issue #101). Ohne Vue-/DOM-Bezug, damit sie ohne
// laufende Nextcloud-Instanz testbar sind.
//
// Die Pruefungen hier spiegeln die des Servers, damit ein Tippfehler sofort
// und mit dem Namen des Feldes gemeldet wird. Massgeblich bleibt der Server
// (SettingsController, DebitBatchController, SepaImportController): dessen
// Meldung zeigen die Karten an, wenn sie dennoch eintrifft.
import { t, tRaw } from './l10n.js'

/** Plausibilitaetsgrenzen der Tage-Einstellungen, wie ContributionCycleSettings/DunningSettings/MandateExpirySettings. */
export const DAYS_MIN = 1
export const DAYS_MAX = 365

/** Die Mandatsreferenz-Vorgabe des Servers, wenn das Praefix leer bleibt (MandateReferenceGenerator::DEFAULT_PREFIX). */
export const DEFAULT_REFERENCE_PREFIX = 'M'

/** Laenge des Mandatsreferenz-Praefixes: der Server schneidet bei 16 Zeichen ab (SettingsController::update()). */
export const REFERENCE_PREFIX_MAX_LENGTH = 16

/**
 * Die zwoelf Monate fuer die Auswahl des Beitragsjahr-Beginns.
 *
 * @param {string} language Sprache der Monatsnamen (de, en, de_DE, …) - die
 *                          Oberflaechensprache, nicht das Zahlenformat-Locale; ein
 *                          unbrauchbarer Wert faellt auf Deutsch (Quellsprache) zurueck
 * @return {Array<{value: number, label: string}>}
 */
export function monthOptions(language = 'de') {
	let formatter
	try {
		formatter = new Intl.DateTimeFormat(String(language).replace('_', '-'), { month: 'long' })
	} catch {
		formatter = new Intl.DateTimeFormat('de', { month: 'long' })
	}
	return Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: formatter.format(new Date(2000, i, 1)) }))
}

/**
 * Prueft eine Eingabe in Tagen. Gibt die Meldung mit dem Feldnamen zurueck,
 * oder null, wenn der Wert in Ordnung ist.
 *
 * @param {number|string|null} value Wert aus v-model.number ('' fuer ein leeres Feld)
 * @param {string} label Name des Feldes fuer die Meldung
 * @return {string|null}
 */
export function daysError(value, label) {
	const isBlank = value === '' || value === null || value === undefined
	if (isBlank || !Number.isInteger(Number(value))) {
		return tRaw('{label}: Bitte eine ganze Zahl von {min} bis {max} eingeben.', { label, min: DAYS_MIN, max: DAYS_MAX })
	}
	const days = Number(value)
	if (days < DAYS_MIN || days > DAYS_MAX) {
		return tRaw('{label}: Der Wert muss zwischen {min} und {max} Tagen liegen.', { label, min: DAYS_MIN, max: DAYS_MAX })
	}
	return null
}

/**
 * Die Konten, die fuer eine Konten-Einstellung in Frage kommen: aktive
 * Nicht-Geldkonten der verlangten Kontoart (siehe SepaSettingsAccountValidator).
 * Ein bereits gespeichertes Konto, das nicht mehr passt, bleibt mit einem
 * Vermerk in der Liste - sonst zeigte die Auswahl „kein Konto", obwohl eines
 * eingetragen ist.
 *
 * @param {Array} accounts alle Konten (useAccounts().state.accounts)
 * @param {'income'|'expense'} type verlangte Kontoart
 * @param {number|null} selectedId aktuell gespeichertes Konto
 * @return {Array<{id: number, label: string, unsuitable: boolean}>}
 */
export function accountOptions(accounts, type, selectedId) {
	const compare = (a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true })
	const fitting = accounts.filter((a) => a.type === type && !a.isBank && a.active).sort(compare)
	const options = fitting.map((a) => ({ id: a.id, label: `${a.number} · ${a.name}`, unsuitable: false }))
	const selected = selectedId ? accounts.find((a) => a.id === selectedId) : null
	if (selected && !fitting.includes(selected)) {
		options.push({ id: selected.id, label: `${selected.number} · ${selected.name}`, unsuitable: true })
	}
	return options
}

/**
 * Ob ein gespeichertes Konto in der Kontenliste fehlt (geloescht). Erst
 * auswerten, wenn die Konten geladen sind - sonst meldet jede Seite beim
 * Laden kurz ein „fehlendes" Konto.
 *
 * @param {Array} accounts alle Konten
 * @param {number|null} accountId gespeichertes Konto
 * @return {boolean}
 */
export function accountMissing(accounts, accountId) {
	return !!accountId && accounts.length > 0 && !accounts.some((a) => a.id === accountId)
}

/**
 * Die Meldung zu einem fehlgeschlagenen Speichern: die Fehlermeldung des
 * Servers (sie ist bereits uebersetzt und nennt das Feld), sonst der
 * HTTP-Status bzw. „Netzwerkfehler" - dieselbe Rueckfallstufe wie
 * SettingsApp.vue::saveSettings().
 *
 * @param {object} e Axios-Fehler
 * @return {string}
 */
export function saveErrorMessage(e) {
	const serverMessage = e?.response?.data?.message
	if (serverMessage) { return serverMessage }
	return t('Speichern fehlgeschlagen (HTTP {status})', { status: e?.response?.status ?? t('Netzwerkfehler') })
}

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * Datum „JJJJ-MM-TT“ um Tage verschieben (negativ = früher), ohne Zeitzonen- und
 * Sommerzeit-Versatz.
 *
 * @param {string} iso Ausgangsdatum
 * @param {number} days Tage
 * @return {string}
 */
export function shiftIsoDate(iso, days) {
	const [y, m, d] = iso.split('-').map(Number)
	return new Date(Date.UTC(y, m - 1, d) + days * DAY_MS).toISOString().slice(0, 10)
}

/**
 * Der nächste Monatserste nach dem Stichtag – das Beispieldatum für die Fristen
 * („Einzug am 1. des nächsten Monats“).
 *
 * @param {string} todayIso Stichtag „JJJJ-MM-TT“
 * @return {string}
 */
export function nextMonthStart(todayIso) {
	const [y, m] = todayIso.split('-').map(Number)
	return new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 10)
}

/**
 * Die drei Meilensteine eines Einzugs aus den Fristen (Tage vor dem Einzug),
 * als Beispiel in den Einstellungen: ab wann die Forderungen entstehen, wann
 * die Vorabinfo rausgeht, bis wann der Lauf freigegeben sein sollte.
 *
 * @param {string} dueIso Einzugstermin „JJJJ-MM-TT“
 * @param {{ warningLeadDays: number, prenotificationLeadDays: number, releaseLeadDays: number }} leads Fristen
 * @return {{ due: string, warning: string, prenotification: string, release: string }}
 */
export function leadDaysExample(dueIso, { warningLeadDays, prenotificationLeadDays, releaseLeadDays }) {
	return {
		due: dueIso,
		warning: shiftIsoDate(dueIso, -Number(warningLeadDays)),
		prenotification: shiftIsoDate(dueIso, -Number(prenotificationLeadDays)),
		release: shiftIsoDate(dueIso, -Number(releaseLeadDays)),
	}
}

/**
 * Hinweise zu einer unüblichen Reihenfolge der Fristen. Es sind Hinweise, keine
 * Sperren: der Server nimmt jede Zahl von 1 bis 365 an, und Vereine mit kurzer
 * vereinbarter Frist stellen die Werte bewusst eng.
 *
 * @param {{ warningLeadDays: number, prenotificationLeadDays: number, releaseLeadDays: number }} leads Fristen
 * @return {string[]}
 */
export function leadDaysOrderHints({ warningLeadDays, prenotificationLeadDays, releaseLeadDays }) {
	const hints = []
	if (Number(warningLeadDays) < Number(prenotificationLeadDays)) {
		hints.push(t('Das Vorwarnfenster sollte nicht kürzer sein als der Vorabinfo-Vorlauf: Sonst entstehen die Forderungen erst, wenn die Vorabinfo schon hätte rausgehen sollen.'))
	}
	if (Number(prenotificationLeadDays) < Number(releaseLeadDays)) {
		hints.push(t('Der Vorabinfo-Vorlauf sollte nicht kürzer sein als der Freigabe-Vorlauf: Sonst ist der Lauf schon freizugeben, bevor die Vorabinfo beim Mitglied ist.'))
	}
	return hints
}
