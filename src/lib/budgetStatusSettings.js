// Die Ampel-Schwellen der visuellen Gliederungsansicht (Einstellung
// `budget_status_thresholds`, SettingsBudgetStatus.vue). Die Pruefung spiegelt
// die des Servers (BudgetStatusSettings::set()); massgeblich bleibt der Server.
import { t } from './l10n.js'

/** Die Vorgaben in Prozent, wie BudgetStatus::DEFAULTS. */
export const THRESHOLD_DEFAULTS = Object.freeze({ expWarn: 80, expCrit: 120, incGood: 100, incWarn: 80, resTol: 20 })

export const THRESHOLD_MIN = 0
export const THRESHOLD_MAX = 999

/**
 * Prueft die fuenf Schwellen. Gibt die Meldung zurueck, oder null, wenn alles
 * in Ordnung ist.
 *
 * @param {Record<string, number|string>} values Werte aus v-model.number ('' fuer ein leeres Feld)
 * @return {string|null}
 */
export function thresholdsError(values) {
	for (const key of Object.keys(THRESHOLD_DEFAULTS)) {
		const value = values?.[key]
		const blank = value === '' || value === null || value === undefined
		if (blank || !Number.isInteger(Number(value)) || Number(value) < THRESHOLD_MIN || Number(value) > THRESHOLD_MAX) {
			return t('Jede Ampel-Schwelle muss eine ganze Zahl zwischen {min} und {max} sein.', { min: THRESHOLD_MIN, max: THRESHOLD_MAX })
		}
	}
	if (Number(values.expWarn) >= Number(values.expCrit)) {
		return t('Bei den Ausgaben muss die Schwelle für Orange unter der für Rot liegen.')
	}
	if (Number(values.incWarn) >= Number(values.incGood)) {
		return t('Bei den Einnahmen muss die Schwelle für Rot unter der für Grün liegen.')
	}
	return null
}
