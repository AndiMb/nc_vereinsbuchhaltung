import axios from '@nextcloud/axios'
import { computed, reactive } from 'vue'
import api from '../api.js'
import { actionCount, hintCount, sortTasks } from '../lib/tasks.js'

/**
 * Aufgaben/Störfälle (Spec §7, Issue #99): geteilter Zustand für das
 * Aufgaben-Flyout in der Kopfzeile (TasksFlyout.vue).
 *
 * Die Liste ist eine abgeleitete Abfrage des Servers, keine Entity: eine
 * Aufgabe verschwindet von selbst, sobald ihre Ursache behoben ist - es gibt
 * kein Quittieren. Die Oberfläche muss also nur oft genug neu fragen, siehe
 * startAutoRefresh().
 *
 * Fehler werden bewusst nicht als Toast gemeldet: das Flyout zeigt sie selbst
 * (`error`), und ein Toast alle 60 Sekunden bei wackliger Verbindung wäre
 * Lärm. Eine frühere, erfolgreich geladene Liste bleibt dabei stehen.
 */
const state = reactive({
	tasks: [],
	/** Mindestens einmal erfolgreich geladen - vorher gibt es nichts zu zeigen. */
	loaded: false,
	loading: false,
	/** Der letzte Ladeversuch ist fehlgeschlagen. */
	error: false,
})

const actionTotal = computed(() => actionCount(state.tasks))
const hintTotal = computed(() => hintCount(state.tasks))

// Überholte Antworten verwerfen: kommen zwei Abfragen kurz hintereinander
// zurück (Öffnen des Flyouts + Poll), gilt die zuletzt gestartete - wie
// journalSeq in useJournal.js.
let loadSeq = 0

async function loadTasks() {
	const seq = ++loadSeq
	state.loading = true
	try {
		const { data } = await api.listTasks()
		if (seq !== loadSeq) { return }
		state.tasks = sortTasks(Array.isArray(data) ? data : [])
		state.loaded = true
		state.error = false
	} catch {
		if (seq !== loadSeq) { return }
		state.error = true
	} finally {
		if (seq === loadSeq) { state.loading = false }
	}
}

// Abstand des Hintergrund-Abgleichs und Wartezeit nach einer eigenen
// Schreibaktion (damit mehrere Schreibzugriffe in Folge nur eine Abfrage
// auslösen).
const POLL_INTERVAL = 60000
const WRITE_DEBOUNCE = 1000

let stopRefresh = null

/**
 * Hält die Liste aktuell: beim Fokussieren des Fensters, nach jeder eigenen
 * Schreibaktion der App (ein behobener Störfall soll sofort aus dem Badge
 * verschwinden, nicht erst beim nächsten Poll) und sonst im Minutentakt, solange
 * der Tab sichtbar ist. Der Aufgabenstand hängt von der Uhrzeit ab (Fristen
 * laufen ab), der Änderungsstand-Abgleich der App (useSync.js) erfasst das
 * nicht.
 *
 * Mehrfache Aufrufe sind harmlos - es läuft immer höchstens ein Abgleich.
 *
 * @return {() => void} beendet den Abgleich wieder
 */
function startAutoRefresh() {
	if (stopRefresh) { return stopRefresh }

	let writeTimer = null
	const scheduleReload = () => {
		clearTimeout(writeTimer)
		writeTimer = setTimeout(loadTasks, WRITE_DEBOUNCE)
	}

	// Dasselbe Erkennungsmerkmal wie der Interceptor in api.js (lastWriteTs):
	// jede erfolgreiche Schreibanfrage an die App.
	const interceptorId = axios.interceptors.response.use((response) => {
		const method = ((response.config && response.config.method) || 'get').toLowerCase()
		const requestUrl = (response.config && response.config.url) || ''
		if (method !== 'get' && method !== 'head' && requestUrl.includes('/apps/vereinsbuchhaltung/')) {
			scheduleReload()
		}
		return response
	})

	const onFocus = () => loadTasks()
	window.addEventListener('focus', onFocus)

	const pollTimer = setInterval(() => {
		if (!document.hidden) { loadTasks() }
	}, POLL_INTERVAL)

	loadTasks()

	stopRefresh = () => {
		axios.interceptors.response.eject(interceptorId)
		window.removeEventListener('focus', onFocus)
		clearInterval(pollTimer)
		clearTimeout(writeTimer)
		stopRefresh = null
	}
	return stopRefresh
}

export function useTasks() {
	return { state, actionTotal, hintTotal, loadTasks, startAutoRefresh }
}
