import { showSuccess } from '@nextcloud/dialogs'
import { ref } from 'vue'
import { t } from '../lib/l10n.js'
import { saveErrorMessage } from '../lib/sepaSettings.js'

/**
 * Speichern-Ablauf einer Einstellungskarte (SettingsSepa*.vue): zuerst die
 * Eingaben pruefen, dann speichern; jeder Fehler steht als Meldung IN der
 * Karte (`error`), nicht nur als fluechtiger Toast - die Meldung nennt das
 * betroffene Feld und bleibt stehen, bis die naechste Eingabe gespeichert ist.
 * Erfolg meldet ein Toast, wie auf der ganzen Einstellungsseite.
 */
export function useSettingsCardSave() {
	const saving = ref(false)
	const error = ref('')

	/**
	 * @param {() => string|null} validate Eingabepruefung; liefert die Meldung oder null
	 * @param {() => Promise<void>} save Schreibt die Einstellungen; wirft bei einem Fehler
	 * @return {Promise<boolean>} ob gespeichert wurde
	 */
	async function run(validate, save) {
		error.value = validate() ?? ''
		if (error.value) { return false }
		saving.value = true
		try {
			await save()
			showSuccess(t('Einstellungen gespeichert.'))
			return true
		} catch (e) {
			error.value = saveErrorMessage(e)
			return false
		} finally {
			saving.value = false
		}
	}

	return { saving, error, run }
}
