import axios from '@nextcloud/axios'
import { confirmPassword, PasswordConfirmationCancelledError } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import api from '../api.js'

import '@nextcloud/password-confirmation/style.css'

/**
 * Nextcloud-Konten legen wir nicht selbst an, sondern über die OCS-Schnittstelle von
 * Nextcloud (`POST /ocs/v2.php/cloud/users`, dieselbe wie die Benutzerverwaltung).
 * Damit gelten dort die Rechte der angemeldeten Person (Administrator, delegierter
 * Administrator, Gruppen-Administrator), die Passwortregeln der Instanz und die
 * Einladungsmail mit dem Link zum Passwort-Setzen – ohne dass die App diese Logik
 * nachbaut oder interne Klassen von Nextcloud ruft.
 *
 * Wie in der Benutzerverwaltung verlangt Nextcloud dafür, dass die angemeldete Person
 * ihr Passwort kürzlich bestätigt hat (Issue #150) – sonst lehnt die Schnittstelle mit
 * „Password confirmation is required" ab. {@link confirmAccountCreation} fragt es ab,
 * wenn nötig; wer es gerade bestätigt hat, sieht keinen Dialog.
 */

/**
 * Lässt die angemeldete Person ihr Passwort bestätigen, falls Nextcloud das verlangt.
 * Der Aufnahme-Assistent ruft das, bevor er irgendetwas speichert – so hinterlässt ein
 * Abbruch keine halb angelegte Akte.
 *
 * @return {Promise<boolean>} false, wenn die Person den Dialog abgebrochen hat
 */
export async function confirmAccountCreation() {
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

/**
 * Legt das Konto an. Fehler von Nextcloud (Konto gibt es schon, Passwort zu schwach,
 * keine Berechtigung für die Gruppe …) tragen den Text von Nextcloud selbst und lassen
 * sich wie die der eigenen Schnittstelle mit `errMsg()` lesen.
 *
 * @param {{userId: string, password: string, displayName: string, email: string, groups: string[], quota: string, language: string}} account
 * @return {Promise<string>} die ID des angelegten Kontos
 * @throws {PasswordConfirmationCancelledError} wenn die Person den Passwort-Dialog abbricht
 */
export async function createNextcloudAccount(account) {
	await confirmPassword()
	const { data } = await axios.post(generateOcsUrl('/cloud/users'), {
		userid: account.userId,
		password: account.password,
		displayName: account.displayName,
		email: account.email,
		groups: account.groups,
		quota: account.quota,
		language: account.language,
	})
	return String(data?.ocs?.data?.id ?? account.userId)
}

/**
 * Legt das Konto an und verknüpft es mit der Akte. Scheitert nur die Verknüpfung, gibt es das
 * Konto bereits: der Fehler trägt dann dessen ID als `createdUid`, damit der Aufrufer „Konto da,
 * Verknüpfung fehlt“ von „Konto nicht angelegt“ unterscheiden kann.
 *
 * @param {number} memberId
 * @param {object} account siehe {@link createNextcloudAccount}
 * @return {Promise<string>} die ID des angelegten und verknüpften Kontos
 */
export async function createAndLinkAccount(memberId, account) {
	const uid = await createNextcloudAccount(account)
	try {
		await api.linkMember(memberId, uid)
	} catch (e) {
		e.createdUid = uid
		throw e
	}
	return uid
}
