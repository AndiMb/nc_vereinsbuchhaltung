// Auslagenerstattung (docs/reimbursement-design.md): zustandslose Helfer für
// Status, Fortschritt und Historie - von der Mitglieder- und der
// Verwaltungssicht gemeinsam genutzt.
import { formatMoney } from './format.js'
import { normalizeIban } from './iban.js'
import { t, tRaw } from './l10n.js'
import { parseAmountQuery } from './search.js'

/** Reihenfolge der Filter-Chips in der Verwaltungssicht. */
export const REIMBURSEMENT_STATUSES = ['submitted', 'partially_approved', 'approved', 'paid', 'rejected', 'withdrawn']

export function reimbursementStatusLabel(status) {
	switch (status) {
		case 'submitted': return t('Eingereicht')
		case 'partially_approved': return t('Teilweise freigegeben')
		case 'approved': return t('Freigegeben')
		case 'paid': return t('Ausgezahlt')
		case 'rejected': return t('Abgelehnt')
		case 'withdrawn': return t('Zurückgezogen')
		default: return status
	}
}

/**
 * Farbklasse des Status-Tags (siehe .vbh-typetag in styles.css): erledigt grün,
 * abgelehnt rot, alles andere neutral.
 */
export function reimbursementStatusClass(status) {
	if (status === 'paid' || status === 'approved') { return 'paid' }
	if (status === 'rejected') { return 'expense' }
	return ''
}

/** „1 von 2 Freigaben“ - nur sinnvoll, solange über den Antrag noch entschieden wird oder er freigegeben wurde. */
export function reimbursementProgressText(item) {
	if (item.status === 'rejected' || item.status === 'withdrawn') { return '' }
	return t('{approved} von {required} Freigaben', { approved: item.progress.approved, required: item.progress.required })
}

/**
 * Zeile der Historie als Satz. `staff` zeigt Namen, die Mitgliedersicht nur
 * „ein Verwalter“ (Design §5).
 */
export function reimbursementEventText(event, staff) {
	const who = staff ? (event.actorName || event.actorUid || '') : ''
	const data = event.data || {}
	switch (event.action) {
		case 'submitted':
			return staff ? tRaw('{name} hat den Antrag eingereicht', { name: who }) : t('Antrag eingereicht')
		case 'approved':
			return (staff
				? tRaw('{name} hat freigegeben', { name: who })
				: t('Freigabe durch einen Verwalter'))
			+ (data.required ? ` (${data.count} / ${data.required})` : '')
		case 'rejected':
			return staff ? tRaw('{name} hat abgelehnt', { name: who }) : t('Antrag abgelehnt')
		case 'withdrawn':
			return t('Antrag zurückgezogen')
		case 'transferred':
			return t('Überweisung angewiesen')
		case 'transfer_revoked':
			return t('Vormerkung der Überweisung zurückgenommen')
		case 'payment_reverted':
			return t('Auszahlung zurückgenommen')
		case 'paid':
			return t('Erstattung überwiesen')
		case 'receipt_added':
			return tRaw('Beleg hinzugefügt: {file}', { file: data.file || '' })
		case 'receipt_removed':
			return tRaw('Beleg entfernt: {file}', { file: data.file || '' })
		case 'duplicate_hint':
			return t('Hinweis: Ein Beleg liegt schon in einem anderen Antrag vor')
		case 'amount_corrected': {
			// #353: Begründung steht als Kommentar darunter; frühere Freigaben verfallen.
			const amounts = { from: formatMoney((data.from || 0) / 100), to: formatMoney((data.to || 0) / 100) }
			return staff
				? tRaw('{name} hat den Betrag von {from} auf {to} korrigiert', { name: who, ...amounts })
				: t('Betrag durch einen Verwalter korrigiert: {from} → {to}', amounts)
		}
		default:
			return event.action
	}
}

/** Zählt die Anträge je Status, für die Filter-Chips. */
export function countByStatus(items) {
	const counts = {}
	for (const item of items) { counts[item.status] = (counts[item.status] || 0) + 1 }
	return counts
}

/**
 * Passt die Auslage zum Suchtext? Gesucht wird in Zweck, Mitgliedsname und
 * Betrag (12,50 findet auch 12.5).
 */
export function matchesReimbursementSearch(item, query) {
	const q = (query || '').trim().toLowerCase()
	if (q === '') { return true }
	const haystack = [item.purpose, item.memberName, item.number, String(item.id)].join(' ').toLowerCase()
	return haystack.includes(q) || parseAmountQuery(q) === item.amountCents
}

/**
 * Vorbelegung des Buchungsdialogs für die Auszahlung (Design §7): Betrag,
 * Text und Beleg-Referenz, den Rest wählt der Buchende.
 */
export function reimbursementBookingPrefill(item, today) {
	return {
		date: today,
		amount: item.amountCents / 100,
		kind: 'expense',
		description: tRaw('Erstattung Auslage: {purpose} ({member})', { purpose: item.purpose, member: item.memberName }),
		documentRef: item.number || `AUSL-${item.id}`,
	}
}

// --- Antragsformular (Rechnung, Kontoverbindung, Erklärung) --------------------

/**
 * Die Felder, die jeder Antrag neu braucht - Kontoverbindung und Erklärung bleiben beim nächsten stehen.
 * `files` sind neu gewählte Dateien, `draftFiles` die schon in einem Entwurf gespeicherten Belege (#356).
 */
export function emptyReimbursementInvoice(date) {
	return { amount: '', expenseDate: date, invoiceNumber: '', supplier: '', purpose: '', files: [], draftFiles: [] }
}

/** Ein leeres Formular; `account` (accountHolder, iban) belegt die Kontoverbindung vor. */
export function emptyReimbursementForm(date, account = {}) {
	return {
		...emptyReimbursementInvoice(date),
		accountHolder: account.accountHolder || '',
		iban: account.iban || '',
		declaration: false,
	}
}

/**
 * Das Formular zu einem gespeicherten Entwurf (#356). Leere Felder des Entwurfs
 * bleiben leer; nur die Kontoverbindung fällt auf den Vorschlag zurück. Die
 * Erklärung ist nie gespeichert - sie wird beim Einreichen bestätigt.
 */
export function reimbursementDraftForm(draft, account = {}) {
	return {
		amount: draft.amountCents !== null && draft.amountCents !== undefined ? draft.amountCents / 100 : '',
		expenseDate: draft.expenseDate || '',
		invoiceNumber: draft.invoiceNumber || '',
		supplier: draft.supplier || '',
		purpose: draft.purpose || '',
		files: [],
		draftFiles: [...(draft.files || [])],
		accountHolder: draft.accountHolder || account.accountHolder || '',
		iban: draft.iban || account.iban || '',
		declaration: false,
	}
}

/** Hat das Formular überhaupt eine Angabe? Ein ganz leerer Entwurf lohnt das Speichern nicht. */
export function reimbursementFormHasContent(form) {
	return Number(form.amount) > 0
		|| form.purpose.trim() !== ''
		|| form.invoiceNumber.trim() !== ''
		|| form.supplier.trim() !== ''
		|| form.files.length > 0
		|| (form.draftFiles || []).length > 0
}

/** Was zum Einreichen noch fehlt, als Liste von Feldnamen - sie steht neben dem Knopf. */
export function missingReimbursementFields(form) {
	const missing = []
	if (!(Number(form.amount) > 0)) { missing.push(t('Betrag')) }
	if (!form.expenseDate) { missing.push(t('Rechnungsdatum')) }
	if (form.purpose.trim() === '') { missing.push(t('Zweck')) }
	if (form.accountHolder.trim() === '') { missing.push(t('Kontoinhaber')) }
	if (normalizeIban(form.iban).length < 15) { missing.push(t('IBAN')) }
	if (form.files.length + (form.draftFiles || []).length === 0) { missing.push(t('Beleg')) }
	if (!form.declaration) { missing.push(t('Erklärung')) }
	return missing
}
