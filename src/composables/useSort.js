import { reactive } from 'vue'

// Die Sortierung der drei Tabellen (Bankumsaetze, Saldenliste, Journal).
//
// Der Zustand lag in App.vue und wurde als sort-Objekt samt toggleSort und
// sortArrow an BookingsTab und ReportsTab durchgereicht - drei Props fuer
// eine Tabelleneinstellung. Ein Kommentar in BookingsTab hielt dabei fest,
// das Objekt werde "per Referenz durchgereicht (auch von der Saldenliste im
// Berichte-Tab genutzt)": genau die Kopplung, die ein gemeinsamer Zustand
// ohnehin herstellt, nur ohne die Prop-Kette.

const state = reactive({
	transactions: { key: 'bookingDate', dir: 'desc' },
	balances: { key: 'number', dir: 'asc' },
	journal: { key: 'entryNo', dir: 'desc' },
})

/**
 * Schaltet die Sortierung einer Tabelle um: dieselbe Spalte dreht die
 * Richtung, eine andere Spalte beginnt aufsteigend.
 *
 * @param {string} table 'transactions' | 'balances' | 'journal'
 * @param {string} key Feldname der Spalte
 */
function toggleSort(table, key) {
	const s = state[table]
	if (s.key === key) s.dir = s.dir === 'asc' ? 'desc' : 'asc'
	else { s.key = key; s.dir = 'asc' }
}

/** Der Pfeil hinter der gerade sortierten Spaltenueberschrift. */
function sortArrow(table, key) {
	const s = state[table]
	return s.key !== key ? '' : (s.dir === 'asc' ? ' ▲' : ' ▼')
}

/**
 * Sortiert Zeilen nach dem Zustand einer Tabelle.
 *
 * Zahlen werden numerisch verglichen, alles andere mit localeCompare in
 * deutscher Sortierung (numeric: true, damit "Konto 9" vor "Konto 10" steht).
 * Wer ein Feld rein lexikografisch sortiert haben will - Datumsangaben im
 * ISO-Format etwa, bei denen der Zeichenvergleich schon chronologisch ist -,
 * nennt es in lexKeys.
 *
 * Reine Funktion: sie veraendert die uebergebene Liste nicht, sondern gibt
 * eine sortierte Kopie zurueck.
 *
 * @param {Array} rows die Zeilen
 * @param {{key: string, dir: string}} state Sortierzustand
 * @param {Array<string>} lexKeys Felder, die rein lexikografisch verglichen werden
 * @return {Array} sortierte Kopie
 */
export function applySort(rows, state, lexKeys = []) {
	if (!state || !state.key) return rows
	const f = state.dir === 'asc' ? 1 : -1
	const lex = lexKeys.includes(state.key)
	return rows.slice().sort((a, b) => {
		let x = a[state.key]; let y = b[state.key]
		if (x === null || x === undefined) x = ''
		if (y === null || y === undefined) y = ''
		if (lex) {
			const sx = String(x); const sy = String(y)
			return (sx < sy ? -1 : sx > sy ? 1 : 0) * f
		}
		if (typeof x === 'number' && typeof y === 'number') return (x - y) * f
		return String(x).localeCompare(String(y), 'de', { numeric: true, sensitivity: 'base' }) * f
	})
}

export function useSort() {
	return { sort: state, toggleSort, sortArrow, applySort }
}
