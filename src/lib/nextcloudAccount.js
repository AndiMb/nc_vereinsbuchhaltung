import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import api from '../api.js'

/**
 * Nextcloud-Konten legen wir nicht selbst an, sondern über die OCS-Schnittstelle von
 * Nextcloud (`POST /ocs/v2.php/cloud/users`, dieselbe wie die Benutzerverwaltung).
 * Damit gelten dort die Rechte der angemeldeten Person (Administrator, delegierter
 * Administrator, Gruppen-Administrator), die Passwortregeln der Instanz und die
 * Einladungsmail mit dem Link zum Passwort-Setzen – ohne dass die App diese Logik
 * nachbaut oder interne Klassen von Nextcloud ruft.
 */

/**
 * Legt das Konto an. Fehler von Nextcloud (Konto gibt es schon, Passwort zu schwach,
 * keine Berechtigung für die Gruppe …) tragen den Text von Nextcloud selbst und lassen
 * sich wie die der eigenen Schnittstelle mit `errMsg()` lesen.
 *
 * @param {{userId: string, password: string, displayName: string, email: string, groups: string[], quota: string, language: string}} account
 * @return {Promise<string>} die ID des angelegten Kontos
 */
export async function createNextcloudAccount(account) {
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
