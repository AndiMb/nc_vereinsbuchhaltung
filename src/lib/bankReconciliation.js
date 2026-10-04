// Zustandslose Helfer des Segments „Bankabgleich“ im Einzug-Unterreiter (Issue
// #105, Spec §3.6/§3.10/§5): Fortschritt eines Sammlers, Klartext für
// Zustände, Begründungen und Rückgabegründe, die automatischen Folgen einer
// Rücklastschrift und die Zeilen der Buchungsvorschau. Reine Funktionen ohne
// Vue-/DOM-Bezug, damit sie sich ohne Komponente testen lassen
// (bankReconciliation.test.js) – dieselbe Arbeitsteilung wie bei claims.js.
//
// Die Daten kommen fertig vom Server (GET /bank-reconciliation und …/preview,
// BankReconciliationService): Vorschläge samt Stufe, Rückgabe-Klasse, die
// Aufteilung der Buchung. Hier steckt nur, was die Oberfläche daraus macht.
// `t()` wird immer erst beim Aufruf ausgewertet, nie beim Import: die
// Übersetzungen sind beim Laden der Module noch nicht registriert.
//
// Nutzerdaten (Namen, Kontonamen, Verwendungszwecke aus dem Bankauszug) stehen
// nie in einer t()-Variable: @nextcloud/l10n escaped sie als HTML, aus „&“
// würde „&amp;“. Texte, die einen Namen nennen, setzen ihn deshalb in der
// Komponente neben den übersetzten Text.
import { formatDate, formatMoney } from './format.js'
import { t } from './l10n.js'

/** Urteil je Detail-Zeile (BankTxSepaDetail::STATUS_*). */
export const DETAIL_OPEN = 'offen'
export const DETAIL_ASSIGNED = 'zugeordnet'
export const DETAIL_REJECTED = 'abgelehnt'
export const DETAIL_UNMATCHED = 'nicht_zuordenbar'

/** Zustand eines Bankumsatzes in der Arbeitsliste. */
export const TX_OPEN = 'offen'
export const TX_READY = 'bereit'
export const TX_NO_MATCH = 'ohne_zuordnung'

/** Matching-Stufen (SepaMatchingService): je höher, desto schwächer der Treffer. */
export const STAGE_END_TO_END_ID = 1
export const STAGE_MANDATE_AND_AMOUNT = 2
export const STAGE_AMOUNT_AND_IBAN = 3

// --- Fortschritt eines Umsatzes ---------------------------------------------------------

/**
 * Fortschritt und Zustand eines Umsatzes, aus seinen Detail-Zeilen gerechnet –
 * nicht aus den Zahlen, die der Server beim Laden mitgab: nach jedem Urteil
 * ändert sich das, ohne dass die Liste neu geladen wird.
 *
 * Verbuchen ist erst möglich, wenn alle Zeilen beurteilt sind UND mindestens
 * eine einem Posten zugeordnet wurde (Spec §5): sonst bliebe nichts zu buchen.
 *
 * @param {{details: Array<{status: string}>}} entry Umsatz der Arbeitsliste
 * @return {{total: number, judged: number, assigned: number, state: string}}
 */
export function summarize(entry) {
	const details = entry.details || []
	const judged = details.filter((d) => d.status !== DETAIL_OPEN).length
	const assigned = details.filter((d) => d.status === DETAIL_ASSIGNED).length
	let state = TX_OPEN
	if (judged === details.length) {
		state = assigned > 0 ? TX_READY : TX_NO_MATCH
	}
	return { total: details.length, judged, assigned, state }
}

/** „4 von 12 beurteilt“ – der Fortschritt eines Sammlers. */
export function progressText(summary) {
	return t('{n} von {gesamt} beurteilt', { n: summary.judged, gesamt: summary.total })
}

/**
 * Warum „Verbuchen“ gerade nicht geht – oder leer, wenn es geht. Der Hinweis
 * steht neben dem Knopf, damit ein ausgegrauter Knopf nicht rätselhaft bleibt.
 */
export function settleHint(summary) {
	if (summary.state === TX_READY) { return '' }
	if (summary.state === TX_NO_MATCH) {
		return t('Keine Zeile ist einem Posten zugeordnet – es gibt nichts zu verbuchen. Ändern Sie ein Urteil, oder buchen Sie den Umsatz unter Buchungen von Hand.')
	}
	const open = summary.total - summary.judged
	return t('Verbuchen ist erst möglich, wenn alle {gesamt} Zeilen beurteilt sind – noch {offen} offen.', { gesamt: summary.total, offen: open })
}

/** Die Zahl der Umsätze, die noch etwas von jemandem wollen (Urteil oder Verbuchen) – nicht die ohne Zuordnung. */
export function pendingCount(items) {
	return items.filter((entry) => summarize(entry).state !== TX_NO_MATCH).length
}

/**
 * Die Einzugsposten, die schon einer ANDEREN Zeile desselben Umsatzes (und
 * derselben Richtung) zugeordnet sind. Ein Posten gehört zu höchstens einer
 * Zeile: bei mehreren Forderungen desselben Mandats mit gleichem Betrag passen
 * mehrere Zeilen auf dieselben Kandidaten, und ein zweites Zuordnen desselben
 * Postens würde eine Forderung doppelt abschließen. Der Server lehnt es ab
 * (SepaImportConfirmationService::assign()); die Oberfläche bietet es gar
 * nicht erst an.
 *
 * @param {{details: Array<object>}} entry Umsatz der Arbeitsliste
 * @param {{id: number, isReturn: boolean}} detail die Zeile, für die gefragt wird
 * @return {number[]} Posten-IDs
 */
export function takenItemIds(entry, detail) {
	return entry.details
		.filter((other) => other.id !== detail.id
			&& other.status === DETAIL_ASSIGNED
			&& other.debitItemId !== null
			&& other.isReturn === detail.isReturn)
		.map((other) => other.debitItemId)
}

/**
 * Zeilen mit genau einem Kandidaten einer starken Stufe (End-to-End-ID oder
 * Mandatsreferenz + Betrag), noch ohne Urteil: für „Eindeutige Vorschläge
 * bestätigen“. Mehrdeutige Zeilen und die schwächste Stufe (Betrag + IBAN)
 * bleiben bewusst draußen – dort entscheidet ein Mensch (Spec §5: bei
 * Mehrdeutigkeit alle Kandidaten zur Auswahl, keiner vorausgewählt). Ebenso
 * ein Kandidat, den eine andere Zeile schon hat oder den sich zwei offene
 * Zeilen teilen: eindeutig ist nur, was nur eine Zeile will.
 *
 * @param {{details: Array<object>}} entry Umsatz der Arbeitsliste
 * @return {Array<{detail: object, candidate: object}>} Zeilen mit ihrem einzigen Kandidaten
 */
export function unambiguousOpenDetails(entry) {
	const result = []
	for (const detail of entry.details || []) {
		if (detail.status !== DETAIL_OPEN || detail.candidates.length !== 1) { continue }
		const candidate = detail.candidates[0]
		if (candidate.stage > STAGE_MANDATE_AND_AMOUNT) { continue }
		if (takenItemIds(entry, detail).includes(candidate.debitItemId)) { continue }
		result.push({ detail, candidate })
	}
	// Zwei Zeilen mit demselben einzigen Kandidaten: keine von beiden ist eindeutig.
	return result.filter(({ detail, candidate }) => !result.some((other) => other.detail !== detail
		&& other.detail.isReturn === detail.isReturn
		&& other.candidate.debitItemId === candidate.debitItemId))
}

// --- Klartext -------------------------------------------------------------------------

export function kindLabel(kind) {
	return {
		einzug: t('Einzugsgutschrift'),
		ruecklastschrift: t('Rücklastschrift'),
		gemischt: t('Gutschrift und Rücklastschrift'),
	}[kind] || kind
}

export function txStateLabel(state) {
	return {
		[TX_OPEN]: t('wartet auf Urteil'),
		[TX_READY]: t('bereit zum Verbuchen'),
		[TX_NO_MATCH]: t('ohne Zuordnung beurteilt'),
	}[state] || state
}

/** Farbton für DebitStatusTag: `info` (Handlung nötig), `success` (bereit), sonst `neutral`. */
export function txStateTone(state) {
	return { [TX_OPEN]: 'info', [TX_READY]: 'success' }[state] || 'neutral'
}

export function detailStatusLabel(status) {
	return {
		[DETAIL_OPEN]: t('noch nicht beurteilt'),
		[DETAIL_ASSIGNED]: t('zugeordnet'),
		[DETAIL_REJECTED]: t('abgelehnt'),
		[DETAIL_UNMATCHED]: t('nicht zuordenbar'),
	}[status] || status
}

export function detailStatusTone(status) {
	return { [DETAIL_OPEN]: 'info', [DETAIL_ASSIGNED]: 'success' }[status] || 'neutral'
}

/**
 * Warum dieser Posten vorgeschlagen wird – in Klartext statt einer
 * Konfidenz-Zahl (Spec §5): die Stufe, auf der er gefunden wurde.
 */
export function stageReason(stage) {
	return {
		[STAGE_END_TO_END_ID]: t('Gleiche End-to-End-ID'),
		[STAGE_MANDATE_AND_AMOUNT]: t('Gleiche Mandatsreferenz und gleicher Betrag'),
		[STAGE_AMOUNT_AND_IBAN]: t('Gleicher Betrag und gleiche Zahler-IBAN'),
	}[stage] || ''
}

/** Die schwächste Stufe: ein Treffer allein über Betrag und IBAN, ohne Referenz. Er verdient einen genauen Blick. */
export function stageIsWeak(stage) {
	return stage === STAGE_AMOUNT_AND_IBAN
}

/**
 * Der Rückgabegrund in Klartext, nach Klasse (ReturnReasonClassifier, Spec
 * §3.6). Den ISO-Code zeigt die Oberfläche nur dort, wo der Server ihn
 * mitliefert (`buchhalter`/`verwalter`).
 */
export function returnReasonLabel(reasonClass) {
	return {
		insufficient_funds: t('Mangels Kontodeckung zurückgegeben'),
		account_unusable: t('Konto nicht nutzbar (z. B. aufgelöst, gesperrt oder IBAN ungültig)'),
		disputed: t('Vom Zahlungspflichtigen widersprochen oder ohne gültiges Mandat'),
		deceased: t('Zahlungspflichtiger verstorben'),
		technical: t('Technischer oder formaler Fehler (z. B. Doppeleinreichung oder Datenfehler)'),
		unknown: t('Grund unbekannt oder von der Bank nicht übermittelt'),
	}[reasonClass] || t('Grund unbekannt oder von der Bank nicht übermittelt')
}

/**
 * Was beim Verbuchen einer Rücklastschrift automatisch geschieht (Spec §3.6):
 * die Forderung wird wieder offen, je nach Grund wird das Mandat gesperrt, geht
 * eine Zahlungsaufforderung raus, entsteht eine Gebühren-Forderung. Vor der
 * Bestätigung genannt, damit niemand von den Folgen überrascht wird.
 *
 * @param {{suspendsMandate: boolean, paymentRequest: boolean, feeClaimCents: ?number}} info Folgen laut Server
 * @return {string[]} ein Satz je Folge, die Wiedereröffnung der Forderung zuerst
 */
export function returnConsequences(info) {
	const lines = [t('Die Forderung wird wieder offen. Sie wird nicht erneut per Lastschrift eingezogen.')]
	if (info.suspendsMandate) {
		lines.push(t('Das Mandat wird gesperrt: Es wird nicht mehr eingezogen, bis Sie es in der Mitglieder-Akte geklärt und entsperrt haben.'))
	}
	if (info.paymentRequest) {
		lines.push(t('Das Mitglied erhält sofort eine Zahlungsaufforderung per E-Mail (sofern eine E-Mail-Adresse hinterlegt ist).'))
	}
	if (info.feeClaimCents) {
		lines.push(t('Die Bankgebühr von {betrag} wird dem Mitglied als eigene Gebühren-Forderung weiterbelastet.', { betrag: formatMoney(info.feeClaimCents / 100) }))
	}
	return lines
}

// --- Buchungsvorschau -----------------------------------------------------------------

/** Rolle einer Gegenkonto-Zeile in der Vorschau. */
export function lineRoleLabel(role) {
	return {
		erloes: t('Erlös'),
		erloes_zurueck: t('Erlös zurück'),
		gebuehr: t('Bankgebühr'),
	}[role] || ''
}

/** „4000 Mitgliedsbeiträge“ – Nummer und Name eines Kontos. */
export function accountLabel(account) {
	return [account?.number, account?.name].filter(Boolean).join(' ')
}

/**
 * Die Zeilen der Buchungsvorschau in Buchungsreihenfolge: bei der Gutschrift
 * zuerst die Bank im Soll, dann die Erlöskonten im Haben; bei der
 * Rücklastschrift die Erlöskonten (und das Gebührenkonto) im Soll, dann die
 * Bank im Haben (BookingService::doAssign()).
 *
 * `count` ist bei Gutschriften die Zahl der Posten je Erlöskonto, damit eine
 * Sammelbuchung über 80 Mitglieder als „4000 Mitgliedsbeiträge · 80 Posten“
 * lesbar bleibt statt als Liste von 80 Zeilen.
 *
 * @param {object} preview Antwort von GET /bank-reconciliation/{id}/preview
 * @return {Array<{side: string, account: string, amountCents: number, role: string, count: ?number}>} Zeilen in Buchungsreihenfolge
 */
export function bookingRows(preview) {
	if (!preview?.direction) { return [] }
	const isReturn = preview.direction === 'ruecklastschrift'
	const perAccount = {}
	if (!isReturn) {
		for (const row of preview.rows || []) {
			perAccount[row.account.accountId] = (perAccount[row.account.accountId] || 0) + 1
		}
	}
	const contra = (preview.lines || []).map((line) => ({
		side: line.side,
		account: accountLabel(line),
		amountCents: line.amountCents,
		role: line.role,
		count: isReturn ? null : (perAccount[line.accountId] ?? null),
	}))
	const bank = {
		side: isReturn ? 'haben' : 'soll',
		account: accountLabel(preview.bank),
		amountCents: preview.amountCents,
		role: 'bank',
		count: null,
	}
	return isReturn ? [...contra, bank] : [bank, ...contra]
}

export function sideLabel(side) {
	return side === 'soll' ? t('Soll') : t('Haben')
}

/**
 * Text zu einem Hindernis der Vorschau. Die wichtigsten Fälle haben einen
 * eigenen Text mit dem nächsten Schritt; alles andere sagt der Server in
 * eigenen Worten (Meldungen der App, deutsch, für Menschen geschrieben).
 */
export function blockerText(blocker, preview) {
	if (blocker.code === 'period_closed') {
		return t('Der Bankumsatz vom {datum} liegt in einem abgeschlossenen Geschäftsjahr und kann deshalb nicht verbucht werden. Ein Verwalter kann das Geschäftsjahr in den Nextcloud-Einstellungen unter Vereinsbuchhaltung → Geschäftsjahr wiedereröffnen.', { datum: formatDate(preview?.bookingDate) })
	}
	if (blocker.code === 'already_booked') {
		return t('Dieser Bankumsatz ist bereits gebucht. Laden Sie die Liste neu: Möglicherweise hat jemand anderes ihn gerade verbucht.')
	}
	return blocker.message
}

/**
 * Meldung zu einem fehlgeschlagenen Verbuchen oder Urteil. Der Statuscode
 * entscheidet, nicht der rohe Antworttext: eine geschlossene Periode (423)
 * bekommt ihren eigenen Hinweis; die Meldungen der App bei 400 sind für
 * Menschen geschrieben und werden durchgereicht; alles andere (500, Netz weg)
 * zeigt nur den Rückfalltext, nie einen technischen Fehlertext.
 *
 * @param {object} error Axios-Fehler
 * @param {string} fallback Text, wenn der Server nichts Brauchbares sagt
 */
export function describeSettleError(error, fallback) {
	const status = error?.response?.status
	if (status === 423) {
		return t('Der Bankumsatz liegt in einem abgeschlossenen Geschäftsjahr und kann deshalb nicht verbucht werden. Ein Verwalter kann das Geschäftsjahr in den Nextcloud-Einstellungen unter Vereinsbuchhaltung → Geschäftsjahr wiedereröffnen.')
	}
	if (status === 403) {
		return t('Dafür fehlt Ihnen die Berechtigung: Urteile und Verbuchungen sind ab der Rolle Buchhalter möglich.')
	}
	if (status === 404) {
		return t('Der Eintrag wurde nicht gefunden – möglicherweise hat ihn jemand anderes inzwischen geändert. Laden Sie die Liste neu.')
	}
	const message = error?.response?.data?.message
	if (status === 400 && typeof message === 'string' && message !== '') {
		return message
	}
	return fallback
}
