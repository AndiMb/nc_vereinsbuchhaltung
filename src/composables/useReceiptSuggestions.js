import { getCurrentInstance, onBeforeUnmount, ref, watch } from 'vue'
import api from '../api.js'

const DEBOUNCE_MS = 600
// Der Server liest je Anfrage nur wenige Dateien; solange das voranging,
// fragt der Dialog nach kurzer Pause noch einmal.
const FOLLOW_UP_MS = 1500

/**
 * Nur die Antwort der zuletzt gestarteten Anfrage zählt: ältere (und deren
 * Fehler) lösen mit `{ stale: true }` auf.
 *
 * @return {(factory: () => Promise<unknown>) => Promise<unknown>} run
 */
export function createLatestOnly() {
	let latest = 0
	return async function run(factory) {
		const mine = ++latest
		try {
			const result = await factory()
			return mine === latest ? result : { stale: true }
		} catch (e) {
			if (mine !== latest) { return { stale: true } }
			throw e
		}
	}
}

/**
 * Belegvorschläge für das Buchungsformular (#222). Ruft beim Einschalten ab
 * und danach entprellt bei Änderungen an Datum, Betrag, Buchungstext und
 * Belegnummer oder der Ausschlussliste. Der Endpunkt hebt den gemeinsamen
 * Änderungsstand nicht an, die Abrufe lösen also keinen Abgleich bei anderen
 * Sitzungen aus.
 *
 * @param {object} options Optionen
 * @param {() => object} options.form Formularstand (date, amount in Euro, description, documentRef, id)
 * @param {() => number[]} options.excludeFileIds Dateien der Warteliste
 * @param {() => boolean} options.enabled ob überhaupt abgerufen wird
 * @return {object} suggestions, pending, loading, refresh
 */
export function useReceiptSuggestions({ form, excludeFileIds, enabled }) {
	const suggestions = ref([])
	const pending = ref(0)
	const loading = ref(false)
	const run = createLatestOnly()
	let timer = null
	let followUps = 0
	let disposed = false

	function clearTimer() {
		if (timer !== null) { clearTimeout(timer); timer = null }
	}

	function payload() {
		const f = form() || {}
		const amount = Number(f.amount)
		return {
			journalId: f.id || undefined,
			date: f.date || '',
			amountCents: Number.isFinite(amount) ? Math.round(amount * 100) : 0,
			description: f.description || '',
			documentRef: f.documentRef || '',
			excludeFileIds: [...excludeFileIds()],
		}
	}

	async function refresh() {
		clearTimer()
		if (disposed || !enabled()) { return }
		loading.value = true
		try {
			const result = await run(() => api.receiptSuggest(payload()))
			if (result.stale || disposed) { return }
			const before = pending.value
			suggestions.value = result.data.suggestions || []
			pending.value = result.data.pending || 0
			loading.value = false
			// Noch ungelesene Dateien: weiterfragen, aber nur solange es voranging.
			if (pending.value > 0 && (followUps === 0 || pending.value < before)) {
				followUps++
				timer = setTimeout(refresh, FOLLOW_UP_MS)
			}
		} catch {
			// Vorschläge sind nur eine Hilfe: ein Fehler lässt die bisherige Liste stehen.
			loading.value = false
		}
	}

	function schedule() {
		clearTimer()
		followUps = 0
		timer = setTimeout(refresh, DEBOUNCE_MS)
	}

	watch(enabled, (on) => {
		clearTimer()
		if (on) {
			followUps = 0
			refresh()
		} else {
			// Ausgeschaltet: laufende Antworten verwerfen, Liste leeren.
			run(() => Promise.resolve())
			suggestions.value = []
			pending.value = 0
			loading.value = false
		}
	}, { immediate: true })

	watch(
		() => {
			const f = form() || {}
			return [f.date, f.amount, f.description, f.documentRef, excludeFileIds().join(',')]
		},
		() => { if (enabled()) { schedule() } },
	)

	/** Aufräumen beim Abbau: keine Antwort wird mehr übernommen, kein Timer neu gesetzt. */
	function dispose() {
		disposed = true
		clearTimer()
	}

	if (getCurrentInstance()) { onBeforeUnmount(dispose) }

	return { suggestions, pending, loading, refresh, dispose }
}
