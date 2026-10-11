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
	return {
		userId: suggestedUserId(source),
		displayName: displayNameOf(source),
		email: String(source.email ?? ''),
		password: '',
		groups: [],
		quota: DEFAULT_CHOICE,
		language: DEFAULT_CHOICE,
	}
}

export function displayNameOf(source) {
	if (source.memberType === 'organisation') {
		return String(source.organizationName ?? '').trim()
	}
	return [source.firstName, source.lastName].map((part) => String(part ?? '').trim()).filter(Boolean).join(' ')
}

export function suggestedUserId(source) {
	if (source.memberType === 'organisation') {
		return suggestUserId(source.organizationName)
	}
	return suggestUserId(source.firstName, source.lastName)
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
	if (account.password === '' && account.email.trim() === '') {
		missing.push('email')
	} else if (options.requireEmail && account.email.trim() === '') {
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
