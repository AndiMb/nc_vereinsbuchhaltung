// Zahlungsfrequenz eines Mitgliedsbeitrags: Label und Monatszahl je Schlüssel.
// War identisch in MemberDialog.vue und MembersList.vue dupliziert, jetzt eine
// gemeinsame Stelle (wie formatMoney() etc. in format.js).
import { t } from './l10n.js'

/** Monate je Frequenz – auch die Hochrechnung aufs Jahr (12 / Monate) nutzt das. */
export const FREQUENCY_MONTHS = { monthly: 1, quarterly: 3, semiannual: 6, yearly: 12 }

export function frequencyLabels() {
	return {
		monthly: t('monatlich'),
		quarterly: t('vierteljährlich'),
		semiannual: t('halbjährlich'),
		yearly: t('jährlich'),
	}
}

export function frequencyLabel(f) {
	return frequencyLabels()[f] || f
}

/**
 * Turnus in Monaten (neues Modell: Zuweisung, Terminplan) als bekanntes
 * Frequenz-Label; ungewöhnliche Turnusse („alle 2 Monate“) ausgeschrieben.
 */
export function intervalLabel(months) {
	const key = Object.keys(FREQUENCY_MONTHS).find((k) => FREQUENCY_MONTHS[k] === months)
	return key ? frequencyLabel(key) : t('alle {monate} Monate', { monate: months })
}

/** Für <select>-Optionslisten: [{ value, label }, …]. */
export function frequencyOptions() {
	return Object.entries(frequencyLabels()).map(([value, label]) => ({ value, label }))
}
