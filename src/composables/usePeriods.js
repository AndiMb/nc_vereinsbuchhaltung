import { computed, reactive } from 'vue'
import api from '../api.js'

/**
 * Die Geschäftsjahre und das gerade gewählte.
 *
 * Löst useYears ab (Issue #8). Dort war ein Geschäftsjahr eine Zahl, die
 * zugleich Anfrageparameter, Schlüssel der Sperrliste, Beschriftung und – über
 * `jahr - 1` – der Vorjahresbezug war. Ein Geschäftsjahr, das nicht dem
 * Kalenderjahr entspricht, ließ sich damit nicht ausdrücken und ein Semester
 * schon gar nicht. Jetzt ist es ein Datensatz mit ID, Bezeichnung und Grenzen;
 * die ID geht an den Server, die Bezeichnung an die Oberfläche.
 */
const state = reactive({
	// Vom Server, absteigend nach Beginn (neuester Zeitraum zuerst)
	periods: [],
	// null bedeutet „alle Zeiträume"
	selectedPeriodId: null,
	// Wurde die Vorgabe schon einmal gesetzt? Getrennt von selectedPeriodId,
	// weil null dort auch die bewusste Wahl „alle Zeiträume" ist – die darf
	// ein späteres Nachladen (nach jeder Buchung, bei jedem Poll) nicht
	// stillschweigend wieder auf den laufenden Zeitraum zurückdrehen.
	initialised: false,
})

const periodsById = computed(() => {
	const byId = {}
	for (const p of state.periods) { byId[p.id] = p }
	return byId
})

const selectedPeriod = computed(() => (
	state.selectedPeriodId === null ? null : (periodsById.value[state.selectedPeriodId] ?? null)
))

/**
 * Der Zeitraum, in den ein Buchungsdatum fällt.
 *
 * Der Vergleich läuft über die ISO-Zeichenketten; bei JJJJ-MM-TT ist die
 * alphabetische Ordnung dieselbe wie die zeitliche. Genau darauf baut auch
 * der Server auf.
 */
function periodForDate(date) {
	if (!date) { return null }
	const d = String(date).slice(0, 10)
	return state.periods.find((p) => d >= p.startDate && d <= p.endDate) ?? null
}

/** Liegt das Datum in einem festgeschriebenen Zeitraum? */
function isDateClosed(date) {
	const period = periodForDate(date)
	return !!(period && period.closedAt)
}

/** Der Zeitraum davor – für Vorjahresvergleiche, die früher `jahr - 1` hießen. */
function previousOf(period) {
	if (!period) { return null }
	let best = null
	for (const p of state.periods) {
		if (p.startDate < period.startDate && (!best || p.startDate > best.startDate)) { best = p }
	}
	return best
}

/**
 * Der Zeitraum, der heute enthält – sonst der neueste, sonst null.
 *
 * Vorgabe ist der laufende, nicht der zuletzt angelegte: bei halbjährlicher
 * Buchführung ist im Mai das Sommersemester gemeint, auch wenn für das
 * kommende Wintersemester schon ein Planwert steht.
 *
 * @param {Array} list die Zeiträume, absteigend nach Beginn
 */
function defaultPeriodId(list) {
	if (!list.length) { return null }
	const today = new Date().toISOString().slice(0, 10)
	const current = list.find((p) => today >= p.startDate && today <= p.endDate)
	return (current ?? list[0]).id
}

/** Lädt die Zeiträume und setzt beim ersten Mal die Vorgabe. */
async function loadPeriods() {
	try {
		const { data } = await api.periods()
		state.periods = data
		if (!state.initialised && data.length) {
			state.initialised = true
			state.selectedPeriodId = defaultPeriodId(data)
		}
		// Nach einer Umstellung der Geschäftsjahr-Regel gibt es die bisher
		// gewählte ID nicht mehr. Ohne diese Korrektur liefe jede folgende
		// Anfrage in ein 404. Ersatz ist derselbe wie beim ersten Laden.
		if (state.selectedPeriodId !== null && !periodsById.value[state.selectedPeriodId]) {
			state.selectedPeriodId = defaultPeriodId(data)
		}
	} catch { /* Zeiträume optional */ }
}

export function usePeriods() {
	return {
		state,
		selectedPeriod,
		periodForDate,
		isDateClosed,
		previousOf,
		loadPeriods,
	}
}
