import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

/**
 * Zustand des Segments „Forderungen“ im Einzug-Unterreiter (Issue #104): alle
 * Forderungen mit abgeleitetem Zustand, Mahnstand, Einzug, Rücklastschrift und
 * Störfällen (Antwort von GET /claims/overview, siehe ClaimOverviewService).
 * Rein gelesen; geschrieben wird über die Forderungs-Aktionen in ClaimDetail,
 * die danach {@link load} aufrufen.
 *
 * Eigener Zustand neben useClaims(): das liefert die schlanke Liste des
 * Beitragsgruppen-Reiters (GET /claims) und bleibt dafür unverändert.
 * Modul-Singleton wie die übrigen Composables.
 */
const state = reactive({
	claims: [],
	// Stichtag und Mahnabstand kommen vom Server: dieselbe Uhr wie Cron und Mahnlauf
	today: null,
	dunningIntervalDays: null,

	loaded: false,
	loading: false,
	error: null,
})

// Überholte Antworten verwerfen: wer nach einer Aktion sofort neu lädt, soll nicht
// den Stand einer früheren, langsameren Anfrage sehen.
let seq = 0

async function load() {
	const mine = ++seq
	state.loading = true
	try {
		const { data } = await api.claimOverview()
		if (mine !== seq) { return }
		state.claims = data.claims
		state.today = data.today
		state.dunningIntervalDays = data.dunningIntervalDays
		state.error = null
		state.loaded = true
	} catch (e) {
		if (mine !== seq) { return }
		state.error = errMsg(e, t('Die Forderungen konnten nicht geladen werden.'))
	} finally {
		if (mine === seq) { state.loading = false }
	}
}

export function useClaimOverview() {
	return { state, load }
}
