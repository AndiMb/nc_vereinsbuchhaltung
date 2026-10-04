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
