import { confirmPassword, PasswordConfirmationCancelledError } from '@nextcloud/password-confirmation'

import '@nextcloud/password-confirmation/style.css'

/**
 * Lässt die angemeldete Person ihr Passwort bestätigen, falls Nextcloud das verlangt.
 *
 * Endpunkte mit `#[PasswordConfirmationRequired]` (alle Daten zurücksetzen, Rollen
 * vergeben und entziehen, Konto anlegen) lehnt Nextcloud sonst mit „Password
 * confirmation is required" ab. Wer sein Passwort in den letzten 30 Minuten bestätigt
 * hat, sieht keinen Dialog. Aufrufer rufen das unmittelbar vor der Anfrage – nach
 * einer eigenen Rückfrage und bevor sie etwas speichern –, damit ein Abbruch nichts
 * halb erledigt hinterlässt.
 *
 * @return {Promise<boolean>} false, wenn die Person den Dialog abgebrochen hat
 */
export async function withPasswordConfirmation() {
	try {
		await confirmPassword()
		return true
	} catch (e) {
		if (e instanceof PasswordConfirmationCancelledError) {
			return false
		}
		throw e
	}
}
