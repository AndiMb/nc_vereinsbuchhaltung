import { showError } from '@nextcloud/dialogs'
import { computed, reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

/**
 * Kostenstellen an der Buchungszeile (Issue #7): geteilter Zustand für
 * Buchungsdialog, Aufteilen-Dialog, Buchungsliste und Bericht.
 *
 * `enabled` spiegelt den Schalter der Einstellungen. Solange er aus ist,
 * zeigt keine Komponente etwas von der Funktion, und die Liste wird gar
 * nicht erst geladen.
 *
 * Nicht zu verwechseln mit useCostCenters.js: das sind die
 * Auswertungsgruppen, die ganze Konten zusammenfassen.
 */
const state = reactive({
	enabled: false,
	// Erst wenn die Einstellungen gelesen sind, ist `enabled` verlaesslich -
	// bis dahin darf niemand aus "aus" Schluesse ziehen (Deep-Link).
	known: false,
	bookingCostCenters: [],
})

const bookingCostCentersById = computed(() => {
	const map = {}
	for (const cc of state.bookingCostCenters) { map[cc.id] = cc }
	return map
})

/** Nur diese stehen für eine neue Zuordnung zur Auswahl. */
const activeBookingCostCenters = computed(() => state.bookingCostCenters.filter((cc) => cc.active))

/** @return {Promise<Array|null>} die geladenen Kostenstellen, oder null bei Fehler (bereits als Toast gemeldet) */
async function loadBookingCostCenters() {
	try {
		const { data } = await api.bookingCostCenters()
		state.bookingCostCenters = data
		return data
	} catch (e) {
		showError(errMsg(e, t('Kostenstellen konnten nicht geladen werden')))
		return null
	}
}

/**
 * Übernimmt den Schalter aus den Einstellungen und lädt die Liste, sobald
 * die Funktion eingeschaltet ist.
 *
 * @param {boolean} enabled Wert von `cost_centers_enabled`
 */
function setBookingCostCentersEnabled(enabled) {
	state.enabled = !!enabled
	state.known = true
	if (state.enabled) {
		loadBookingCostCenters()
	} else {
		state.bookingCostCenters = []
	}
}

export function useBookingCostCenters() {
	return { state, bookingCostCentersById, activeBookingCostCenters, loadBookingCostCenters, setBookingCostCentersEnabled }
}
