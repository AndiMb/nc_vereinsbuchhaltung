// Zustandslose Helfer des Terminplans (DueDateScheduleSettings.vue,
// DueDateIntervalSchedule.vue): Eingabe „[Tage] [nach|vor] Beginn jeder
// Periode“ und Klartext für Perioden, Termine und Verstöße. Ohne Vue-/DOM-Bezug,
// damit sie ohne laufende Nextcloud-Instanz testbar sind.
//
// Gerechnet und geprüft wird auf dem Server (DueDateScheduleService::preview()):
// er liefert Termine und Verstöße, diese Helfer machen daraus Text.
import { toUtcDay } from './debitRun.js'
import { formatDate } from './format.js'
import { t } from './l10n.js'

/**
 * Tage-Versatz als Eingabe „[Tage] [nach|vor]“ – ohne negative Zahlen im Feld.
 *
 * @param {number} offset Tage-Versatz zum Periodenbeginn
 * @return {{days: number, direction: 'after'|'before'}}
 */
export function splitOffset(offset) {
	const value = Number(offset) || 0
	return { days: Math.abs(value), direction: value < 0 ? 'before' : 'after' }
}

/**
 * Die Eingabe „[Tage] [nach|vor]“ als Tage-Versatz.
 *
 * @param {number|string|null} days Wert aus v-model.number ('' für ein leeres Feld)
 * @param {'after'|'before'} direction nach oder vor Periodenbeginn
 * @return {number|null} null bei leerer, negativer oder nicht ganzer Eingabe
 */
export function joinOffset(days, direction) {
	if (days === '' || days === null || days === undefined) { return null }
	const value = Number(days)
	if (!Number.isInteger(value) || value < 0) { return null }
	return direction === 'before' && value > 0 ? -value : value
}

/**
 * Prüft einen Tage-Versatz gegen die Grenzen des Servers, in den Worten der
 * Eingabe („vor/nach Periodenbeginn“) statt als negative Zahl.
 *
 * @param {number|null} offset Ergebnis von joinOffset()
 * @param {{min: number, max: number}} range Grenzen (DueDateScheduleService::MIN/MAX_OFFSET_DAYS)
 * @return {string|null}
 */
export function offsetError(offset, { min, max }) {
	if (offset === null) {
		return t('Bitte eine ganze Zahl von Tagen eingeben.')
	}
	if (offset < min || offset > max) {
		return t('Möglich sind bis zu {before} Tage vor und {after} Tage nach Periodenbeginn.', { before: -min, after: max })
	}
	return null
}

/**
 * Was der Versatz als Tag heißt (Issue #149): „am 15. jedes Monats“ liest sich
 * leichter als „14 Tage nach Periodenbeginn“. Perioden beginnen immer am
 * Monatsersten, deshalb ergibt Versatz 0–27 in jedem Monat denselben Tag; für
 * alles andere sprechen die Termine in der Periodentabelle.
 *
 * @param {number} intervalMonths Turnus
 * @param {number|null} offset Tage-Versatz
 * @return {string} leer, wenn sich kein fester Tag im Monat ergibt
 */
export function offsetMeaning(intervalMonths, offset) {
	if (!Number.isInteger(offset) || offset < 0 || offset > 27) {
		return ''
	}
	return intervalMonths === 1
		? t('am {day}. jedes Monats', { day: offset + 1 })
		: t('am {day}. des ersten Periodenmonats', { day: offset + 1 })
}

function utcDate(iso) {
	return new Date(toUtcDay(iso))
}

function formatter(language, options) {
	try {
		return new Intl.DateTimeFormat(String(language).replace('_', '-'), { ...options, timeZone: 'UTC' })
	} catch {
		return new Intl.DateTimeFormat('de', { ...options, timeZone: 'UTC' })
	}
}

/**
 * Name einer Periode im Beitragsjahr: der Monat beim monatlichen Turnus,
 * sonst „1. Quartal“, „2. Halbjahr“, „3. Periode“ – gezählt ab Beginn des
 * Beitragsjahrs, nicht ab Januar.
 *
 * @param {number} intervalMonths Turnus
 * @param {number} periodIndex Periodenindex ab 0
 * @param {string} startIso Periodenbeginn „JJJJ-MM-TT“
 * @param {string} language Oberflächensprache für Monatsnamen
 * @return {string}
 */
export function periodName(intervalMonths, periodIndex, startIso, language = 'de') {
	const number = periodIndex + 1
	switch (intervalMonths) {
		case 1: return formatter(language, { month: 'long' }).format(utcDate(startIso))
		case 3: return t('{n}. Quartal', { n: number })
		case 6: return t('{n}. Halbjahr', { n: number })
		case 12: return t('Ganzes Beitragsjahr')
		default: return t('{n}. Periode', { n: number })
	}
}

/**
 * Monatsspanne einer Periode („Jan.–März“); leer beim monatlichen Turnus, dort
 * sagt der Name schon alles.
 *
 * @param {string} startIso Periodenbeginn
 * @param {string} endIso Periodenende
 * @param {string} language Oberflächensprache
 * @return {string}
 */
export function periodRange(startIso, endIso, language = 'de') {
	const month = formatter(language, { month: 'short' })
	const from = month.format(utcDate(startIso))
	const to = month.format(utcDate(endIso))
	return from === to ? '' : `${from}–${to}`
}

/**
 * Einzugstermin mit Wochentag („Fr. 15.01.2027“): ob ein Termin aufs
 * Wochenende fällt, sieht man so ohne Kalender.
 *
 * @param {string} iso Termin „JJJJ-MM-TT“
 * @param {string} language Oberflächensprache für den Wochentag
 * @return {string}
 */
export function dueDateLabel(iso, language = 'de') {
	return `${formatter(language, { weekday: 'short' }).format(utcDate(iso))} ${formatDate(iso)}`
}

function isLeapYear(year) {
	return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
}

/**
 * Hinweis, wenn ein per Datum gewählter Termin in anderen Jahren auf einen
 * anderen Tag fällt. Gespeichert wird der Abstand zum Periodenbeginn in Tagen;
 * liegt zwischen Periodenbeginn und Termin ein Februar-Ende, zählt der
 * 29. Februar je nach Jahr mit oder nicht.
 *
 * @param {string} startIso Periodenbeginn
 * @param {string} dueIso gewählter Termin
 * @return {string} leer, wenn der Tag jedes Jahr derselbe ist
 */
export function leapShiftNote(startIso, dueIso) {
	const later = dueIso > startIso
	const [from, to] = later ? [startIso, dueIso] : [dueIso, startIso]
	for (let year = Number(from.slice(0, 4)); year <= Number(to.slice(0, 4)); year++) {
		const feb28 = `${year}-02-28`
		if (from <= feb28 && to > feb28) {
			const shownLeap = isLeapYear(year)
			// Ab Periodenbeginn vorwärts gezählt verschiebt ein fehlender 29. Februar den Termin nach hinten, rückwärts nach vorn.
			if (shownLeap) {
				return later
					? t('In Jahren ohne 29. Februar liegt dieser Termin einen Tag später: Gespeichert wird der Abstand zum Periodenbeginn.')
					: t('In Jahren ohne 29. Februar liegt dieser Termin einen Tag früher: Gespeichert wird der Abstand zum Periodenbeginn.')
			}
			return later
				? t('In Schaltjahren liegt dieser Termin einen Tag früher: Gespeichert wird der Abstand zum Periodenbeginn.')
				: t('In Schaltjahren liegt dieser Termin einen Tag später: Gespeichert wird der Abstand zum Periodenbeginn.')
		}
	}
	return ''
}

/**
 * Ein Verstoß aus der Vorschau des Servers als Satz, mit den Daten, an denen
 * er sichtbar wird – auch wenn sie in einem anderen Jahr als dem gezeigten
 * liegen (Schaltjahr).
 *
 * @param {object} violation Eintrag aus preview().violations
 * @param {number} intervalMonths Turnus
 * @param {Array<{periodIndex: number, start: string}>} periods gezeigte Perioden (für die Namen)
 * @param {string} language Oberflächensprache
 * @return {string}
 */
export function violationMessage(violation, intervalMonths, periods, language = 'de') {
	// Ein Verstoß nennt nur Periodenindizes, die es im Zyklus gibt – also auch in den gezeigten Perioden.
	const name = (index) => periodName(intervalMonths, index, periods.find((p) => p.periodIndex === index).start, language)
	if (violation.kind === 'outsideYear') {
		return t('{periodName}: Der Einzug am {date} läge außerhalb des Beitragsjahrs ({from} bis {to}).', {
			periodName: name(violation.periodIndex),
			date: formatDate(violation.dueDate),
			from: formatDate(violation.yearStart),
			to: formatDate(violation.yearEnd),
		})
	}
	return t('{periodName}: Der Einzug am {date} läge nicht nach dem Einzug für {previous} am {previousDate}.', {
		periodName: name(violation.periodIndex),
		date: formatDate(violation.dueDate),
		previous: name(violation.previousPeriodIndex),
		previousDate: formatDate(violation.previousDueDate),
	})
}

/**
 * Ob der Entwurf eines Turnus vom gespeicherten Stand abweicht.
 *
 * @param {{defaultOffsetDays: number, overrides: object}} saved gespeicherter Terminplan des Turnus
 * @param {number|null} defaultOffsetDays Entwurf des Standard-Versatzes (null bei ungültiger Eingabe)
 * @param {object} overrides Entwurf der Überschreibungen (Periodenindex => Versatz)
 * @return {boolean}
 */
export function scheduleChanged(saved, defaultOffsetDays, overrides) {
	if (saved.defaultOffsetDays !== defaultOffsetDays) { return true }
	const keys = new Set([...Object.keys(saved.overrides), ...Object.keys(overrides)])
	return [...keys].some((key) => saved.overrides[key] !== overrides[key])
}
