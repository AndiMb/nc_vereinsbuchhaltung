import { reactive, computed } from 'vue'
import { showError } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { useYears } from './useYears.js'
import { useAccounts } from './useAccounts.js'

const state = reactive({
	journalData: [],
	transactions: [],
})

const years = useYears()
const accounts = useAccounts()

function accountLabel(id) {
	const acc = accounts.accountsById.value[id]
	return acc ? `${acc.number} ${acc.name}` : `#${id}`
}

const journalRows = computed(() => state.journalData.map(item => {
	const j = item.journal
	const lines = item.lines || []
	const dl = lines.filter(l => l.debitCents > 0)
	const cl = lines.filter(l => l.creditCents > 0)
	return {
		id: j.id,
		entryNo: j.entryNo,
		date: j.date,
		description: j.description,
		documentRef: j.documentRef,
		soll: dl.map(l => accountLabel(l.accountId)).join(', '),
		haben: cl.map(l => accountLabel(l.accountId)).join(', '),
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
	}
}))

const unassignedCount = computed(() => state.transactions.filter(t => t.status === 'unassigned').length)

async function loadJournal() {
	try {
		const { data } = await api.journal(years.state.selectedYear)
		state.journalData = data
	} catch (e) { showError(errMsg(e, 'Journal konnte nicht geladen werden')) }
}

async function loadTransactions() {
	try {
		const { data } = await api.listTransactions('')
		state.transactions = data
	} catch (e) { showError(errMsg(e, 'Buchungen konnten nicht geladen werden')) }
}

export function useJournal() {
	return { state, journalRows, unassignedCount, loadJournal, loadTransactions }
}
