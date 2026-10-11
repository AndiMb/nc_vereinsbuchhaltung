import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

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
 * keine Berechtigung für die Gruppe …) kommen mit dem Text von Nextcloud selbst zurück
 * und lassen sich wie die Fehler der eigenen Schnittstelle mit `errMsg()` lesen.
 *
 * @param {{userId: string, password: string, displayName: string, email: string, groups: string[], quota: string, language: string}} account
 * @return {Promise<string>} die ID des angelegten Kontos
 */
export async function createNextcloudAccount(account) {
	try {
		const { data } = await axios.post(generateOcsUrl('/cloud/users'), {
			userid: account.userId,
			password: account.password || '',
			displayName: account.displayName || '',
			email: account.email || '',
			groups: account.groups || [],
			quota: account.quota || '',
			language: account.language || '',
		})
		return String(data?.ocs?.data?.id ?? account.userId)
	} catch (e) {
		const message = e?.response?.data?.ocs?.meta?.message
		if (message) {
			const error = new Error(message)
			error.response = { data: { message } }
			throw error
		}
		throw e
	}
}
