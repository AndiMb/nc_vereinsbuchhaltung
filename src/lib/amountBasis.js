// Betragsangabe einer Beitragsgruppe (Issue #270): „pro Monat“ (Vorgabe) oder
// „pro Jahr“. Bei Jahresgruppen stehen Untergrenze, Standardbeitrag und der
// Beitrag der Zuweisung als Jahresbetrag in eigenen Feldern; die Monatsfelder
// tragen dort nur den gerundeten Zwölftel-Wert. Diese Helfer wählen überall
// dasselbe Feld, damit kein Dialog versehentlich den Hilfswert zeigt oder einen
// Jahresbetrag als Monatsbetrag schickt.
import { t } from './l10n.js'
import { isAnnual } from './prorata.js'

/**
 * Gibt die Gruppe ihre Beträge pro Jahr an?
 *
 * @param {{amountBasis?: string}|null|undefined} group
 * @return {boolean}
 */
export function isAnnualGroup(group) {
	return !!group && group.amountBasis === 'year'
}

/**
 * Untergrenze der Gruppe in ihrer Betragsangabe, in Cent.
 *
 * @param {object} group
 * @return {number}
 */
export function groupMinCents(group) {
	return isAnnualGroup(group) ? group.minAnnualAmountCents : group.minMonthlyAmountCents
}

/**
 * Standardbeitrag der Gruppe in ihrer Betragsangabe, in der Währungseinheit.
 *
 * @param {object} group
 * @return {number}
 */
export function groupDefaultAmount(group) {
	return isAnnualGroup(group) ? group.defaultAnnualAmount : group.defaultMonthlyAmount
}

/**
 * Beitrag der Zuweisung in der Betragsangabe ihrer Gruppe, in der Währungseinheit.
 *
 * @param {object} assignment
 * @return {number}
 */
export function assignmentAmount(assignment) {
	return isAnnual(assignment) ? assignment.annualAmount : assignment.monthlyAmount
}

/**
 * Wie assignmentAmount(), in Cent.
 *
 * @param {object} assignment
 * @return {number}
 */
export function assignmentAmountCents(assignment) {
	return isAnnual(assignment) ? assignment.annualAmountCents : assignment.monthlyAmountCents
}

/**
 * Individuelle Untergrenze der Zuweisung in der Betragsangabe ihrer Gruppe, in Cent; null ohne eine.
 *
 * @param {object} assignment
 * @return {number|null}
 */
export function assignmentMinOverrideCents(assignment) {
	return isAnnual(assignment) ? assignment.minAnnualAmountOverrideCents : assignment.minMonthlyAmountOverrideCents
}

/**
 * Der Betrag unter dem Namen, den der Server für diese Betragsangabe erwartet.
 * Er lehnt den jeweils anderen ab – ein Jahresbetrag, als Monatsbetrag gelesen,
 * läge um den Faktor 12 daneben.
 *
 * @param {boolean} annual
 * @param {number|string} amount
 * @return {object}
 */
export function amountPayload(annual, amount) {
	return annual ? { annualAmount: amount } : { monthlyAmount: amount }
}

/**
 * Die individuelle Untergrenze unter dem Namen ihrer Betragsangabe.
 *
 * @param {boolean} annual
 * @param {number|null} amount
 * @return {object}
 */
export function minOverridePayload(annual, amount) {
	return annual ? { minAnnualAmountOverride: amount } : { minMonthlyAmountOverride: amount }
}

/**
 * Beschriftung des Betragsfelds: „Monatsbeitrag (€)“ oder „Jahresbeitrag (€)“.
 *
 * @param {boolean} annual
 * @param {string} currency Währungszeichen
 * @return {string}
 */
export function amountFieldLabel(annual, currency) {
	return annual ? t('Jahresbeitrag ({currency})', { currency }) : t('Monatsbeitrag ({currency})', { currency })
}

/**
 * Formatierter Betrag mit Einheit: „8,00 € / Monat“, „50,00 € / Jahr“.
 *
 * @param {string} formatted schon formatierter Betrag
 * @param {boolean} annual
 * @return {string}
 */
export function amountWithUnit(formatted, annual) {
	return annual ? t('{amount} / Jahr', { amount: formatted }) : t('{amount} / Monat', { amount: formatted })
}
