/**
 * Hilfen für „Mitglieder zur erneuten Bestätigung anschreiben“ auf der
 * Rechtstext-Einstellungsseite (MandateLegalTextController::reconfirmation()).
 *
 * Der Server verschickt je Aufruf höchstens {@link RECONFIRM_BATCH_SIZE} Mails
 * (jede ist ein SMTP-Roundtrip); die Oberfläche teilt die Liste deshalb in
 * Stücke und führt die Teilergebnisse zusammen.
 */

/** Muss zu MandateLegalTextController::MAX_RECONFIRMATION_BATCH passen. */
export const RECONFIRM_BATCH_SIZE = 25

/**
 * @param {number[]} ids
 * @param {number} [size]
 * @return {number[][]}
 */
export function chunkIds(ids, size = RECONFIRM_BATCH_SIZE) {
	const chunks = []
	for (let i = 0; i < ids.length; i += size) {
		chunks.push(ids.slice(i, i + size))
	}
	return chunks
}

/**
 * Welche Mandate angeschrieben werden: die ohne gültigen Link, auf Wunsch auch
 * die, deren früherer Link noch gilt (Erinnerung – der alte Link wird dabei ungültig).
 *
 * @param {{readyIds: number[], pendingIds: number[]}} overview
 * @param {boolean} includePending
 * @return {number[]}
 */
export function targetIds(overview, includePending) {
	return includePending ? [...overview.readyIds, ...overview.pendingIds] : [...overview.readyIds]
}

/**
 * @typedef {{sent: number, alreadyCurrent: number, skipped: number, noEmail: string[], failed: {name: string, reason: string}[]}} ReconfirmResult
 */

/** @return {ReconfirmResult} */
export function emptyResult() {
	return { sent: 0, alreadyCurrent: 0, skipped: 0, noEmail: [], failed: [] }
}

/**
 * @param {ReconfirmResult} total
 * @param {ReconfirmResult} part
 * @return {ReconfirmResult}
 */
export function mergeResult(total, part) {
	return {
		sent: total.sent + part.sent,
		alreadyCurrent: total.alreadyCurrent + part.alreadyCurrent,
		skipped: total.skipped + part.skipped,
		noEmail: [...total.noEmail, ...part.noEmail],
		failed: [...total.failed, ...part.failed],
	}
}

/**
 * Anzahl Mandate, die noch nicht die aktuelle Fassung bestätigt haben und sich
 * per Mail erreichen lassen (mit und ohne bereits gültigen Link).
 *
 * @param {{readyIds: number[], pendingIds: number[]}} overview
 * @return {number}
 */
export function reachableCount(overview) {
	return overview.readyIds.length + overview.pendingIds.length
}
