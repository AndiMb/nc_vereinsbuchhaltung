// Schluessel fuer v-for-Listen.
//
// Index-Schluessel lassen Vue bei Einfuegen, Loeschen und Umsortieren die falschen
// Zeilen wiederverwenden (Eingabefokus und Zustand wandern mit dem Index). Wo es eine
// ID gibt, nimmt die Liste die ID; Listen ohne ID bekommen beim Anlegen ein `uid`.

let counter = 0

/** Neuer, im Fenster eindeutiger Schluessel fuer eine bearbeitbare Zeile ohne ID. */
export function newUid() {
	return 'u' + (++counter)
}

/**
 * Schluessel fuer Zeilen eines Kontoauszugs. Eine Zeile ist eine Buchungszeile eines
 * Kontos; zwei Zeilen derselben Buchung auf verschiedenen Konten (Unterkonten
 * einbezogen) unterscheiden sich im Konto. Hat eine aufgeteilte Buchung mehrere Zeilen
 * auf demselben Konto, tragen die weiteren ihre Reihenfolge als Zusatz: `1-7`, `1-7#2`.
 * Die Reihenfolge der Zeilen einer Buchung steht fest, der Schluessel bleibt stabil.
 *
 * @param {Array<{journalId: number, accountId: number}>} rows Zeilen des Kontoauszugs
 * @return {string[]} ein Schluessel je Zeile
 */
export function statementRowKeys(rows) {
	const seen = new Map()
	return (rows || []).map((row) => {
		const base = row.journalId + '-' + row.accountId
		const n = (seen.get(base) || 0) + 1
		seen.set(base, n)
		return n === 1 ? base : base + '#' + n
	})
}
