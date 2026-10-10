import { computed, reactive } from 'vue'
import api from '../api.js'
import { liveBatches } from '../lib/debitRun.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

/**
 * Zustand des Einzug-Unterreiters (Issue #102, Spec §3.5/§6): Zeitstrahl,
 * Vorschau des gewählten Termins (die „Geisterkarte“ – vor der Freigabe gibt
 * es keinen Lauf-Datensatz, siehe DebitRunQueryService) und die Läufe samt
 * Detail. Alles ist gelesen; geschrieben wird erst ab Freigabe (Folge-Ticket
 * #103), das nach jeder Aktion {@link reload} aufruft.
 *
 * Modul-Singleton wie die übrigen Composables: es gibt genau einen
 * Einzug-Unterreiter, und die Folge-Segmente (Forderungen, Bankabgleich)
 * teilen sich diesen Zustand, statt ihn über Props zu reichen.
 */
const state = reactive({
	// Antwort von GET /debit-batches/timeline (Termine, Meilensteine, nächster Termin)
	timeline: null,
	// Läufe, neueste Fälligkeit zuerst (Antwort von GET /debit-batches)
	runs: [],
	// Lauf-Detail inkl. Posten, erst beim Aufklappen geladen: { [id]: Lauf }
	runDetails: {},
	// Vorschau je Termin (Forderungen, Summe, Störfälle): { [dueDate]: Vorschau }
	previews: {},

	loaded: false,
	loading: false,
	error: null,

	// Angezeigtes Beitragsjahr (Anker-Kalenderjahr); null = das laufende
	year: null,
	selectedDate: null,
	expandedRunId: null,

	previewLoadingFor: null,
	previewError: null,
	runLoadingId: null,
	runError: null,
})

// Überholte Antworten verwerfen: wer schnell das Jahr wechselt oder den Termin
// wählt, soll nicht die Antwort einer früheren Anfrage angezeigt bekommen.
let overviewSeq = 0

/** Der Eintrag des gewählten Termins – aus dem angezeigten Jahr oder, sonst, der nächste Termin. */
const selectedEntry = computed(() => {
	const timeline = state.timeline
	if (!timeline || !state.selectedDate) { return null }
	return timeline.dates.find((d) => d.dueDate === state.selectedDate)
		|| (timeline.next?.dueDate === state.selectedDate ? timeline.next : null)
})

const selectedPreview = computed(() => (state.selectedDate ? state.previews[state.selectedDate] || null : null))

const expandedRun = computed(() => {
	const id = state.expandedRunId
	if (id === null) { return null }
	return state.runDetails[id] || state.runs.find((r) => r.id === id) || null
})

/** Ohne Auswahl (oder mit einem Termin, den es nicht mehr gibt): der nächste Termin, sonst im fremden Jahr der erste. */
function ensureSelection() {
	const timeline = state.timeline
	if (!timeline) { return }
	const known = state.selectedDate !== null
		&& (timeline.dates.some((d) => d.dueDate === state.selectedDate) || timeline.next?.dueDate === state.selectedDate)
	if (known) { return }
	state.selectedDate = timeline.year.isCurrent
		? (timeline.next?.dueDate ?? null)
		: (timeline.dates[0]?.dueDate ?? null)
}

async function loadOverview() {
	const seq = ++overviewSeq
	state.loading = true
	try {
		const [timeline, runs] = await Promise.all([api.debitTimeline(state.year), api.listDebitBatches()])
		if (seq !== overviewSeq) { return }
		state.timeline = timeline.data
		state.runs = runs.data
		state.error = null
		state.loaded = true
		ensureSelection()
	} catch (e) {
		if (seq !== overviewSeq) { return }
		state.error = errMsg(e, t('Der Einzug konnte nicht geladen werden.'))
	} finally {
		if (seq === overviewSeq) { state.loading = false }
	}
}

async function loadPreview(dueDate, { force = false } = {}) {
	if (!dueDate || (!force && state.previews[dueDate])) { return }
	state.previewLoadingFor = dueDate
	state.previewError = null
	try {
		const { data } = await api.debitPreview(dueDate)
		state.previews = { ...state.previews, [dueDate]: data }
	} catch (e) {
		if (state.previewLoadingFor === dueDate) { state.previewError = errMsg(e, t('Die Vorschau konnte nicht geladen werden.')) }
	} finally {
		if (state.previewLoadingFor === dueDate) { state.previewLoadingFor = null }
	}
}

async function loadRun(id, { force = false } = {}) {
	if (!force && state.runDetails[id]) { return }
	state.runLoadingId = id
	state.runError = null
	try {
		const { data } = await api.getDebitBatch(id)
		state.runDetails = { ...state.runDetails, [id]: data }
	} catch (e) {
		if (state.runLoadingId === id) { state.runError = errMsg(e, t('Der Lauf konnte nicht geladen werden.')) }
	} finally {
		if (state.runLoadingId === id) { state.runLoadingId = null }
	}
}

/** Erste Ladung und Wiederaufnahme: Übersicht, danach Vorschau und aufgeklappter Lauf frisch. */
async function reload() {
	await loadOverview()
	const jobs = []
	if (state.selectedDate) { jobs.push(loadPreview(state.selectedDate, { force: true })) }
	if (state.expandedRunId !== null) { jobs.push(loadRun(state.expandedRunId, { force: true })) }
	await Promise.all(jobs)
}

/** Ein Termin auf dem Zeitstrahl wurde gewählt: Vorschau laden und, falls es dort einen Lauf gibt, ihn aufklappen. */
async function selectDate(dueDate) {
	state.selectedDate = dueDate
	const entry = selectedEntry.value
	const target = liveBatches(entry)[0] || entry?.batches?.[0]
	const jobs = [loadPreview(dueDate)]
	if (target) {
		state.expandedRunId = target.id
		jobs.push(loadRun(target.id))
	}
	await Promise.all(jobs)
}

async function toggleRun(id) {
	if (state.expandedRunId === id) {
		state.expandedRunId = null
		return
	}
	state.expandedRunId = id
	await loadRun(id)
}

/** Beitragsjahr wechseln: `delta` = ±1, ohne Angabe zurück zum laufenden Jahr. */
async function shiftYear(delta = null) {
	const base = state.year ?? state.timeline?.year.anchorYear ?? null
	state.year = delta === null || base === null ? null : base + delta
	state.selectedDate = null
	await loadOverview()
	await loadPreview(state.selectedDate)
}

export function useDebitRuns() {
	return {
		state,
		selectedEntry,
		selectedPreview,
		expandedRun,
		reload,
		loadOverview,
		loadPreview,
		loadRun,
		selectDate,
		toggleRun,
		shiftYear,
	}
}
