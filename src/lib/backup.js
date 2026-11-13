/**
 * Kleine Entscheidungen der Oberfläche für „Sicherung und Umzug“ (Issue #111).
 * Der Server prüft alles noch einmal – hier geht es darum, dass ein Knopf
 * gar nicht erst anbietet, was scheitern wird.
 */

/** Bestätigungswort für „Bestand vollständig leeren“, solange kein Vereinsname eingetragen ist (wie BackupController::WIPE_WORD). */
export const WIPE_WORD = 'LÖSCHEN'

/** Reihenfolge, in der fehlende Dateien genannt werden. */
const FILE_KINDS = ['attachments', 'reimbursements', 'invoices', 'mandates']

/**
 * Was am Passwort einer neuen Sicherung noch nicht stimmt.
 *
 * @param {string} password das Passwort
 * @param {string} repeat die Wiederholung
 * @param {number} minLength Mindestlänge in Zeichen (vom Server)
 * @return {''|'short'|'mismatch'}
 */
export function passwordProblem(password, repeat, minLength = 10) {
	// Zeichen, nicht UTF-16-Einheiten – der Server zählt mit mb_strlen().
	if ([...String(password ?? '')].length < minLength) {
		return 'short'
	}
	return password === repeat ? '' : 'mismatch'
}

/**
 * Warum sich eine geprüfte Sicherung hier nicht einspielen lässt.
 *
 * @param {{compatible: boolean, targetEmpty: boolean}|null} inspection Antwort von /backup/inspect
 * @return {''|'unchecked'|'version'|'notEmpty'}
 */
export function importBlocker(inspection) {
	if (!inspection) {
		return 'unchecked'
	}
	if (!inspection.compatible) {
		return 'version'
	}
	return inspection.targetEmpty ? '' : 'notEmpty'
}

/**
 * Was zur Bestätigung des Leerens einzutippen ist.
 *
 * @param {string|null} clubName der eingetragene Vereinsname
 * @return {string}
 */
export function wipeWord(clubName) {
	const name = String(clubName ?? '').trim()
	return name !== '' ? name : WIPE_WORD
}

/**
 * Fehlende Dateien je Art gezählt.
 *
 * @param {Array<{kind: string, id: number}>|undefined} missing Liste vom Server
 * @return {Array<{kind: string, count: number}>}
 */
export function missingByKind(missing) {
	const list = Array.isArray(missing) ? missing : []
	return FILE_KINDS
		.map((kind) => ({ kind, count: list.filter((m) => m.kind === kind).length }))
		.filter((entry) => entry.count > 0)
}
