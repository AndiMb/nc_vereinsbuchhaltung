// Kontoabstimmung (Issue #159): reine Helfer für die Ansicht – Zerlegung der
// Saldendifferenz, Sprungziele und der CSV-Export des Ergebnisses. Nicht der
// „Bankabgleich“ des SEPA-Moduls (bankReconciliation.js).

/**
 * Posten, aus denen sich die Differenz am Ende zusammensetzt (Differenz =
 * App − Bank), in der Reihenfolge der Anzeige. „Fehlt“ und „verschoben“
 * mindern die Differenz und stehen deshalb mit umgekehrtem Vorzeichen da;
 * „verschoben“ und der Rest erscheinen nur, wenn es sie gibt. Die Posten vor „diffClosing“ ergeben zusammen genau dessen Wert.
 *
 * @param {object|null} balance Saldenblock der Serverantwort
 * @return {Array<{key: string, cents: number}>}
 */
export function breakdownRows(balance) {
	if (!balance) { return [] }
	const rows = [
		{ key: 'diffOpening', cents: balance.diffOpeningCents },
		{ key: 'surplus', cents: balance.surplusCents },
		{ key: 'missing', cents: -balance.missingCents },
	]
	if (balance.shiftedCents !== 0) { rows.push({ key: 'shifted', cents: -balance.shiftedCents }) }
	if (balance.restCents !== 0) { rows.push({ key: 'rest', cents: balance.restCents }) }
	rows.push({ key: 'diffClosing', cents: balance.diffClosingCents })
	return rows
}

/**
 * Stimmen Konto und Auszug überein? Keine offene Zeile auf beiden Seiten und
 * – sofern der Auszug Salden trägt – kein Unterschied am Ende.
 *
 * @param {object|null} result Serverantwort
 * @return {boolean}
 */
export function isClean(result) {
	if (!result) { return false }
	if (result.surplus.length || result.missing.length || result.shifted.length) { return false }
	return !result.balance || result.balance.diffClosingCents === 0
}

/**
 * Router-Ziel zu einer App-Bewegung: die Buchungsliste, gefiltert auf Konto
 * und Tag (einen Link auf eine einzelne Buchung kennt die Liste nicht), bzw.
 * „Zuzuordnen“ für einen noch nicht zugeordneten Umsatz.
 *
 * @param {number} accountId
 * @param {{kind: string, date: string}} row
 * @return {object}
 */
export function bookingLink(accountId, row) {
	if (row.kind === 'unassigned') { return { name: 'bookings-unassigned' } }
	return { name: 'bookings', query: { period: 'all', account: accountId, from: row.date, to: row.date } }
}

/**
 * Cent als Dezimalzahl mit Komma, ohne Tausenderpunkte – so liest sie eine
 * Tabellenkalkulation mit deutschem Gebietsschema als Zahl.
 *
 * @param {number} cents
 * @return {string}
 */
function csvAmount(cents) {
	const abs = Math.abs(cents)
	return (cents < 0 ? '-' : '') + Math.floor(abs / 100) + ',' + String(abs % 100).padStart(2, '0')
}

/**
 * Ein Betrag, wie ihn csvAmount() schreibt – der einzige Zellinhalt, der mit
 * einem Minus beginnen darf.
 */
const CSV_AMOUNT = /^-?\d+,\d{2}$/

/**
 * Texte des Auszugs bestimmt, wer überweist. Beginnt einer mit einem Zeichen,
 * das eine Tabellenkalkulation als Formel liest, erzwingt ein Apostroph die
 * Text-Deutung – wie in den Server-Exporten (CsvFormatter::safeField()).
 *
 * @param {Array<string|number|null|undefined>} cells
 * @return {string}
 */
function csvLine(cells) {
	return cells.map((cell) => {
		let value = cell === null || cell === undefined ? '' : String(cell)
		if (/^[=+\-@\t\r]/.test(value) && !CSV_AMOUNT.test(value)) { value = '\'' + value }
		return /[;"\r\n]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value
	}).join(';')
}

/**
 * Das Ergebnis der Abstimmung als CSV (Semikolon, BOM, CRLF). Leere Listen
 * und eine fehlende Saldenprüfung entfallen.
 *
 * @param {object} result Serverantwort
 * @param {object} labels übersetzte Beschriftungen (surplus, missing, shifted, balance, date, text, counterparty, amount, kind, appDate, kinds{}, breakdown{})
 * @return {string}
 */
export function reconciliationCsv(result, labels) {
	const sections = []
	if (result.surplus.length) {
		sections.push([
			labels.surplus,
			csvLine([labels.date, labels.text, labels.kind, labels.amount]),
			...result.surplus.map((row) => csvLine([row.date, row.text, labels.kinds[row.kind] || row.kind, csvAmount(row.amountCents)])),
		])
	}
	if (result.missing.length) {
		sections.push([
			labels.missing,
			csvLine([labels.date, labels.text, labels.counterparty, labels.amount]),
			...result.missing.map((row) => csvLine([row.date, row.text, row.counterparty, csvAmount(row.amountCents)])),
		])
	}
	if (result.shifted.length) {
		sections.push([
			labels.shifted,
			csvLine([labels.date, labels.text, labels.counterparty, labels.appDate, labels.kind, labels.amount]),
			...result.shifted.map(({ statement, app }) => csvLine([
				statement.date,
				statement.text,
				statement.counterparty,
				app.date,
				labels.kinds[app.kind] || app.kind,
				csvAmount(statement.amountCents),
			])),
		])
	}
	const breakdown = breakdownRows(result.balance)
	if (breakdown.length) {
		sections.push([
			labels.balance,
			...breakdown.map((row) => csvLine([labels.breakdown[row.key], csvAmount(row.cents)])),
		])
	}
	return '\uFEFF' + sections.map((lines) => lines.join('\r\n')).join('\r\n\r\n') + '\r\n'
}
