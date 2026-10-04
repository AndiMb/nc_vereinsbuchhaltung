// Aufgaben/Störfälle (Spec §7, Issue #99): zustandslose Helfer für das
// Aufgaben-Flyout. Der Server liefert je Aufgabe fertigen deutschen Klartext
// (`message`), einen von zwei Schweregraden und ein Fachobjekt
// (`objectType`/`objectId`) samt aufgelöster `memberId` (TaskTargetResolver) -
// hier steckt nur, was die Oberfläche daraus macht: sortieren, zählen,
// einordnen und das Sprungziel bestimmen.
import { t } from './l10n.js'

/** Es besteht Handlungsbedarf - zählt ins Badge. */
export const SEVERITY_ACTION = 'handlungsbedarf'
/** Nur zur Kenntnis - steht im Flyout, zählt aber nicht ins Badge. */
export const SEVERITY_HINT = 'hinweis'

/** Aufgaben mit Handlungsbedarf zuerst, sonst die Reihenfolge des Servers (stabil). */
export function sortTasks(tasks) {
	const rank = (task) => (task.severity === SEVERITY_ACTION ? 0 : 1)
	return tasks
		.map((task, index) => ({ task, index }))
		.sort((a, b) => rank(a.task) - rank(b.task) || a.index - b.index)
		.map(({ task }) => task)
}

/** Anzahl der Aufgaben mit Handlungsbedarf - die Zahl im Badge. */
export function actionCount(tasks) {
	return tasks.filter((task) => task.severity === SEVERITY_ACTION).length
}

/** Anzahl der Hinweise - nur im Flyout sichtbar. */
export function hintCount(tasks) {
	return tasks.length - actionCount(tasks)
}

/**
 * Bereich, dem eine Aufgabe zugeordnet ist, als kurze Marke vor dem Text.
 * Der Server-Text nennt das Objekt selbst nicht immer („Freigabe fällig: Lauf
 * am …"), die Marke macht auf einen Blick klar, wohin die Aufgabe gehört.
 */
export function taskKindLabel(task) {
	// Alles andere sind Läufe und aggregierte Einzug-Aufgaben (objectType null).
	switch (task.objectType) {
		case 'member': return t('Mitglied')
		case 'mandate': return t('Mandat')
		case 'assignment': return t('Zuweisung')
		case 'claim': return t('Forderung')
		// Aggregierte Zeile über mehrere Forderungen („N Forderungen nach Rücklastschrift weiter offen“).
		case 'claims': return t('Forderungen')
		default: return t('Einzug')
	}
}

/**
 * Wohin eine Aufgabe führt - oder null, wo es kein Ziel gibt.
 *
 * - `{ kind: 'member', memberId }`: die Mitglieder-Akte (Mitglied, Mandat,
 *   Zuweisung, Forderung - die Zielansicht für Forderungen und Mandate ist
 *   die Akte des betroffenen Mitglieds)
 * - `{ kind: 'members' }`: die Mitgliederliste (Aufgabe zu einem Mitglied,
 *   das es nicht mehr gibt bzw. ohne Mitglieds-ID, etwa der Hinweis aus der
 *   Datenübernahme)
 * - `{ kind: 'batch' }`: der Einzug-Unterreiter (Läufe, aggregierte
 *   Einzug-Aufgaben, aggregierte Forderungs-Aufgaben (`claims`: Rücklastschrift
 *   ohne Wiedereinzug, Forderungen nach Widerruf), Forderungen ohne
 *   auffindbares Mitglied)
 *
 * Die Mandat-Aufgaben des Katalogs (Entwurf, gesperrt, erloschen, ohne
 * Nachweis, verfällt, ausgetreten) tragen `objectType: 'mandate'` samt
 * `memberId` und springen damit in die Akte.
 */
export function taskTarget(task) {
	const memberId = Number.isInteger(task.memberId) ? task.memberId : null
	// Ein künftiger, noch unbekannter Typ (default): Eintrag ohne Sprung statt
	// eines Sprungs ins Blaue.
	switch (task.objectType) {
		case 'member':
			return memberId ? { kind: 'member', memberId } : { kind: 'members' }
		case 'mandate':
		case 'assignment':
			return memberId ? { kind: 'member', memberId } : null
		case 'claim':
			return memberId ? { kind: 'member', memberId } : { kind: 'batch' }
		case 'debit_batch':
		case 'claims':
		case null:
		case undefined:
			return { kind: 'batch' }
		default:
			return null
	}
}

/** Beschriftung des Sprung-Knopfs je Ziel. */
export function targetLabel(target) {
	switch (target.kind) {
		case 'member': return t('Zur Akte')
		case 'members': return t('Zur Mitgliederliste')
		default: return t('Zum Einzug')
	}
}
