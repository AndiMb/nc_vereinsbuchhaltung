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

/**
 * Summen einer Rechnung. Ohne Regelbesteuerung wird keine Steuer ausgewiesen;
 * mit ihr rechnet die Steuer je Satz einmal aus der Summe der Nettobeträge
 * dieses Satzes (wie InvoiceCalculator::totals()).
 *
 * @param {Array<{lineNetCents: number, taxCategory?: string, taxRateBp?: number}>} lines
 * @param {boolean} regular Regelbesteuerung
 * @return {{netCents: number, taxCents: number, grossCents: number, taxBreakdown: Array<{rateBp: number, baseCents: number, taxCents: number}>}}
 */
export function totals(lines, regular = false) {
	const net = lines.reduce((sum, l) => sum + l.lineNetCents, 0)
	const groups = new Map()
	if (regular) {
		for (const l of lines) {
			if ((l.taxCategory || 'S') !== 'S') { continue }
			const rate = l.taxRateBp || 0
			groups.set(rate, (groups.get(rate) || 0) + l.lineNetCents)
		}
	}
	const taxBreakdown = [...groups.entries()]
		.sort((a, b) => b[0] - a[0])
		.map(([rateBp, baseCents]) => ({ rateBp, baseCents, taxCents: roundDiv(baseCents * rateBp, 10000) }))
	const tax = taxBreakdown.reduce((sum, g) => sum + g.taxCents, 0)
	return { netCents: net, taxCents: tax, grossCents: net + tax, taxBreakdown }
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

/** Steuerauswahl einer Position bei Regelbesteuerung: Schlüssel → Kategorie und Satz in Basispunkten. */
export const TAX_KEYS = {
	S1900: { category: 'S', rateBp: 1900 },
	S700: { category: 'S', rateBp: 700 },
	Z: { category: 'Z', rateBp: 0 },
	E: { category: 'E', rateBp: 0 },
}

export function taxKeyLabel(key) {
	return { S1900: '19 %', S700: '7 %', Z: t('0 % (Nullsatz)'), E: t('steuerfrei') }[key] || key
}

/** Schlüssel der Steuerauswahl zu Kategorie und Satz einer gespeicherten Position. */
export function taxKeyOf(category, rateBp) {
	if (category === 'E') { return 'E' }
	if (category === 'Z') { return 'Z' }
	return rateBp === 700 ? 'S700' : 'S1900'
}

/**
 * Vorgeschlagene Steuerauswahl zu einem Erlöskonto: die Sphäre entscheidet.
 * Zweckbetrieb und wirtschaftlicher Geschäftsbetrieb nehmen den in den
 * Einstellungen gewählten Satz, alles andere den Satz des wirtschaftlichen
 * Geschäftsbetriebs.
 *
 * @param {{sphere?: string|null}|null} account
 * @param {{zweckbetrieb: number, wirtschaftlich: number}} rates Sätze in Basispunkten
 */
export function suggestTaxKey(account, rates) {
	const rate = account && account.sphere === 'zweckbetrieb' ? rates.zweckbetrieb : rates.wirtschaftlich
	if (rate === 0) { return 'Z' }
	return rate === 700 ? 'S700' : 'S1900'
}

let lineKey = 0

/** Leere Positionszeile des Editors; `key` hält die Zeile beim Umsortieren stabil. */
export function emptyLine(accountId = null, taxKey = 'S1900') {
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
		taxKey,
		// Hat jemand den Satz von Hand gewählt, folgt er dem Konto nicht mehr.
		taxTouched: false,
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
		taxKey: taxKeyOf(line.taxCategory, line.taxRateBp),
		taxTouched: true,
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

/** Positionen für den Server; leere Zeilen entfallen. Die Steuer geht nur bei Regelbesteuerung mit. */
export function linesToPayload(lines, regular = false) {
	return lines.filter((l) => !isEmptyLine(l)).map((l) => ({
		name: l.name,
		description: l.description,
		quantity: l.quantity === '' ? 1 : l.quantity,
		unitCode: l.unitCode,
		unitPriceCents: l.unitPrice === '' ? 0 : Math.round(Number(l.unitPrice) * 100),
		discountPercent: l.discountPercent === '' ? 0 : l.discountPercent,
		accountId: l.accountId,
		...(regular ? { taxCategory: TAX_KEYS[l.taxKey || 'S1900'].category, taxRateBp: TAX_KEYS[l.taxKey || 'S1900'].rateBp } : {}),
	}))
}

/** Summen der Editor-Zeilen; unlesbare Zeilen zählen mit 0 und melden `invalid`. */
export function editorTotals(lines, regular = false) {
	let invalid = false
	const nets = lines.filter((l) => !isEmptyLine(l)).map((l) => {
		const net = editorLineNet(l)
		if (net === null) { invalid = true }
		const tax = TAX_KEYS[l.taxKey || 'S1900']
		return { lineNetCents: net ?? 0, taxCategory: tax.category, taxRateBp: tax.rateBp }
	})
	return { ...totals(nets, regular), invalid }
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

/** Formate der E-Rechnung in der Reihenfolge der Auswahl. */
export const EINVOICE_FORMATS = ['zugferd', 'xrechnung_cii', 'xrechnung_ubl']

/** Dokumentart (`kind` des Dokuments), die ein Format erzeugt. */
export const EINVOICE_KIND = { zugferd: 'zugferd_pdf', xrechnung_cii: 'xrechnung_cii', xrechnung_ubl: 'xrechnung_ubl' }

/** Formate, zu denen die Rechnung noch kein Dokument hat. */
export function missingEInvoiceFormats(documents) {
	const kinds = new Set((documents || []).map((d) => d.kind))
	return EINVOICE_FORMATS.filter((f) => !kinds.has(EINVOICE_KIND[f]))
}

/** Dokumente, die E-Rechnungen sind (nicht das einfache PDF und nicht die Mahnschreiben). */
export function eInvoiceDocuments(documents) {
	const kinds = new Set(Object.values(EINVOICE_KIND))
	return (documents || []).filter((d) => kinds.has(d.kind))
}

/** Bezeichnung der Mahnstufe: 1 Zahlungserinnerung, 2 erste, 3 zweite Mahnung. */
export function dunningLabel(level) {
	return { 1: t('Zahlungserinnerung'), 2: t('1. Mahnung'), 3: t('2. Mahnung') }[level] || ''
}

/** Gibt es für die Rechnung eine fällige Mahnstufe? */
export function isDunningDue(invoice) {
	return Boolean(invoice?.dunning?.due)
}

/** Takte wiederkehrender Rechnungen in Monaten. */
export const PLAN_INTERVALS = [1, 3, 6, 12]

export function planIntervalLabel(months) {
	return { 1: t('monatlich'), 3: t('vierteljährlich'), 6: t('halbjährlich'), 12: t('jährlich') }[months] || t('alle {months} Monate', { months })
}

/**
 * Positionen einer Rechnung in der Form, die der Server für Entwürfe und Pläne annimmt
 * (Menge als Text, Rabatt in Prozent, Steuer als Kategorie und Satz).
 */
export function planLinesFromInvoice(invoice) {
	return (invoice.lines || []).map((l) => ({
		name: l.name,
		description: l.description || '',
		quantity: formatQuantity(l.quantityE4),
		unitCode: l.unitCode,
		unitPriceCents: l.unitPriceCents,
		discountPercent: l.discountBp ? String(l.discountBp / 100).replace('.', ',') : '',
		accountId: l.accountId,
		taxCategory: l.taxCategory,
		taxRateBp: l.taxRateBp,
	}))
}

/** Tage zwischen Rechnungsdatum und Zahlungsziel; null, wenn eines fehlt. */
export function dueDaysOf(invoice) {
	if (!invoice.issueDate || !invoice.dueDate) { return null }
	const [y1, m1, d1] = invoice.issueDate.split('-').map(Number)
	const [y2, m2, d2] = invoice.dueDate.split('-').map(Number)
	return Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / 86400000)
}

/** Zeile des Editors aus einer Positionsvorlage: eine Kopie, die nicht mehr an die Vorlage hängt. */
export function lineFromTemplate(template) {
	const line = emptyLine(template.accountId ?? null, taxKeyOf(template.taxCategory, template.taxRateBp))
	line.name = template.name
	line.description = template.description || ''
	line.unitCode = template.unitCode
	line.unitPrice = template.unitPriceCents === 0 ? '' : template.unitPriceCents / 100
	line.taxTouched = true
	return line
}

/** Editor-Zeile aus einer Position eines Plans (so gespeichert, wie der Editor sie sendet). */
export function lineFromPlan(line) {
	const cents = Number(line.unitPriceCents) || 0
	const row = emptyLine(line.accountId ?? null, taxKeyOf(line.taxCategory || 'S', Number(line.taxRateBp) || 0))
	row.name = line.name || ''
	row.description = line.description || ''
	row.quantity = line.quantity === undefined || line.quantity === null || line.quantity === '' ? '1' : String(line.quantity).replace('.', ',')
	row.unitCode = line.unitCode || 'C62'
	row.unitPrice = cents === 0 ? '' : cents / 100
	row.discountPercent = line.discountPercent ? String(line.discountPercent) : ''
	row.taxTouched = true
	return row
}

/** Name für einen Plan, wenn niemand einen eingibt: Empfänger und erste Position. */
export function defaultPlanName(debtorName, lines) {
	const first = (lines || []).find((l) => (l.name || '').trim() !== '')
	return [debtorName, first ? first.name.trim() : ''].filter(Boolean).join(' – ')
}
