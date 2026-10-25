// Zustandslose Helfer des Rechnungsmoduls (Epic #168). Reine Funktionen ohne
// Vue-/DOM-Bezug, damit sie sich ohne Komponente testen lassen
// (invoices.test.js). Die Rechenregeln spiegeln lib/Service/InvoiceCalculator.php:
// Beträge in Cent, Mengen in Zehntausendstel, Rabatt in Basispunkten – so zeigt
// der Editor dieselben Cent wie das PDF, ohne Rundungsabweichung. `t()` wird
// erst beim Aufruf ausgewertet, nie beim Import.
import { t } from './l10n.js'

/** Ganzzahlige Division, halbe Einheiten weg von null (InvoiceCalculator::roundDiv). */
export function roundDiv(numerator, denominator) {
	const negative = (numerator < 0) !== (denominator < 0)
	const n = Math.abs(numerator)
	const d = Math.abs(denominator)
	let q = Math.floor(n / d)
	if ((n % d) * 2 >= d) { q++ }
	return negative ? -q : q
}

/** Netto einer Position in Cent: Menge × Einzelpreis, danach der Rabatt. */
export function lineNet(quantityE4, unitPriceCents, discountBp = 0) {
	const base = roundDiv(quantityE4 * unitPriceCents, 10000)
	return base - roundDiv(base * discountBp, 10000)
}

/** Summen einer Rechnung ohne Steuerausweis (Stufe 1); Steuer folgt mit der Regelbesteuerung. */
export function totals(lines) {
	const net = lines.reduce((sum, l) => sum + l.lineNetCents, 0)
	return { netCents: net, taxCents: 0, grossCents: net }
}

/**
 * Liest eine Dezimalzahl in deutscher oder englischer Schreibweise ("1.234,5",
 * "1,5", "2.5"). Gibt null bei unlesbarer Eingabe zurück.
 */
export function parseDecimal(text) {
	if (typeof text === 'number') { return Number.isFinite(text) ? text : null }
	let s = String(text ?? '').trim().replace(/\s/g, '')
	if (s === '') { return null }
	if (s.includes(',')) {
		s = s.replace(/\./g, '').replace(',', '.')
	} else if ((s.match(/\./g) || []).length > 1) {
		s = s.replace(/\./g, '')
	}
	return /^-?\d+(\.\d+)?$/.test(s) ? Number(s) : null
}

/** Menge in Zehntausendstel aus einer Eingabe; null bei unlesbarer. */
export function quantityToE4(text) {
	const n = parseDecimal(text)
	return n === null ? null : Math.round(n * 10000)
}

/** Menge als Text in deutscher Schreibweise ohne überflüssige Nullen: 35000 → "3,5". */
export function formatQuantity(quantityE4) {
	const v = quantityE4 / 10000
	return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 4 }).format(v)
}

/** Rabatt in Basispunkten als Prozentzahl: 1000 → "10". */
export function formatDiscount(discountBp) {
	return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(discountBp / 100)
}

/** Einheitencode (UN/ECE Rec. 20) → Beschriftung für die Auswahl und die Tabelle. */
export function unitLabel(code) {
	return {
		C62: t('Stück'),
		HUR: t('Stunde'),
		DAY: t('Tag'),
		MON: t('Monat'),
		ANN: t('Jahr'),
		LS: t('Pauschale'),
		KMT: t('Kilometer'),
		MTR: t('Meter'),
		MTK: t('Quadratmeter'),
		LTR: t('Liter'),
		KGM: t('Kilogramm'),
		P1: t('Prozent'),
	}[code] || code
}

/** Kurzform für enge Tabellenspalten. */
export function unitShort(code) {
	return {
		C62: t('Stk.'),
		HUR: t('Std.'),
		DAY: t('Tag(e)'),
		MON: t('Monat(e)'),
		ANN: t('Jahr(e)'),
		LS: t('pauschal'),
		KMT: 'km',
		MTR: 'm',
		MTK: 'm²',
		LTR: 'l',
		KGM: 'kg',
		P1: '%',
	}[code] || code
}

export function statusLabel(status) {
	return {
		draft: t('Entwurf'),
		issued: t('Festgeschrieben'),
		sent: t('Versendet'),
		paid: t('Bezahlt'),
		cancelled: t('Storniert'),
	}[status] || status
}

/**
 * Anzeigestatus einer Rechnung. Überfällig ist kein gespeicherter Status,
 * sondern folgt aus Fälligkeit und Rest; ein Teilbetrag zählt als „Teilweise bezahlt“.
 */
export function displayStatus(invoice) {
	if (invoice.kind === 'storno') { return 'credit' }
	if (invoice.status === 'issued' || invoice.status === 'sent') {
		if (invoice.overdue) { return 'overdue' }
		if ((invoice.settledCents || 0) > 0 && (invoice.remainingCents || 0) > 0) { return 'partial' }
	}
	return invoice.status
}

export function displayStatusLabel(invoice) {
	const s = displayStatus(invoice)
	return {
		credit: t('Stornorechnung'),
		overdue: t('Überfällig'),
		partial: t('Teilweise bezahlt'),
	}[s] || statusLabel(s)
}

/** CSS-Klasse für die Statusmarke. */
export function statusClass(invoice) {
	return 'vbh-inv-status vbh-inv-status--' + displayStatus(invoice)
}

export function todayIso() {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Datum (Y-m-d) plus Tage, ohne Zeitzonen-Sprung. */
export function addDays(iso, days) {
	const [y, m, d] = iso.split('-').map(Number)
	const date = new Date(Date.UTC(y, m - 1, d + days))
	return date.toISOString().slice(0, 10)
}

let lineKey = 0

/** Leere Positionszeile des Editors; `key` hält die Zeile beim Umsortieren stabil. */
export function emptyLine(accountId = null) {
	lineKey++
	return {
		key: 'l' + lineKey,
		name: '',
		description: '',
		quantity: '1',
		unitCode: 'C62',
		unitPrice: '',
		discountPercent: '',
		accountId,
	}
}

/** Editor-Zeile aus einer gespeicherten Position. */
export function lineFromServer(line) {
	lineKey++
	return {
		key: 'l' + lineKey,
		name: line.name,
		description: line.description || '',
		quantity: formatQuantity(line.quantityE4),
		unitCode: line.unitCode,
		unitPrice: line.unitPriceCents === 0 ? '' : (line.unitPriceCents / 100),
		discountPercent: line.discountBp > 0 ? formatDiscount(line.discountBp) : '',
		accountId: line.accountId,
	}
}

/** Netto einer Editor-Zeile in Cent; null, solange Menge oder Preis nicht lesbar sind. */
export function editorLineNet(line) {
	const qty = quantityToE4(line.quantity === '' ? 1 : line.quantity)
	const price = line.unitPrice === '' ? 0 : Math.round(Number(line.unitPrice) * 100)
	const discount = line.discountPercent === '' ? 0 : parseDecimal(line.discountPercent)
	if (qty === null || discount === null || !Number.isFinite(price)) { return null }
	return lineNet(qty, price, Math.round(discount * 100))
}

/** Ob eine Editor-Zeile leer ist (wird beim Speichern übergangen). */
export function isEmptyLine(line) {
	return String(line.name).trim() === '' && String(line.description).trim() === '' && (line.unitPrice === '' || Number(line.unitPrice) === 0)
}

/** Positionen für den Server; leere Zeilen entfallen. */
export function linesToPayload(lines) {
	return lines.filter((l) => !isEmptyLine(l)).map((l) => ({
		name: l.name,
		description: l.description,
		quantity: l.quantity === '' ? 1 : l.quantity,
		unitCode: l.unitCode,
		unitPriceCents: l.unitPrice === '' ? 0 : Math.round(Number(l.unitPrice) * 100),
		discountPercent: l.discountPercent === '' ? 0 : l.discountPercent,
		accountId: l.accountId,
	}))
}

/** Summen der Editor-Zeilen; unlesbare Zeilen zählen mit 0 und melden `invalid`. */
export function editorTotals(lines) {
	let invalid = false
	const nets = lines.filter((l) => !isEmptyLine(l)).map((l) => {
		const net = editorLineNet(l)
		if (net === null) { invalid = true }
		return { lineNetCents: net ?? 0 }
	})
	return { ...totals(nets), invalid }
}

/**
 * Rechnungsnummer aus einem Muster (NumberRangeService::format): {JJJJ} Jahr,
 * {JJ} zweistelliges Jahr, {NNNN} Zähler mit führenden Nullen in Breite der N.
 */
export function formatNumberPattern(pattern, date, no) {
	const year = String(date.getFullYear())
	return String(pattern ?? '')
		.replace('{JJJJ}', year)
		.replace('{JJ}', year.slice(2))
		.replace(/\{(N+)\}/, (_m, ns) => String(no).padStart(ns.length, '0'))
}
