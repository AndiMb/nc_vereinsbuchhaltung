import { showError } from '@nextcloud/dialogs'
import { computed, reactive, shallowRef } from 'vue'
import api from '../api.js'
import { accountLabel as labelOf } from '../lib/accountLabel.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'
import { createLatestRequest } from '../lib/latestRequest.js'
import { createRowCache } from '../lib/rowCache.js'
import { useAccounts } from './useAccounts.js'
import { usePeriods } from './usePeriods.js'

// Die geladenen Listen liegen in shallowRefs (Issue #254): Sie werden nur als
// Ganzes ersetzt, nie in sich geändert. So läuft kein Leser über tausende
// Proxys, und die Einträge bleiben die Objekte vom Server. reactive() packt
// die Refs aus; state.journalData = … ersetzt den Inhalt des Refs.
const state = reactive({
	journalData: shallowRef([]),
	// Nur noch die unzugeordneten Umsätze (Issue #255).
	transactions: shallowRef([]),
	// Was die Oberfläche von den zugeordneten braucht, verdichtet: Historie je
	// Zahlungspartner, Fortschritt und die Umsätze der Journal-Zeilen.
	txContext: shallowRef({ history: [], progress: { total: 0, assigned: 0 }, byJournal: {} }),
})

const periods = usePeriods()
const accounts = useAccounts()

// Kein Teil des reaktiven Zustands: nur loadJournal() liest die Nummer.
let journalSeq = 0
// Dasselbe für loadTransactions(): der Kontext (byJournal) hängt am Zeitraum.
const startTransactionsLoad = createLatestRequest()

function accountLabel(id) {
	const acc = accounts.accountsById.value[id]
	return acc ? labelOf(acc) : `#${id}`
}

// Schlüssel der Kontobeschriftungen und -arten: Ändert sich ein Konto, das in
// einer Zeile steht (Nummer, Name) oder ihre Richtung bestimmt (isBank), werden
// alle Zeilen neu gebaut. Konten haben keinen eigenen Änderungsstempel, darum
// zählen die Felder selbst; das sind wenige hundert Konten, nicht Buchungen.
export function accountLabelKey(list) {
	return list.length + '|' + list.map((a) => `${a.id}:${a.number}:${a.name}:${a.shortName || ''}:${a.isBank ? 1 : 0}`).join('|')
}
const accountsKey = computed(() => accountLabelKey(accounts.state.accounts))

// Stand einer Buchung: updatedAt, sonst createdAt – Bankzuordnung
// (BookingService) und Eröffnungsbilanz (OpeningBalanceService) legen
// Buchungen ohne updatedAt an, und jede spätere Änderung über JournalService
// setzt es. Dazu die Nummer, weil das Neu-Nummerieren (EntryNumberService)
// keinen Stempel anfasst. Ohne beide Stempel kein Stand: dann baut der
// Zwischenspeicher die Zeile jedes Mal neu.
export function journalVersion(item) {
	const j = item.journal
	const stamp = j.updatedAt || j.createdAt
	return stamp ? `${stamp}#${j.entryNo}` : null
}

function buildJournalRow(item) {
	const j = item.journal
	const lines = item.lines || []
	const dl = lines.filter((l) => l.debitCents > 0)
	const cl = lines.filter((l) => l.creditCents > 0)
	return {
		id: j.id,
		entryNo: j.entryNo,
		date: j.date,
		description: j.description,
		documentRef: j.documentRef,
		soll: dl.map((l) => accountLabel(l.accountId)).join(', '),
		haben: cl.map((l) => accountLabel(l.accountId)).join(', '),
		debitAccountId: dl.length ? dl[0].accountId : null,
		creditAccountId: cl.length ? cl[0].accountId : null,
		isSplit: dl.length > 1 || cl.length > 1,
		// Die Zeilen selbst, damit der Buchungsdialog eine Splittbuchung zum
		// Bearbeiten laden kann (die abgeleiteten Felder oben reichen dafür nicht).
		lines,
		// Bearbeitbar ist eine Splittbuchung, solange genau eine Seite einzeilig
		// ist - das ist die Form, die der Dialog abbildet (eine feste Seite,
		// mehrere Gegenkonten). Echte N:M-Buchungen entstehen in dieser App
		// nirgends; kaemen sie aus Fremddaten, wuerde der Dialog sie beim
		// Speichern verstuemmeln, deshalb bleiben sie gesperrt.
		splitSide: dl.length === 1 && cl.length > 1
			? 'credit'
			: (cl.length === 1 && dl.length > 1 ? 'debit' : null),
		amount: lines.reduce((s, l) => s + (l.debitCents || 0), 0) / 100,
		updatedAt: j.updatedAt || null,
		comment: j.comment || '',
	}
}

const journalRowCache = createRowCache(buildJournalRow, { id: (item) => item.journal.id, version: journalVersion })

// Unveränderte Buchungen behalten nach jedem Nachladen ihr Zeilenobjekt, damit
// v-memo in der Buchungstabelle sie überspringt (Issue #254).
const journalRows = computed(() => journalRowCache(state.journalData, accountsKey.value))

const unassignedCount = computed(() => state.transactions.filter((t) => t.status === 'unassigned').length)

async function loadJournal() {
	// Laufende Nummer gegen überholte Antworten. Beim Start und nach jedem
	// Zeitraumwechsel laufen mehrere Journal-Abfragen gleichzeitig (Watcher,
	// mounted(), Reiterwechsel); wer als Letzter antwortet, gewinnt – und das
	// ist nicht zwingend die zuletzt gestellte Anfrage. Ohne diese Sperre
	// zeigte das Journal nach dem Wechsel auf 2031 gelegentlich den leeren
	// Stand des laufenden Zeitraums, dessen Antwort später eintraf.
	const seq = ++journalSeq
	try {
		const { data } = await api.journal(periods.state.selectedPeriodId)
		if (seq !== journalSeq) { return }
		state.journalData = data
	} catch (e) {
		if (seq !== journalSeq) { return }
		showError(errMsg(e, t('Journal konnte nicht geladen werden')))
	}
}

async function loadTransactions(periodId = null) {
	// Bei schnellem Zeitraumwechsel P1 → P2 darf die verspätete Antwort für P1
	// den Kontext von P2 nicht überschreiben: nur die zuletzt gestartete gilt.
	const isCurrent = startTransactionsLoad()
	try {
		// Beide Abfragen laufen gleichzeitig; erst wenn beide da sind, wird
		// übernommen, damit Liste und Fortschritt nie auseinanderlaufen.
		const [list, context] = await Promise.all([api.listTransactions('unassigned'), api.transactionContext(periodId)])
		if (!isCurrent()) { return }
		state.transactions = list.data
		state.txContext = context.data
	} catch (e) {
		if (!isCurrent()) { return }
		showError(errMsg(e, t('Buchungen konnten nicht geladen werden')))
	}
}

export function useJournal() {
	return { state, journalRows, unassignedCount, loadJournal, loadTransactions }
}
