// Sätze der Mitglieder-Akte, in die Nutzerdaten gehören (MemberDialog.vue).
//
// Ein Nextcloud-Benutzername darf Zeichen wie „'" und „&" enthalten; als
// Variable in t() würde @nextcloud/l10n sie als HTML escapen und die Anzeige
// verfälschen (siehe tRaw() in l10n.js). Deshalb steht der Satz hier als
// Funktion – prüfbar ohne Komponententest-Umgebung (memberAccount.test.js).
import { tRaw } from './l10n.js'

/** Hinweis neben dem Knopf „Verknüpfung lösen": mit welchem Nextcloud-Konto das Mitglied verknüpft ist. */
export function linkedAccountText(uid) {
	return tRaw('Verknüpft mit „{uid}".', { uid })
}

/** Dasselbe für eine Verknüpfung, die das Mitglied noch nicht bestätigt hat (#295). */
export function pendingAccountText(uid) {
	return tRaw('Verknüpfung mit „{uid}" wartet auf Bestätigung durch das Mitglied.', { uid })
}

/**
 * Rückfrage vor dem Verknüpfen (#295): nennt die Benutzer-ID, denn Anzeigename und Mailadresse
 * setzt jedes Konto selbst – nur die ID unterscheidet zwei Konten sicher.
 */
export function linkConfirmText(memberName, account) {
	return tRaw('{name} mit dem Nextcloud-Konto „{konto}" (Benutzer-ID {uid}, {email}) verknüpfen? Das Mitglied bekommt dann einen Bestätigungslink an seine Mailadresse; erst wenn es ihn mit diesem Konto bestätigt, gilt die Verknüpfung.', {
		name: memberName,
		konto: account.displayName,
		uid: account.uid,
		email: account.email || '',
	})
}

/**
 * Meldung nach dem Verknüpfen (#295): die Verknüpfung ist angelegt, aber erst ausstehend. Scheiterte
 * nur der Versand des Bestätigungslinks, steht der Grund in `linkMailError`.
 *
 * @param {object} data Antwort von `POST /members/{id}/link`
 * @return {{ok: boolean, text: string}}
 */
export function linkMailMessage(data) {
	if (data && data.linkMailError) {
		return { ok: false, text: tRaw('Verknüpfung angelegt, aber der Bestätigungslink ging nicht raus: {reason}', { reason: data.linkMailError }) }
	}
	if (data && data.ncLinkConfirmed) {
		return { ok: true, text: tRaw('Verknüpft.', {}) }
	}
	return { ok: true, text: tRaw('Verknüpfung angelegt. Der Bestätigungslink ist an {email} unterwegs.', { email: (data && data.email) || '' }) }
}
