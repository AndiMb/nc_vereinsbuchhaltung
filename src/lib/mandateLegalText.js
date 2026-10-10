// Zustandslose Helfer des Rechtstext-Editors (SettingsMandateLegalText.vue,
// Spec §3.11, Issue #101).
//
// Der Server trennt Pflichtblock und Rahmen innerhalb EINES Textkoerpers durch
// einen internen Marker (MandateLegalTextService::RAHMEN_MARKER). Dieser Marker
// darf nie als Text sichtbar werden (Fehler in #67) - der Server liefert beide
// Teile deshalb getrennt und ohne Marker, und hier wird nur mit diesen
// getrennten Teilen gerechnet. Die Vorschau setzt sie so zusammen, wie
// MandateFormRenderer::renderLegalText() es fuer das Mandatsformular tut.
import { t } from './l10n.js'

/** Der Platzhalter fuer den Vereinsnamen (MandateLegalTextVersion::render()). */
export const CREDITOR_PLACEHOLDER = '{{creditor_name}}'

/** Obergrenze des Rahmens, wie MandateLegalTextService::MAX_RAHMEN_LENGTH. */
export const MAX_RAHMEN_LENGTH = 5000

/** Fuer interne Marker reservierte Zeichenfolge, wie MandateLegalTextService::RESERVED_MARKER_PREFIX. */
export const RESERVED_MARKER_PREFIX = '<!-- vbh:'

/**
 * Vergleichs- und Speicherform eines Rahmens: einheitliche Zeilenenden,
 * Raender getrimmt (wie MandateLegalTextService::normalizeRahmen()).
 *
 * @param {string} text eingegebener Rahmen
 * @return {string}
 */
export function normalizeRahmen(text) {
	return String(text ?? '').replace(/\r\n?/g, '\n').trim()
}

/**
 * Prueft den eingegebenen Rahmen. Gibt die Meldung zurueck, oder null, wenn
 * er so gespeichert werden darf.
 *
 * @param {string} text eingegebener Rahmen
 * @return {string|null}
 */
export function rahmenError(text) {
	const rahmen = normalizeRahmen(text)
	if (rahmen.includes(RESERVED_MARKER_PREFIX)) {
		// Die Zeichenfolge wird ausserhalb von t() angehaengt: t() bereinigt das
		// Ergebnis mit DOMPurify und schneidet alles ab "<!--" ab, als Variable
		// eingesetzt wuerde sie zu "&lt;!--" maskiert (und Vue maskiert ein
		// zweites Mal).
		return `${t('Der Text enthält eine für die App reservierte Zeichenfolge, bitte entfernen Sie sie:')} ${RESERVED_MARKER_PREFIX}`
	}
	const length = [...rahmen].length
	if (length > MAX_RAHMEN_LENGTH) {
		return t('Der Rahmentext ist zu lang: {length} Zeichen, erlaubt sind höchstens {max}.', { length, max: MAX_RAHMEN_LENGTH })
	}
	return null
}

/**
 * Der Text so, wie ihn ein Mitglied auf dem Mandat sieht: Pflichtblock und
 * Rahmen als getrennte Absaetze, der Platzhalter durch den Vereinsnamen
 * ersetzt. Ohne Vereinsnamen gilt dieselbe Ersatzformulierung wie im
 * Mandatsformular („den Verein").
 *
 * @param {string} pflichtblock geschuetzter Pflichtblock
 * @param {string} rahmen editierbarer Rahmen
 * @param {string} clubName Vereinsname aus den Einstellungen (darf leer sein)
 * @return {string[]} Absaetze; innerhalb eines Absatzes bleiben Zeilenumbrueche stehen
 */
export function legalTextParagraphs(pflichtblock, rahmen, clubName) {
	// „den Verein" steht mitten in einem deutschen Satz des Rechtstexts („Ich ermächtige …"):
	// bewusst nicht übersetzt. Der Mandats-Rechtstext gilt nur auf Deutsch (Spec §3.11,
	// MandateFormRenderer::renderLegalText() setzt dieselbe Formulierung ein).
	const name = String(clubName ?? '').trim() || 'den Verein'
	return [pflichtblock, normalizeRahmen(rahmen)]
		.join('\n\n')
		.split(CREDITOR_PLACEHOLDER)
		.join(name)
		.split(/\n{2,}/)
		.map((paragraph) => paragraph.trim())
		.filter((paragraph) => paragraph !== '')
}

/**
 * Wer eine Fassung angelegt hat, in Klartext (MandateLegalTextVersion::CREATED_BY_*).
 *
 * @param {string} createdBy 'verwalter' oder 'system'
 * @return {string}
 */
export function authorLabel(createdBy) {
	return createdBy === 'system'
		? t('durch App-Update')
		: t('durch Verwalter')
}
