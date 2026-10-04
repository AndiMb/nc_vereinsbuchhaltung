// Zustandslose Helfer des Einzug-Unterreiters (Issue #102, Spec §3.5/§6):
// Klartext-Labels für Lauf- und Forderungszustände, Zeitstrahl-Rechnerei
// (Position im Beitragsjahr, Meilenstein-Zeitpunkte) und die Einstufung eines
// Termins. Reine Funktionen ohne Vue-/DOM-Bezug, damit sie sich ohne
// Komponente testen lassen (debitRun.test.js) – dieselbe Arbeitsteilung wie
// bei memberRow.js und format.js.
//
// Alle Daten sind ISO-Strings (JJJJ-MM-TT); der Stichtag („heute") kommt vom
// Server (Antwort des Zeitstrahls), nicht aus dem Browser: dieselbe Uhr wie
// Cron, Aufgabenliste und Vorabinfo-Fristen.
import { formatDate } from './format.js'
import { intervalLabel } from './frequency.js'
import { n, t } from './l10n.js'

export const BATCH_STATUS_RELEASED = 'freigegeben'
export const BATCH_STATUS_SUBMITTED = 'eingereicht'
export const BATCH_STATUS_DISCARDED = 'verworfen'

// --- Klartext-Labels ----------------------------------------------------------

/** Lauf-Status (Spec §3.5: `released` → `submitted` | `discarded`) in Klartext. */
export function batchStatusLabel(status) {
	return {
		[BATCH_STATUS_RELEASED]: t('freigegeben'),
		[BATCH_STATUS_SUBMITTED]: t('eingereicht'),
		[BATCH_STATUS_DISCARDED]: t('verworfen'),
	}[status] || status
}

/** Farbton eines Lauf-Status für DebitStatusTag: `info`, `success` oder `neutral`. */
export function batchStatusTone(status) {
	return {
		[BATCH_STATUS_RELEASED]: 'info',
		[BATCH_STATUS_SUBMITTED]: 'success',
		[BATCH_STATUS_DISCARDED]: 'neutral',
	}[status] || 'neutral'
}

/**
 * Abgeleiteter Forderungszustand (Spec §2.2) in Klartext. Die Zustände kommen
 * fertig vom Server (ClaimStateResolver::resolveForDebitItem). Ein
 * Erledigungsvermerk heißt dort `erledigt`; ob bezahlt oder erlassen steht in
 * `settlementType` – das Label nennt es, weil ein Erlass für eine Kassenprüfung
 * etwas anderes ist als ein eingegangenes Geld.
 */
export function claimStateLabel(state, settlementType = null) {
	if (state === 'erledigt') {
		return settlementType === 'waived' ? t('erledigt (erlassen)') : t('erledigt (bezahlt)')
	}
	return {
		offen: t('offen'),
		im_einzug: t('im Einzug'),
		eingezogen: t('eingezogen'),
		zurueckgegeben: t('zurückgegeben'),
		storniert: t('storniert'),
	}[state] || state
}

/** Farbton eines Forderungszustands: eine Rückgabe fällt auf, ein Storno/Erlass tritt zurück. */
export function claimStateTone(state, settlementType = null) {
	if (state === 'erledigt') { return settlementType === 'waived' ? 'neutral' : 'success' }
	return {
		offen: 'neutral',
		im_einzug: 'info',
		eingezogen: 'success',
		zurueckgegeben: 'error',
		storniert: 'neutral',
	}[state] || 'neutral'
}

/** Meilensteine eines Einzugstermins (Spec §3.5 Zeitachse) – die Schlüssel kommen vom Server. */
export function milestoneLabel(key) {
	return {
		warning: t('Vorwarnung'),
		prenotification: t('Vorabinfo'),
		release: t('Freigabe-Vorlauf'),
		collection: t('Einzug'),
	}[key] || key
}

/** Ein Satz, was an diesem Meilenstein passiert – als Tooltip und für Bildschirmleser. */
export function milestoneExplanation(key, leadDays) {
	return {
		warning: t('Die Aufgabe „Nächster Lauf am …“ erscheint ({tage} Tage vor dem Einzug).', { tage: leadDays.warning }),
		prenotification: t('Die Vorabinfo-Mails gehen raus, die Periode ist ab dann gesperrt ({tage} Tage vor dem Einzug).', { tage: leadDays.prenotification }),
		release: t('Ab hier sollte der Lauf freigegeben und bei der Bank eingereicht sein ({tage} Tage vor dem Einzug).', { tage: leadDays.release }),
		collection: t('Die Bank belastet frühestens an diesem Tag.'),
	}[key] || ''
}

/**
 * Woher ein Termin auf dem Strahl kommt: aus dem Terminplan (dann für welche
 * Turnusse) oder als eigener Termin einer Forderung bzw. eines Laufs.
 */
export function dateSourceText(intervals) {
	if (!intervals?.length) {
		return t('Eigener Termin außerhalb des Terminplans (manuelle Forderung, Prorata-Erstforderung oder verschobener Lauf).')
	}
	return t('Termin aus dem Terminplan für: {turnusse}.', { turnusse: intervals.map(intervalLabel).join(', ') })
}

/** Schweregrad eines Störfalls (Spec §7: zwei Stufen, kein Quittieren). */
export function severityLabel(severity) {
	return severity === 'handlungsbedarf' ? t('Handlungsbedarf') : t('Hinweis')
}

export function severityTone(severity) {
	return severity === 'handlungsbedarf' ? 'error' : 'info'
}

// --- Datum und Zeit -----------------------------------------------------------

function toUtcDay(iso) {
	const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number)
	return Date.UTC(y, m - 1, d)
}

/** Ganze Tage von `from` bis `to` (negativ, wenn `to` davor liegt). */
export function daysBetween(from, to) {
	return Math.round((toUtcDay(to) - toUtcDay(from)) / 86400000)
}

/** „heute“, „morgen“, „in 5 Tagen“, „gestern“, „vor 3 Tagen“ – relativ zum Stichtag. */
export function relativeDays(date, today) {
	const diff = daysBetween(today, date)
	if (diff === 0) { return t('heute') }
	if (diff === 1) { return t('morgen') }
	if (diff === -1) { return t('gestern') }
	return diff > 0 ? t('in {n} Tagen', { n: diff }) : t('vor {n} Tagen', { n: -diff })
}

/** Zeitpunkt aus der Datenbank („2026-10-04 12:30:00“ oder ISO mit T) als „04.10.2026 12:30“. */
export function formatStamp(value) {
	if (!value) { return '' }
	const [date, time] = String(value).replace('T', ' ').split(' ')
	return time ? `${formatDate(date)} ${time.slice(0, 5)}` : formatDate(date)
}

/** „01.11.“ – Kurzform für Beschriftungen am Zeitstrahl. */
export function formatShortDate(iso) {
	const [, m, d] = String(iso).slice(0, 10).split('-')
	return `${d}.${m}.`
}

// --- Zeitstrahl ----------------------------------------------------------------

/**
 * Position eines Datums auf dem Strahl in Prozent (0 = Beginn, 100 = Ende des
 * Beitragsjahres). Außerhalb des Jahres wird auf den Rand geklemmt – ein
 * Meilenstein, der ins Vorjahr fällt (Vorwarnung im Dezember für den Januar-
 * Termin), bleibt so am linken Rand sichtbar statt aus dem Bild zu fallen.
 */
export function yearPosition(date, start, end) {
	const span = daysBetween(start, end)
	if (span <= 0) { return 0 }
	return Math.min(100, Math.max(0, (daysBetween(start, date) / span) * 100))
}

/**
 * Monatsanfänge im Beitragsjahr als Skalenstriche: `[{ date, label, position }]`.
 *
 * @param {string} start erster Tag des Beitragsjahres (JJJJ-MM-TT)
 * @param {string} end letzter Tag des Beitragsjahres (JJJJ-MM-TT)
 * @param {string} language Sprache der Monatsnamen (de, en, de_DE, …) – die
 *                          Oberflächensprache; ein unbrauchbarer Wert fällt auf
 *                          Deutsch (Quellsprache) zurück, wie bei monthOptions()
 */
export function monthTicks(start, end, language = 'de') {
	const ticks = []
	const [sy, sm] = start.split('-').map(Number)
	let format
	try {
		format = new Intl.DateTimeFormat(String(language).replace('_', '-'), { month: 'short', timeZone: 'UTC' })
	} catch {
		format = new Intl.DateTimeFormat('de-DE', { month: 'short', timeZone: 'UTC' })
	}
	for (let i = 0; i < 24; i++) {
		const first = new Date(Date.UTC(sy, sm - 1 + i, 1))
		const iso = first.toISOString().slice(0, 10)
		if (iso > end) { break }
		ticks.push({ date: iso, label: format.format(first).replace('.', ''), position: yearPosition(iso, start, end) })
	}
	return ticks
}

/** Läufe eines Termins, die noch „leben“ (freigegeben/eingereicht, nicht verworfen). */
export function liveBatches(entry) {
	return (entry?.batches || []).filter((b) => b.status !== BATCH_STATUS_DISCARDED)
}

/**
 * Einstufung eines Termins für Marker und Zusammenfassung:
 * `eingereicht` > `freigegeben` > `offen` (es gibt freizugebende Forderungen) >
 * `verworfen` (nur verworfene Läufe) > `leer`. Ein Termin mit lebendem Lauf UND
 * Nachzügler-Forderungen zählt als `offen`: dort ist noch etwas zu tun.
 */
export function entryState(entry) {
	if ((entry?.preview?.count || 0) > 0) { return 'offen' }
	const live = liveBatches(entry)
	if (live.some((b) => b.status === BATCH_STATUS_SUBMITTED)) { return 'eingereicht' }
	if (live.length) { return 'freigegeben' }
	if ((entry?.batches || []).length) { return 'verworfen' }
	return 'leer'
}

export function entryStateLabel(state) {
	return {
		eingereicht: t('Lauf eingereicht'),
		freigegeben: t('Lauf freigegeben'),
		offen: t('Vorschau, noch nicht freigegeben'),
		verworfen: t('Lauf verworfen'),
		leer: t('nichts einzuziehen'),
	}[state] || state
}

/** Der Meilenstein mit diesem Schlüssel, oder null. */
export function milestoneOf(entry, key) {
	return (entry?.milestones || []).find((m) => m.key === key) || null
}

/**
 * Wie dringend ist die Freigabe? (Spec §3.5 „Gerissene Vorlauffrist: blockiert
 * nichts, verschiebt nichts automatisch, verfällt nicht – nur eine
 * eskalierende Aufgabe.“) `none` solange der Freigabe-Vorlauf noch nicht
 * erreicht ist, `due` am Tag selbst, `overdue` danach, `late` nach dem
 * Einzugstermin. Dringend ist nur ein Termin, an dem noch etwas freizugeben
 * ist (Vorschau zählt Forderungen) – ein Termin ohne Forderungen oder mit
 * vollständig gebündeltem Lauf ist es nie.
 */
export function releaseUrgency(entry, today) {
	const release = milestoneOf(entry, 'release')
	if (!release || (entry.preview?.count || 0) === 0) { return 'none' }
	if (today > entry.dueDate) { return 'late' }
	if (today > release.date) { return 'overdue' }
	if (today === release.date) { return 'due' }
	return 'none'
}

/** Anzahl der Störfälle je Schweregrad. */
export function countIssues(issues) {
	const counts = { actionRequired: 0, hint: 0 }
	for (const issue of issues || []) {
		if (issue.severity === 'handlungsbedarf') { counts.actionRequired++ } else { counts.hint++ }
	}
	return counts
}

/** „1 Handlungsbedarf, 2 Hinweise“ oder „keine“. */
export function issueSummary(issues) {
	const { actionRequired, hint } = countIssues(issues)
	const parts = []
	if (actionRequired) { parts.push(t('{n} Handlungsbedarf', { n: actionRequired })) }
	if (hint) { parts.push(n('%n Hinweis', '%n Hinweise', hint)) }
	return parts.length ? parts.join(', ') : t('keine')
}
