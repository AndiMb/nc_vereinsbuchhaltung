/**
 * Teilt laufende Abfragen eines Laders (Muster aus useAttachmentInbox.js).
 *
 * - Ohne `force` teilen sich parallele Aufrufe die laufende Abfrage.
 * - Mit `force` (nach jedem Schreibzugriff) startet immer eine neue Abfrage:
 *   eine, die vor dem Schreiben begann, koennte noch den alten Stand liefern.
 * - Nur die zuletzt GESTARTETE Abfrage gilt als aktuell. `fetchFn` bekommt
 *   dafuer `isCurrent()` und darf den Zustand nur schreiben, solange es true
 *   liefert; eine aeltere Antwort ueberschreibt so nie eine neuere. Aufrufer
 *   einer veralteten Abfrage bekommen das Ergebnis der aktuellen.
 *
 * @param {(isCurrent: () => boolean) => Promise<unknown>} fetchFn
 * @return {(opts?: { force?: boolean }) => Promise<unknown>}
 */
export function createSharedLoader(fetchFn) {
	let latest = null // laufende Abfrage (null, sobald fertig)
	let newest = null // zuletzt gestartete, auch nach dem Ende
	let seq = 0

	return function load({ force = false } = {}) {
		if (latest && !force) { return latest }
		const mine = ++seq
		let started
		try { started = Promise.resolve(fetchFn(() => mine === seq)) } catch (e) { started = Promise.reject(e) }
		const run = started
			.then((result) => (mine === seq ? result : newest))
		const tracked = run.finally(() => {
			if (latest === tracked) { latest = null }
		})
		latest = tracked
		newest = tracked
		return tracked
	}
}
