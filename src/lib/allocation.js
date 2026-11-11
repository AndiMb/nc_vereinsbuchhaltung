// Regeln fuer die Verteilung einer Buchungszeile auf Kostenstellen (Issue #7).
// Reine Funktionen ohne Vue-Bezug, damit Buchungsdialog, Aufteilen-Dialog und
// App.vue dieselbe Entscheidung treffen - und damit sie ohne Browser pruefbar
// sind. Verbindlich prueft das Backend (AllocationRules.php); hier steht die
// bedienfreundliche Vorstufe.
//
// Eine Verteilung ist eine Liste [{ costCenterId, amount }] mit Betraegen in
// Euro. Im Formular liegt sie je Konto: { [accountId]: [...] } - ein Konto
// kommt in einer Buchung nur einmal vor.

import { splitSideOf } from './split.js'

const cents = (euro) => Math.round(Number(euro || 0) * 100)

/**
 * Summe einer Verteilung in Cent. Gerechnet wird je Eintrag in Cent, damit
 * 33,33 + 33,33 + 33,34 wirklich 100 ergibt.
 *
 * @param {Array<{amount: number}>} allocations Verteilung
 * @return {number} Summe in Cent
 */
export function allocatedCents(allocations) {
	return (allocations || []).reduce((sum, a) => sum + cents(a.amount), 0)
}

/**
 * Was von der Zeile keiner Kostenstelle zugeordnet ist.
 *
 * @param {number} lineAmountEuro Betrag der Buchungszeile
 * @param {Array} allocations Verteilung
 * @return {number} Rest in Euro (negativ = zu viel verteilt)
 */
export function remainderEuro(lineAmountEuro, allocations) {
	return (cents(lineAmountEuro) - allocatedCents(allocations)) / 100
}

const isBlank = (a) => !a.costCenterId && !cents(a.amount)

/**
 * Erster Fehler einer Verteilung, in der Reihenfolge, in der man ihn behebt.
 * Ganz leere Zeilen (noch nichts gewaehlt) zaehlen nicht.
 *
 * @param {number} lineAmountEuro Betrag der Buchungszeile
 * @param {Array} allocations Verteilung
 * @return {null|'missing'|'amount'|'duplicate'|'exceeds'} Fehlercode
 */
export function allocationError(lineAmountEuro, allocations) {
	const rows = (allocations || []).filter((a) => !isBlank(a))
	const seen = new Set()
	for (const a of rows) {
		if (!a.costCenterId) { return 'missing' }
		if (!(cents(a.amount) > 0)) { return 'amount' }
		if (seen.has(a.costCenterId)) { return 'duplicate' }
		seen.add(a.costCenterId)
	}
	if (allocatedCents(rows) > cents(lineAmountEuro)) { return 'exceeds' }
	return null
}

/**
 * Genau eine Kostenstelle ueber den ganzen Zeilenbetrag - der Normalfall,
 * den das einfache Auswahlfeld abbildet.
 *
 * @param {number} lineAmountEuro Betrag der Buchungszeile
 * @param {Array} allocations Verteilung
 * @return {boolean}
 */
export function isSingleFull(lineAmountEuro, allocations) {
	return (allocations || []).length === 1
		&& !!allocations[0].costCenterId
		&& cents(allocations[0].amount) === cents(lineAmountEuro)
}

/**
 * Aendert sich der Zeilenbetrag, wandert die eine Kostenstelle ueber den
 * ganzen Betrag mit. Eine bewusste Aufteilung bleibt dagegen stehen - passt
 * sie nicht mehr, meldet das allocationError().
 *
 * @param {number} oldAmountEuro bisheriger Zeilenbetrag
 * @param {number} newAmountEuro neuer Zeilenbetrag
 * @param {Array} allocations Verteilung
 * @return {Array} dieselbe Liste oder die mitgezogene
 */
export function followAmount(oldAmountEuro, newAmountEuro, allocations) {
	if (!isSingleFull(oldAmountEuro, allocations)) { return allocations }
	return [{ ...allocations[0], amount: Number(newAmountEuro || 0) }]
}

/**
 * Die Verteilung, wie sie an die API geht: nur vollstaendige Eintraege.
 *
 * @param {Array} allocations Verteilung
 * @return {Array<{costCenterId: number, amount: number}>}
 */
export function toPayload(allocations) {
	return (allocations || [])
		.filter((a) => a.costCenterId && cents(a.amount) > 0)
		.map((a) => ({ costCenterId: a.costCenterId, amount: cents(a.amount) / 100 }))
}

/**
 * Die Buchungszeilen, die aus dem Formular entstehen - dieselbe Ableitung
 * wie beim Speichern (App.vue: deriveSimpleAccounts, buildSplitPayloadLines),
 * hier fuer die Anzeige der Kostenstellen je Zeile. Zeilen ohne Konto fehlen.
 *
 * @param {object} form bookingForm
 * @param {string} mode 'simple' | 'expert'
 * @return {Array<{accountId: number, amount: number, side: string}>}
 */
export function bookingLines(form, mode) {
	const total = Number(form.amount || 0)
	let lines
	if (form.splitMode) {
		const splitSide = splitSideOf(form, mode)
		const fixedSide = splitSide === 'credit' ? 'debit' : 'credit'
		const fixedAccountId = mode === 'simple'
			? form.moneyAccountId
			: (splitSide === 'credit' ? form.debitAccountId : form.creditAccountId)
		lines = [
			{ accountId: fixedAccountId, amount: total, side: fixedSide },
			...(form.splitLines || []).map((l) => ({ accountId: l.accountId, amount: Number(l.amount || 0), side: splitSide })),
		]
	} else if (mode === 'simple') {
		const [debit, credit] = form.kind === 'income'
			? [form.moneyAccountId, form.categoryId]
			: [form.categoryId, form.moneyAccountId]
		lines = [{ accountId: debit, amount: total, side: 'debit' }, { accountId: credit, amount: total, side: 'credit' }]
	} else {
		lines = [
			{ accountId: form.debitAccountId, amount: total, side: 'debit' },
			{ accountId: form.creditAccountId, amount: total, side: 'credit' },
		]
	}
	return lines.filter((l) => l.accountId)
}

/**
 * Nur Zeilen auf Ertrags- und Aufwandskonten tragen eine Kostenstelle
 * (wie Account::canCarryCostCenter() im Backend).
 *
 * @param {Array} lines Zeilen aus bookingLines()
 * @param {object} accountsById Konten nach ID
 * @return {Array}
 */
export function carryingLines(lines, accountsById) {
	return lines.filter((l) => {
		const type = accountsById[l.accountId]?.type
		return type === 'income' || type === 'expense'
	})
}

/**
 * Haelt die Verteilungen des Formulars zu den Zeilen passend, wenn sich
 * Konten oder Betraege aendern:
 *
 * - wechselt genau eine Zeile ihr Konto, zieht ihre Verteilung mit um (die
 *   Mannschaft bleibt dieselbe, nur die Kostenart aendert sich);
 * - faellt eine Zeile weg oder traegt sie keine Kostenstelle mehr, wird ihre
 *   Verteilung verworfen;
 * - aendert sich ein Betrag, greift followAmount().
 *
 * Aendert sich nichts, kommt dieselbe Referenz zurueck (kein unnoetiges
 * Neuzeichnen, kein falsches "geaendert").
 *
 * @param {Array<{accountId: number, amount: number}>} prevLines tragende Zeilen vorher
 * @param {Array<{accountId: number, amount: number}>} nextLines tragende Zeilen jetzt
 * @param {object} allocations { [accountId]: Verteilung }
 * @return {object} Verteilungen, passend zu nextLines
 */
export function syncAllocations(prevLines, nextLines, allocations) {
	const current = allocations || {}
	const prevIds = prevLines.map((l) => l.accountId)
	const nextIds = nextLines.map((l) => l.accountId)
	const removed = prevIds.filter((id) => !nextIds.includes(id))
	const added = nextIds.filter((id) => !prevIds.includes(id))
	const movedFrom = removed.length === 1 && added.length === 1 ? removed[0] : null

	const out = {}
	let changed = false
	for (const line of nextLines) {
		const sourceId = movedFrom !== null && line.accountId === added[0] ? movedFrom : line.accountId
		const before = current[sourceId]
		if (!before || !before.length) { continue }
		const prevLine = prevLines.find((l) => l.accountId === sourceId)
		const after = prevLine ? followAmount(prevLine.amount, line.amount, before) : before
		out[line.accountId] = after
		if (after !== before || sourceId !== line.accountId) { changed = true }
	}
	const keptKeys = Object.keys(out).length
	const hadKeys = Object.keys(current).filter((id) => current[id] && current[id].length).length
	if (!changed && keptKeys === hadKeys && keptKeys === Object.keys(current).length) { return current }
	return out
}

/**
 * Erster Fehler ueber alle tragenden Zeilen.
 *
 * @param {Array<{accountId: number, amount: number}>} lines tragende Zeilen
 * @param {object} allocations { [accountId]: Verteilung }
 * @return {{accountId: number, error: string}|null}
 */
export function firstAllocationError(lines, allocations) {
	for (const line of lines) {
		const error = allocationError(line.amount, (allocations || {})[line.accountId])
		if (error) { return { accountId: line.accountId, error } }
	}
	return null
}

/**
 * Haengt den Zeilen der API-Nutzlast ihre Verteilung an. Jede Zeile bekommt
 * den Schluessel - auch leer: bei eingeschaltetem Modul heisst das "ersetzen".
 * (Fehlt er, laesst das Backend die bisherige Verteilung stehen.)
 *
 * @param {Array<{accountId: number}>} payloadLines Zeilen fuer die API
 * @param {object} allocations { [accountId]: Verteilung }
 * @param {Array<number>} carryingIds Konten, die eine Kostenstelle tragen duerfen
 * @return {Array}
 */
export function withAllocations(payloadLines, allocations, carryingIds) {
	return payloadLines.map((line) => ({
		...line,
		allocations: carryingIds.includes(line.accountId) ? toPayload((allocations || {})[line.accountId]) : [],
	}))
}

/**
 * Kurzform fuer die Buchungsliste: das Kuerzel, bei mehreren "SEN +1".
 *
 * @param {Array<{costCenterId: number}>} allocations Verteilung
 * @param {object} costCentersById Kostenstellen nach ID
 * @return {string} leer ohne Verteilung
 */
export function allocationSummary(allocations, costCentersById) {
	const list = allocations || []
	if (!list.length) { return '' }
	const first = costCentersById[list[0].costCenterId]?.code ?? `#${list[0].costCenterId}`
	return list.length === 1 ? first : `${first} +${list.length - 1}`
}
