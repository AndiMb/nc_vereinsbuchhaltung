// Reisekostenabrechnung (Issue #353, Stufe 1): Formularzustand, Prüfung und
// Übertragung der Reiseangaben. Erstattet werden tatsächliche Kosten, die das
// Mitglied je Position als Betrag einträgt; der Server rechnet die Summe nach
// (lib/Service/TravelDetails.php) und hält dieselben Grenzen.
import { formatDate } from './format.js'
import { t } from './l10n.js'
import { newUid } from './rowKeys.js'

export const TRAVEL_MAX_ITEMS = 20

/** Verkehrsmittel in der Reihenfolge der Auswahl. */
export const TRAVEL_TRANSPORTS = ['car', 'public', 'bike', 'other']

export function travelTransportLabel(transport) {
	switch (transport) {
		case 'car': return t('Eigenes Auto')
		case 'public': return t('Bus und Bahn')
		case 'bike': return t('Fahrrad')
		case 'other': return t('Sonstiges')
		default: return ''
	}
}

export function travelItemTypeLabel(type) {
	return type === 'parking' ? t('Parkgebühren') : t('Fahrtkosten')
}

/** Eine neue Position im Formular; `uid` ist nur der Schlüssel der Zeile und geht nicht an den Server. */
export function emptyTravelItem(type = 'travel') {
	return type === 'parking'
		? { uid: newUid(), type: 'parking', note: '', amount: '' }
		: { uid: newUid(), type: 'travel', transport: '', route: '', distanceKm: '', amount: '' }
}

/** Leere Reiseangaben: eine Fahrt ist schon angelegt, das ist der häufigste Fall. */
export function emptyTravel() {
	return { destination: '', start: '', end: '', items: [emptyTravelItem('travel')] }
}

/** Reiseangaben vom Server (Entwurf) ins Formular: Cent werden Euro, null wird leer. */
export function travelToForm(travel) {
	if (!travel) { return emptyTravel() }
	return {
		destination: travel.destination || '',
		start: travel.start || '',
		end: travel.end || '',
		items: (travel.items || []).map((item) => item.type === 'parking'
			? { uid: newUid(), type: 'parking', note: item.note || '', amount: item.amountCents !== null && item.amountCents !== undefined ? item.amountCents / 100 : '' }
			: {
					uid: newUid(),
					type: 'travel',
					transport: item.transport || '',
					route: item.route || '',
					distanceKm: item.distanceKm !== null && item.distanceKm !== undefined ? String(item.distanceKm).replace('.', ',') : '',
					amount: item.amountCents !== null && item.amountCents !== undefined ? item.amountCents / 100 : '',
				}),
	}
}

function cents(amount) {
	const value = Number(amount)
	return amount === '' || amount === null || Number.isNaN(value) ? null : Math.round(value * 100)
}

function kilometres(value) {
	const text = String(value ?? '').trim().replace(',', '.')
	if (text === '') { return null }
	const km = Number(text)
	return Number.isNaN(km) ? text : km
}

function text(value) {
	const trimmed = String(value ?? '').trim()
	return trimmed === '' ? null : trimmed
}

/** Die Reiseangaben, wie der Server sie erwartet (JSON im Feld `travel`). */
export function travelPayload(travel) {
	return {
		destination: text(travel.destination),
		start: travel.start || null,
		end: travel.end || null,
		items: travel.items.map((item) => item.type === 'parking'
			? { type: 'parking', note: text(item.note), amountCents: cents(item.amount) }
			: { type: 'travel', transport: item.transport || null, route: text(item.route), distanceKm: kilometres(item.distanceKm), amountCents: cents(item.amount) }),
	}
}

/**
 * Zeitraum einer Reise zum Anzeigen: „07.11.2026 07:30 – 19:15“, über mehrere Tage
 * „07.11.2026 07:30 – 08.11.2026 18:00“.
 */
export function formatTravelPeriod(start, end) {
	if (!start) { return '' }
	const from = `${formatDate(start.slice(0, 10))} ${start.slice(11, 16)}`
	if (!end) { return from }
	const to = end.slice(0, 10) === start.slice(0, 10) ? end.slice(11, 16) : `${formatDate(end.slice(0, 10))} ${end.slice(11, 16)}`
	return `${from} – ${to}`
}

/** Eine gespeicherte Position als Text: „Eigenes Auto · Vereinsheim – Kassel · 184 km“. */
export function travelItemSummary(item) {
	const parts = []
	if (item.type === 'travel') {
		if (item.transport) { parts.push(travelTransportLabel(item.transport)) }
		if (item.route) { parts.push(item.route) }
		if (item.distanceKm !== null && item.distanceKm !== undefined) {
			parts.push(`${String(item.distanceKm).replace('.', ',')} km`)
		}
	} else if (item.note) {
		parts.push(item.note)
	}
	return parts.join(' · ')
}

/** Summe der Positionen in Cent. */
export function travelTotalCents(travel) {
	return travel.items.reduce((sum, item) => sum + (cents(item.amount) || 0), 0)
}

/** Steht schon etwas in den Reiseangaben? */
export function travelHasContent(travel) {
	return text(travel.destination) !== null
		|| travel.items.some((item) => cents(item.amount) > 0 || text(item.route) !== null || text(item.note) !== null)
}

/** Was in den Reiseangaben zum Einreichen noch fehlt, als Feldnamen. */
export function missingTravelFields(travel, today) {
	const missing = []
	if (text(travel.destination) === null) { missing.push(t('Reiseziel')) }
	if (!travel.start || !travel.end) {
		missing.push(t('Beginn und Ende'))
	} else if (travel.end < travel.start) {
		missing.push(t('Ende nach dem Beginn'))
	} else if (today && travel.start.slice(0, 10) > today) {
		missing.push(t('Reisebeginn nicht in der Zukunft'))
	}
	if (travel.items.length === 0) {
		missing.push(t('Position'))
	} else {
		if (travel.items.some((item) => !(cents(item.amount) > 0))) { missing.push(t('Betrag je Position')) }
		if (travel.items.some((item) => item.type === 'travel' && (!item.transport || text(item.route) === null))) {
			missing.push(t('Verkehrsmittel und Strecke'))
		}
	}
	return missing
}
