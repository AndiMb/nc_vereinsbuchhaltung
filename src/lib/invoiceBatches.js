// Sammelrechnungen (#243): Zahlen und Auswahl für einen Rechnungsstapel.
// Rein, damit sich die Regeln ohne Komponente prüfen lassen
// (invoiceBatches.test.js). Grundlage sind die Zeilen der Rechnungsliste
// (InvoiceService::list), die `batchId` und den Empfänger mitbringen.

/** Die Rechnungen eines Stapels (ohne Stornorechnungen, die tragen keinen Stapel). */
export function batchInvoices(invoices, batchId) {
	return (invoices || []).filter((inv) => inv.batchId === batchId && inv.kind !== 'storno')
}

/** E-Mail-Adresse des Empfängers, wie der Versand sie vorschlägt. */
export function recipientEmail(inv) {
	return String((inv.debtor && inv.debtor.email) || '').trim()
}

/**
 * Stand eines Stapels: Anzahl je Status und Beträge in Cent. Stornierte
 * Rechnungen zählen weder zur Summe noch als offen.
 */
export function batchStats(invoices, batchId) {
	const list = batchInvoices(invoices, batchId)
	const s = { total: list.length, draft: 0, issued: 0, sent: 0, paid: 0, cancelled: 0, grossCents: 0, settledCents: 0, remainingCents: 0 }
	for (const inv of list) {
		if (inv.status in s) { s[inv.status]++ }
		if (inv.status === 'cancelled') { continue }
		s.grossCents += inv.grossCents || 0
		s.settledCents += inv.settledCents || 0
		s.remainingCents += inv.remainingCents || 0
	}
	return s
}

/** Entwürfe, die „Alle festschreiben“ bearbeitet. */
export function issuableInvoices(invoices, batchId) {
	return batchInvoices(invoices, batchId).filter((inv) => inv.status === 'draft')
}

/** Festgeschriebene, noch nicht versendete Rechnungen mit E-Mail-Adresse. */
export function sendableInvoices(invoices, batchId) {
	return batchInvoices(invoices, batchId).filter((inv) => inv.status === 'issued' && recipientEmail(inv) !== '')
}

/** Festgeschriebene, noch nicht versendete Rechnungen ohne E-Mail-Adresse – die muss jemand drucken. */
export function printOnlyInvoices(invoices, batchId) {
	return batchInvoices(invoices, batchId).filter((inv) => inv.status === 'issued' && recipientEmail(inv) === '')
}

/**
 * Führt `worker` für jedes Element nacheinander aus. Ein Fehler hält die
 * übrigen nicht auf; er wird mit dem Element gesammelt.
 *
 * @param {Array} items
 * @param {(item) => Promise} worker
 * @param {(done: number, total: number) => void} [onProgress]
 * @param {(error) => string} [describe] macht aus einem Fehler den Text für die Liste
 * @return {Promise<{ ok: number, failed: Array<{ item, message: string }> }>}
 */
export async function runSequential(items, worker, onProgress = () => {}, describe = (e) => String((e && e.message) || e)) {
	const failed = []
	let ok = 0
	let done = 0
	for (const item of items) {
		try {
			await worker(item)
			ok++
		} catch (e) {
			failed.push({ item, message: describe(e) })
		}
		done++
		onProgress(done, items.length)
	}
	return { ok, failed }
}

/**
 * Fehlertext für die Liste nach einer Sammelaktion. Scheitert das
 * Festschreiben an fehlenden Pflichtangaben, nennt der Server sie in
 * `problems`; die gehören hinter die allgemeine Meldung, sonst weiß niemand,
 * was zu tun ist.
 *
 * @param {Error|object} e Fehler aus axios
 * @param {string} fallback Text ohne Serverantwort
 * @param {string} missingLabel Einleitung der Liste, z. B. „Es fehlt:“
 * @return {string}
 */
export function failureMessage(e, fallback, missingLabel) {
	const data = (e && e.response && e.response.data) || {}
	const message = data.message || (e && e.response ? '' : e && e.message) || fallback
	const problems = Array.isArray(data.problems) ? data.problems.filter(Boolean) : []
	return problems.length ? `${message} ${missingLabel} ${problems.join('; ')}` : message
}
