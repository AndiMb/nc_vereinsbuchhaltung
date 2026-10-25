/**
 * Hängt Dateien des Wächter-Ordners an mehrere Buchungen (Mehrfachauswahl im
 * Journal).
 *
 * Was schon verknüpft ist, wird übergangen: jede Datei aus dem Eingangskorb
 * trägt ihre Buchungen mit (journalIds). So legt auch ein zweiter Versuch mit
 * derselben Auswahl nichts doppelt an. Je Paar ein eigener Aufruf der
 * vorhandenen Schnittstelle, nacheinander – ein abgelehntes reißt die übrigen
 * nicht mit, und der Server prüft jedes wie eine einzelne Verknüpfung
 * (abgeschlossenes Geschäftsjahr, Datei noch im Ordner).
 *
 * @param {(journalId: number, fileId: number) => Promise<unknown>} linkAttachment verknüpft eine Datei mit einer Buchung (api.linkAttachment)
 * @param {Array<{fileId: number, journalIds?: number[]}>} files gewählte Dateien
 * @param {number[]} journalIds markierte Buchungen
 * @return {Promise<{linked: number[], failed: number[], error: unknown}>} Buchungen mit neuer bzw. fehlgeschlagener Verknüpfung, dazu der letzte Fehler
 */
export async function linkFilesToBookings(linkAttachment, files, journalIds) {
	const linked = new Set()
	const failed = new Set()
	let error = null
	for (const journalId of journalIds) {
		for (const file of files) {
			if ((file.journalIds || []).includes(journalId)) { continue }
			try {
				await linkAttachment(journalId, file.fileId)
				linked.add(journalId)
			} catch (e) {
				failed.add(journalId)
				error = e
			}
		}
	}
	return { linked: [...linked], failed: [...failed], error }
}

/**
 * Die IDs von der Anker-Zeile bis zur angeklickten, beide eingeschlossen, in
 * der Reihenfolge der Liste – für Umschalt-Klick in einer Auswahlspalte. Steht
 * der Anker nicht (mehr) in der Liste, nur die angeklickte Zeile.
 *
 * @param {Array<{id: number}>} rows die auswählbaren Zeilen, wie angezeigt
 * @param {number|null} anchorId zuletzt angeklickte Zeile
 * @param {number} id angeklickte Zeile
 * @return {number[]}
 */
export function rangeIds(rows, anchorId, id) {
	const from = rows.findIndex((r) => r.id === anchorId)
	const to = rows.findIndex((r) => r.id === id)
	if (from < 0 || to < 0) { return [id] }
	return rows.slice(Math.min(from, to), Math.max(from, to) + 1).map((r) => r.id)
}
