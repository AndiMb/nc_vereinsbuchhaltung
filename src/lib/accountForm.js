import { suggestUserId } from './userId.js'

/**
 * Das Formular „Nextcloud-Konto anlegen“ (Aufnahme-Dialog und Akte teilen es):
 * Vorbelegung aus der Akte, Pflichtangaben und der Zuschnitt auf das, was Nextcloud
 * erwartet. Die Auswahlen und Rechte kommen vom Server (NextcloudAccountOptionsService).
 */

/** Sentinel der beiden Auswahlfelder „Speicherplatz“ und „Sprache“ für „Standard der Instanz“. */
export const DEFAULT_CHOICE = 'default'

/**
 * @param {{memberType?: string, firstName?: string, lastName?: string, organizationName?: string, email?: string}} source
 *        Stammdaten (Formular der Aufnahme oder Akte)
 */
export function emptyAccount(source = {}) {
	const names = source.memberType === 'organisation' ? [source.organizationName] : [source.firstName, source.lastName]
	return {
		userId: suggestUserId(...names),
		displayName: names.map((part) => String(part ?? '').trim()).filter(Boolean).join(' '),
		email: String(source.email ?? ''),
		password: '',
		groups: [],
		quota: DEFAULT_CHOICE,
		language: DEFAULT_CHOICE,
	}
}

/**
 * Ob die E-Mail-Adresse ausgefüllt sein muss: ohne Passwort geht die Einladung dorthin, und manche
 * Instanzen verlangen sie immer (`newUser.requireEmail`). Eine Regel für Beschriftung und Prüfung.
 */
export function isEmailRequired(account, options) {
	return options.requireEmail || account.password === ''
}

/**
 * Was noch fehlt, bevor Nextcloud das Konto anlegen kann – dieselben Regeln wie die Benutzerverwaltung:
 * ohne Passwort braucht es die E-Mail-Adresse (dorthin geht die Einladung), dazu je nach Instanz
 * immer; ein Gruppen-Administrator muss eine seiner Gruppen angeben.
 *
 * @return {string[]} die fehlenden Angaben, leer = vollständig
 */
export function missingAccountFields(account, options) {
	const missing = []
	if (!options.generateUserId && account.userId.trim() === '') {
		missing.push('userId')
	}
	if (isEmailRequired(account, options) && account.email.trim() === '') {
		missing.push('email')
	}
	if (options.groupRequired && account.groups.length === 0) {
		missing.push('groups')
	}
	return missing
}

/** Die Felder in der Form, die {@link createNextcloudAccount} erwartet. */
export function accountPayload(account) {
	return {
		userId: account.userId.trim(),
		password: account.password,
		displayName: account.displayName.trim(),
		email: account.email.trim(),
		groups: account.groups,
		quota: account.quota === DEFAULT_CHOICE ? '' : account.quota,
		language: account.language === DEFAULT_CHOICE ? '' : account.language,
	}
}
