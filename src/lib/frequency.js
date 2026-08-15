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

/** Für <select>-Optionslisten: [{ value, label }, …]. */
export function frequencyOptions() {
	return Object.entries(frequencyLabels()).map(([value, label]) => ({ value, label }))
}
