/**
 * Die Seitenleiste „Aus Ordner wählen“ im Buchungsdialog merkt sich je
 * Browser, ob sie zuletzt offen war (#230). Wer Belege immer aus dem
 * Wächter-Ordner zuordnet, muss sie so nicht bei jeder Buchung neu
 * aufklappen; wer ihn nicht nutzt, bekommt keinen breiteren Dialog.
 */

export const FOLDER_PANEL_KEY = 'vbh_booking_folder_panel'

/** Ob die Leiste zuletzt offen war. Ohne gemerkte Wahl: zu. */
export function readFolderPanelOpen() {
	try { return localStorage.getItem(FOLDER_PANEL_KEY) === '1' } catch { return false }
}

/** @param {boolean} open */
export function writeFolderPanelOpen(open) {
	try { localStorage.setItem(FOLDER_PANEL_KEY, open ? '1' : '0') } catch { /* voll/gesperrt – dann eben ohne */ }
}

/**
 * Ob die Leiste beim Öffnen des Dialogs von selbst aufgeht: nur dort, wo es
 * den Knopf gibt, und nur am Desktop – mobil ersetzt die Leiste das
 * Formular, sie würde die Buchungsmaske verdecken.
 *
 * @param {{remembered: boolean, watchMode: boolean, canWrite: boolean, bookingLocked: boolean, isMobile: boolean, forced?: boolean}} state Zustand beim Öffnen
 * @return {boolean}
 */
export function opensFolderPanel({ remembered, watchMode, canWrite, bookingLocked, isMobile, forced = false }) {
	if (!watchMode || !canWrite || bookingLocked) { return false }
	// Ein ausdrücklicher Klick (Belegvorschlag in der Liste) öffnet die Leiste
	// auch mobil: dort ist sie gewollt, nur das Selbstöffnen aus der gemerkten
	// Wahl würde das Formular verdecken.
	if (forced) { return true }
	return remembered && !isMobile
}
