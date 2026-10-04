import { reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

/**
 * Zustand und Aktionen des Segments „Bankabgleich“ im Einzug-Unterreiter
 * (Issue #105, Spec §3.10/§5): die Arbeitsliste der Bankumsätze, die auf ein
 * Urteil warten (Antwort von GET /bank-reconciliation, siehe
 * BankReconciliationService), und was Buchhalter damit tun – Urteil je
 * Detail-Zeile, Verbuchen, Zahlungseingang bestätigen oder ablehnen.
 *
 * Modul-Singleton wie die übrigen Composables.
 *
 * **Urteile ändern die Liste örtlich, nicht durch Neuladen:** ein Sammler hat
 * hunderte Zeilen, und jedes Urteil neu zu laden hieße, jedes Mal alle
 * Vorschläge neu zu berechnen. Die Antwort des Servers (die Zeile mit ihrem
 * neuen Urteil) wird deshalb in die vorhandene Zeile übernommen; Fortschritt
 * und Zustand des Umsatzes rechnet die Oberfläche aus den Zeilen
 * (lib/bankReconciliation.js, summarize()). Verbuchen und der Zahlungseingang
 * ändern dagegen die Menge der wartenden Umsätze und laden neu.
 *
 * Alle Aktionen werfen den Fehler des Servers weiter; Meldung und Erfolgstext
 * gehören der aufrufenden Komponente (sie weiß, ob ein Dialog offen ist, in
 * dem der Fehler stehen soll). Nach einem Fehler beim Verbuchen oder
 * Bestätigen wird trotzdem neu geladen: meist hat jemand anderes den Umsatz
 * inzwischen gebucht, und die Oberfläche soll den wirklichen Stand zeigen.
 */
const state = reactive({
	// Einzugsgutschriften und Rücklastschriften mit Detail-Zeilen und Vorschlägen
	items: [],
	// Zahlungseingänge mit Vorschlägen
	incoming: [],

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
		const { data } = await api.bankReconciliation()
		if (mine !== seq) { return }
		state.items = data.items
		state.incoming = data.incoming
		state.error = null
		state.loaded = true
	} catch (e) {
		if (mine !== seq) { return }
		state.error = errMsg(e, t('Der Bankabgleich konnte nicht geladen werden.'))
	} finally {
		if (mine === seq) { state.loading = false }
	}
}

/**
 * Urteil über eine Detail-Zeile (Spec §5 „Einzelurteil je Detail-Zeile“):
 * `assign` (mit dem gewählten Kandidaten), `reject` oder `unmatched`. Die
 * Zeile übernimmt das Urteil des Servers; ein Urteil lässt sich jederzeit
 * ändern, solange der Umsatz nicht gebucht ist.
 *
 * @param {object} detail Zeile aus der Arbeitsliste (das reaktive Objekt selbst)
 * @param {'assign'|'reject'|'unmatched'} action
 * @param {object|null} candidate der gewählte Kandidat bei `assign`
 */
async function decide(detail, action, candidate = null) {
	let response
	if (action === 'assign') {
		response = await api.assignSepaDetail(detail.id, candidate.debitItemId)
	} else if (action === 'reject') {
		response = await api.rejectSepaDetail(detail.id)
	} else {
		response = await api.markSepaDetailUnmatched(detail.id)
	}
	const saved = response.data
	detail.status = saved.status
	detail.debitItemId = saved.debitItemId
	detail.decidedAt = saved.decidedAt
	detail.assignedItem = action === 'assign' ? { ...candidate } : null
}

/** Der Server ist die Wahrheit: nach jeder Aktion, die die Menge der wartenden Umsätze ändert (oder ändern wollte), neu laden. */
async function writeThenReload(action) {
	let result
	try {
		result = await action()
	} catch (e) {
		await load()
		throw e
	}
	await load()
	return result
}

/** Verbucht einen Umsatz, dessen Detail-Zeilen alle beurteilt sind. Liefert `{ settled, returned }`. */
function settle(bankTxId) {
	return writeThenReload(async () => (await api.settleSepaImport(bankTxId)).data)
}

/** Was die Verbuchung buchen würde, mit Hindernissen als Daten (BankReconciliationService::settlementPreview()). */
async function loadPreview(bankTxId) {
	return (await api.bankReconciliationPreview(bankTxId)).data
}

/** Bestätigt „diese Gutschrift passt auf diese Forderung“: bucht und schließt die Forderung ab. */
function confirmIncoming(bankTxId, openItemId) {
	return writeThenReload(async () => (await api.confirmIncomingPayment(bankTxId, openItemId)).data)
}

/** Lehnt den Vorschlag ab; dasselbe Paar wird nicht wieder vorgeschlagen. */
function rejectIncoming(bankTxId, openItemId) {
	return writeThenReload(async () => (await api.rejectIncomingPayment(bankTxId, openItemId)).data)
}

export function useBankReconciliation() {
	return { state, load, decide, settle, loadPreview, confirmIncoming, rejectIncoming }
}
