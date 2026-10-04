import { describe, expect, it } from 'vitest'
import {
	batchStatusLabel,
	batchStatusTone,
	claimStateLabel,
	claimStateTone,
	countIssues,
	daysBetween,
	entryState,
	formatShortDate,
	formatStamp,
	issueSummary,
	liveBatches,
	milestoneLabel,
	monthTicks,
	relativeDays,
	releaseUrgency,
	yearPosition,
} from './debitRun.js'

// Helfer des Einzug-Unterreiters: Klartext-Labels, Zeitstrahl-Rechnerei und
// die Einstufung eines Termins. Die Labels sind Teil der Abnahme (Issue #102:
// „Zustandslabels für Forderungen und Läufe in Klartext“), deshalb stehen die
// erwarteten Texte hier ausgeschrieben.

function entry(over = {}) {
	return {
		dueDate: '2026-11-01',
		intervals: [1],
		milestones: [
			{ key: 'warning', date: '2026-10-11' },
			{ key: 'prenotification', date: '2026-10-18' },
			{ key: 'release', date: '2026-10-27' },
			{ key: 'collection', date: '2026-11-01' },
		],
		preview: { count: 0, sumCents: 0 },
		batches: [],
		...over,
	}
}

describe('Klartext-Labels', () => {
	it('benennt die drei Lauf-Status', () => {
		expect(batchStatusLabel('freigegeben')).toBe('freigegeben')
		expect(batchStatusLabel('eingereicht')).toBe('eingereicht')
		expect(batchStatusLabel('verworfen')).toBe('verworfen')
	})

	it('gibt einem unbekannten Status seinen Rohwert zurück statt nichts', () => {
		expect(batchStatusLabel('irgendwas')).toBe('irgendwas')
	})

	it('benennt die fünf Forderungszustände der Spec ohne Codes', () => {
		expect(claimStateLabel('offen')).toBe('offen')
		expect(claimStateLabel('im_einzug')).toBe('im Einzug')
		expect(claimStateLabel('eingezogen')).toBe('eingezogen')
		expect(claimStateLabel('zurueckgegeben')).toBe('zurückgegeben')
		expect(claimStateLabel('storniert')).toBe('storniert')
	})

	it('unterscheidet beim Erledigungsvermerk bezahlt von erlassen', () => {
		expect(claimStateLabel('erledigt', 'paid')).toBe('erledigt (bezahlt)')
		expect(claimStateLabel('erledigt', 'waived')).toBe('erledigt (erlassen)')
	})

	it('wählt Farbtöne, die eine Rückgabe hervorheben und Storno/Erlass zurücktreten lassen', () => {
		expect(claimStateTone('zurueckgegeben')).toBe('error')
		expect(claimStateTone('eingezogen')).toBe('success')
		expect(claimStateTone('storniert')).toBe('neutral')
		expect(claimStateTone('erledigt', 'waived')).toBe('neutral')
		expect(claimStateTone('erledigt', 'paid')).toBe('success')
		expect(batchStatusTone('verworfen')).toBe('neutral')
		expect(batchStatusTone('eingereicht')).toBe('success')
	})

	it('benennt die vier Meilensteine', () => {
		expect(milestoneLabel('warning')).toBe('Vorwarnung')
		expect(milestoneLabel('prenotification')).toBe('Vorabinfo')
		expect(milestoneLabel('release')).toBe('Freigabe-Vorlauf')
		expect(milestoneLabel('collection')).toBe('Einzug')
	})
})

describe('Datum und Zeit', () => {
	it('zählt ganze Tage, auch über Monats- und Jahresgrenzen und Zeitumstellung', () => {
		expect(daysBetween('2026-10-04', '2026-10-05')).toBe(1)
		expect(daysBetween('2026-12-30', '2027-01-02')).toBe(3)
		expect(daysBetween('2026-03-28', '2026-03-30')).toBe(2) // Sommerzeit-Umstellung
		expect(daysBetween('2026-10-05', '2026-10-04')).toBe(-1)
	})

	it('beschreibt Tage relativ zum Stichtag', () => {
		expect(relativeDays('2026-10-04', '2026-10-04')).toBe('heute')
		expect(relativeDays('2026-10-05', '2026-10-04')).toBe('morgen')
		expect(relativeDays('2026-10-03', '2026-10-04')).toBe('gestern')
		expect(relativeDays('2026-10-09', '2026-10-04')).toBe('in 5 Tagen')
		expect(relativeDays('2026-10-01', '2026-10-04')).toBe('vor 3 Tagen')
	})

	it('formatiert Zeitpunkte mit und ohne Uhrzeit', () => {
		expect(formatStamp('2026-10-04 12:30:15')).toBe('04.10.2026 12:30')
		expect(formatStamp('2026-10-04T12:30:15+00:00')).toBe('04.10.2026 12:30')
		expect(formatStamp('2026-10-04')).toBe('04.10.2026')
		expect(formatStamp(null)).toBe('')
	})

	it('kürzt ein Datum für den Zeitstrahl', () => {
		expect(formatShortDate('2026-11-01')).toBe('01.11.')
	})
})

describe('Zeitstrahl', () => {
	it('rechnet die Position im Beitragsjahr in Prozent', () => {
		expect(yearPosition('2026-01-01', '2026-01-01', '2026-12-31')).toBe(0)
		expect(yearPosition('2026-12-31', '2026-01-01', '2026-12-31')).toBe(100)
		expect(yearPosition('2026-07-02', '2026-01-01', '2026-12-31')).toBeCloseTo(50, 0)
	})

	it('klemmt Daten außerhalb des Jahres an den Rand', () => {
		expect(yearPosition('2025-12-10', '2026-01-01', '2026-12-31')).toBe(0)
		expect(yearPosition('2027-02-01', '2026-01-01', '2026-12-31')).toBe(100)
	})

	it('liefert zwölf Monatsmarken für ein Kalenderjahr und beginnt bei null', () => {
		const ticks = monthTicks('2026-01-01', '2026-12-31')
		expect(ticks).toHaveLength(12)
		expect(ticks[0].position).toBe(0)
		expect(ticks[0].date).toBe('2026-01-01')
		expect(ticks[11].date).toBe('2026-12-01')
	})

	it('liefert auch für ein versetztes Beitragsjahr zwölf Monatsmarken', () => {
		const ticks = monthTicks('2026-10-01', '2027-09-30')
		expect(ticks).toHaveLength(12)
		expect(ticks[0].date).toBe('2026-10-01')
		expect(ticks[3].date).toBe('2027-01-01')
	})
})

describe('Einstufung eines Termins', () => {
	it('leer: nichts da', () => {
		expect(entryState(entry())).toBe('leer')
	})

	it('offen: freizugebende Forderungen zählen vor allem anderen', () => {
		expect(entryState(entry({ preview: { count: 2, sumCents: 100 } }))).toBe('offen')
		expect(entryState(entry({
			preview: { count: 1, sumCents: 100 },
			batches: [{ id: 1, status: 'eingereicht', itemCount: 3, sumCents: 300 }],
		}))).toBe('offen')
	})

	it('eingereicht schlägt freigegeben', () => {
		expect(entryState(entry({ batches: [{ id: 1, status: 'freigegeben' }, { id: 2, status: 'eingereicht' }] }))).toBe('eingereicht')
		expect(entryState(entry({ batches: [{ id: 1, status: 'freigegeben' }] }))).toBe('freigegeben')
	})

	it('verworfen: nur verworfene Läufe, die Historie bleibt erkennbar', () => {
		expect(entryState(entry({ batches: [{ id: 1, status: 'verworfen' }] }))).toBe('verworfen')
		expect(liveBatches(entry({ batches: [{ id: 1, status: 'verworfen' }, { id: 2, status: 'freigegeben' }] }))).toHaveLength(1)
	})
})

describe('Dringlichkeit der Freigabe', () => {
	const open = entry({ preview: { count: 3, sumCents: 4500 } })

	it('ist ohne freizugebende Forderungen nie dringend', () => {
		expect(releaseUrgency(entry(), '2026-12-01')).toBe('none')
	})

	it('steigt mit dem Stichtag: none, due, overdue, late', () => {
		expect(releaseUrgency(open, '2026-10-26')).toBe('none')
		expect(releaseUrgency(open, '2026-10-27')).toBe('due')
		expect(releaseUrgency(open, '2026-10-28')).toBe('overdue')
		expect(releaseUrgency(open, '2026-11-01')).toBe('overdue') // am Einzugstag selbst noch nicht „verstrichen“
		expect(releaseUrgency(open, '2026-11-02')).toBe('late')
	})
})

describe('Störfälle', () => {
	const issues = [
		{ severity: 'handlungsbedarf', message: 'a' },
		{ severity: 'handlungsbedarf', message: 'b' },
		{ severity: 'hinweis', message: 'c' },
	]

	it('zählt je Schweregrad', () => {
		expect(countIssues(issues)).toEqual({ actionRequired: 2, hint: 1 })
		expect(countIssues(undefined)).toEqual({ actionRequired: 0, hint: 0 })
	})

	it('fasst sie in Klartext zusammen', () => {
		expect(issueSummary(issues)).toBe('2 Handlungsbedarf, 1 Hinweis')
		expect(issueSummary([issues[2], issues[2]])).toBe('2 Hinweise')
		expect(issueSummary([])).toBe('keine')
	})
})
