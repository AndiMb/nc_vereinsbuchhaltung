// Ergebnis von „Verschieben nach…“ im Eingangskorb (ReceiptMoveDialog.vue,
// AttachmentFolderPanel.vue): welche Meldung danach erscheint.

/**
 * Bei vielen Dateien geht das Verschieben in Paketen. Scheitert ein späteres
 * Paket, ist das frühere trotzdem verschoben – dann ist das ein Fehler mit
 * Teilergebnis, kein Erfolg.
 *
 * @param {{moved: number[], skipped: Array<{fileId: number, reason: string}>, error?: string}} result über alle Pakete
 * @return {{kind: 'success'|'warning'|'error', moved: number, skipped: number, error: string}}
 */
export function moveOutcome(result) {
	const error = result.error || ''
	const kind = error ? 'error' : (result.skipped.length ? 'warning' : 'success')
	return { kind, moved: result.moved.length, skipped: result.skipped.length, error }
}
