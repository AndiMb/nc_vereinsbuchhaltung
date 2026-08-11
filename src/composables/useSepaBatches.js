import { reactive } from 'vue'
import { showError } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * SEPA-Sammeleinzüge: geteilter Zustand für Vorschau (fällige offene Posten
 * mit Mandat) und bereits erzeugte Einzüge.
 *
 * Die Vorschau hängt am geplanten Fälligkeitstag: nur bis dahin fällige Posten
 * kommen mit. Welcher Tag das ist, bestimmt das Backend, solange der Nutzer
 * nichts gewählt hat - so schlagen Vorschlagstermin und Vorankündigungsfrist
 * nicht auseinander.
 */
const state = reactive({
	sepaPreview: [],
	sepaBatches: [],
	// Vom Backend vorgeschlagener bzw. zuletzt abgefragter Fälligkeitstag.
	sepaExecutionDate: '',
	// Zeilen je Einzug, erst beim Aufklappen geladen: { [batchId]: [...] }
	sepaBatchItems: {},
})

async function loadSepaPreview(executionDate) {
	try {
		const { data } = await api.previewSepaExport(executionDate)
		state.sepaPreview = data.rows
		state.sepaExecutionDate = data.executionDate
		return data
	} catch (e) {
		showError(errMsg(e, 'Vorschau konnte nicht geladen werden'))
		return null
	}
}

async function loadSepaBatches() {
	try {
		const { data } = await api.listSepaBatches()
		state.sepaBatches = data
		return data
	} catch (e) {
		showError(errMsg(e, 'SEPA-Einzüge konnten nicht geladen werden'))
		return null
	}
}

async function loadSepaBatchItems(batchId) {
	try {
		const { data } = await api.listSepaBatchItems(batchId)
		// Vue 2 sieht neue Objektschluessel nur ueber eine Neuzuweisung.
		state.sepaBatchItems = { ...state.sepaBatchItems, [batchId]: data }
		return data
	} catch (e) {
		showError(errMsg(e, 'Zeilen des Einzugs konnten nicht geladen werden'))
		return null
	}
}

export function useSepaBatches() {
	return { state, loadSepaPreview, loadSepaBatches, loadSepaBatchItems }
}
