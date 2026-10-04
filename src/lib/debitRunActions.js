// Zustandslose Helfer der Lauf-Aktionen im Einzug-Unterreiter (Issue #103,
// Spec §3.5): Freigeben, Einreichen, Verwerfen und Terminverschiebung. Reine
// Funktionen ohne Vue-/DOM-Bezug, damit sich die Regeln (Termin nur nach
// hinten, eskalierender Hinweis bei verspäteter Einreichung) ohne Komponente
// testen lassen – dieselbe Arbeitsteilung wie bei debitRun.js.
//
// Alle Daten sind ISO-Strings (JJJJ-MM-TT); der Stichtag kommt vom Server
// (Antwort des Zeitstrahls), nicht aus dem Browser.
import { BATCH_STATUS_RELEASED, BATCH_STATUS_SUBMITTED } from './debitRun.js'
import { formatDate } from './format.js'
import { t } from './l10n.js'

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

/** `iso` um `days` Kalendertage verschoben (negativ = zurück), als ISO-Datum. */
export function addDays(iso, days) {
	const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number)
	return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10)
}

/**
 * Der früheste Termin, auf den sich ein Lauf verschieben lässt: der Tag nach
 * dem bisherigen. Spec §2.2 „due_date nur nach hinten verschiebbar“ – das
 * Datumsfeld setzt diesen Wert als `min`, die Prüfung unten gilt zusätzlich,
 * weil ein Datumsfeld auch eine getippte frühere Eingabe zulässt.
 */
export function earliestRescheduleDate(dueDate) {
	return addDays(dueDate, 1)
}

/** Ob `newDate` ein gültiges Datum ist, das echt nach `dueDate` liegt (ISO-Strings vergleichen sich lexikographisch). */
export function isLaterDate(newDate, dueDate) {
	return ISO_DATE.test(String(newDate || '')) && String(newDate) > String(dueDate)
}

/** Freigabe-Datum des Laufs (Meilenstein „Freigabe-Vorlauf“): der Einzugstermin minus Vorlauf-Puffer. */
export function releaseDateOf(dueDate, releaseLeadDays) {
	return addDays(dueDate, -releaseLeadDays)
}

/**
 * Wie dringend ist die Einreichung eines freigegebenen Laufs? (Spec §3.5
 * „Gerissene Vorlauffrist: blockiert nichts, verschiebt nichts automatisch,
 * verfällt nicht – nur eine eskalierende Aufgabe“; „D − 5: Freigabe +
 * Einreichung, ein Vorgang“.) `none` bis zum Freigabe-Vorlauf, `due` am Tag
 * selbst, `overdue` danach, `late` nach dem Einzugstermin. Nur ein Lauf im
 * Zustand „freigegeben“ ist je dringend – ein eingereichter oder verworfener
 * hat nichts mehr zu tun.
 */
export function submissionUrgency(run, today, releaseLeadDays) {
	if (run?.status !== BATCH_STATUS_RELEASED) { return 'none' }
	if (today > run.dueDate) { return 'late' }
	const releaseDate = releaseDateOf(run.dueDate, releaseLeadDays)
	if (today > releaseDate) { return 'overdue' }
	if (today === releaseDate) { return 'due' }
	return 'none'
}

/** Der eskalierende Hinweistext zu {@link submissionUrgency}; leer bei `none`. */
export function submissionUrgencyText(urgency, run, releaseLeadDays) {
	const date = formatDate(releaseDateOf(run.dueDate, releaseLeadDays))
	if (urgency === 'due') {
		return t('Einreichung fällig: Ab heute ({datum}) sollte die Datei bei der Bank eingereicht sein.', { datum: date })
	}
	if (urgency === 'overdue') {
		return t('Einreichung überfällig seit {datum}. Das blockiert nichts: Die Datei lässt sich weiterhin einreichen.', { datum: date })
	}
	if (urgency === 'late') {
		return t('Der Einzugstermin ist verstrichen, die Datei ist noch nicht eingereicht. Das blockiert nichts: Sie lässt sich weiterhin einreichen, und bei Bedarf verschieben Sie den Termin nach hinten.')
	}
	return ''
}

/**
 * Welche Aktionen ein Lauf in seinem Zustand zulässt (Spec §3.5: `freigegeben`
 * → `eingereicht` | `verworfen`, beide terminal; „kein Storno nach
 * Einreichung“). Die Oberfläche blendet alles Unzulässige aus, statt es
 * auszugrauen: ein Knopf „Verwerfen“ an einem eingereichten Lauf wäre eine
 * Einladung, die es nicht gibt. Ein verworfener Lauf behält nur seine
 * Historie.
 */
export function runActionsFor(status) {
	return {
		download: status === BATCH_STATUS_RELEASED || status === BATCH_STATUS_SUBMITTED,
		submit: status === BATCH_STATUS_RELEASED,
		discard: status === BATCH_STATUS_RELEASED,
		reschedule: status === BATCH_STATUS_RELEASED,
	}
}
