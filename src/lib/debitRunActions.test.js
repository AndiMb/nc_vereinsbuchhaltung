import { describe, expect, it } from 'vitest'
import {
	addDays,
	earliestRescheduleDate,
	isLaterDate,
	releaseDateOf,
	runActionsFor,
	submissionUrgency,
	submissionUrgencyText,
} from './debitRunActions.js'

// Regeln der Lauf-Aktionen (Issue #103): der Termin lässt sich nur nach hinten
// verschieben, eine gerissene Vorlauffrist ist nur ein eskalierender Hinweis,
// und je nach Lauf-Status gibt es verschiedene Aktionen („kein Storno nach
// Einreichung“).

const run = (over = {}) => ({ id: 1, dueDate: '2026-11-10', status: 'freigegeben', ...over })

describe('addDays', () => {
	it('rechnet über Monats- und Jahresgrenzen', () => {
		expect(addDays('2026-11-30', 1)).toBe('2026-12-01')
		expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
		expect(addDays('2026-03-01', -1)).toBe('2026-02-28')
		expect(addDays('2028-03-01', -1)).toBe('2028-02-29')
	})

	it('lässt ein Datum mit Uhrzeit auf den Tag zurückfallen', () => {
		expect(addDays('2026-11-10 12:30:00', 2)).toBe('2026-11-12')
	})
})

describe('Terminverschiebung nur nach hinten', () => {
	it('der früheste Termin ist der Tag nach dem bisherigen', () => {
		expect(earliestRescheduleDate('2026-11-10')).toBe('2026-11-11')
		expect(earliestRescheduleDate('2026-12-31')).toBe('2027-01-01')
	})

	it('ein späteres Datum ist zulässig', () => {
		expect(isLaterDate('2026-11-11', '2026-11-10')).toBe(true)
		expect(isLaterDate('2027-01-01', '2026-11-10')).toBe(true)
	})

	it('dasselbe und frühere Daten sind unzulässig', () => {
		expect(isLaterDate('2026-11-10', '2026-11-10')).toBe(false)
		expect(isLaterDate('2026-11-09', '2026-11-10')).toBe(false)
		expect(isLaterDate('2025-12-01', '2026-11-10')).toBe(false)
	})

	it('eine leere oder halbfertige Eingabe ist unzulässig', () => {
		expect(isLaterDate('', '2026-11-10')).toBe(false)
		expect(isLaterDate(null, '2026-11-10')).toBe(false)
		expect(isLaterDate('2026-11', '2026-11-10')).toBe(false)
		expect(isLaterDate('11.11.2026', '2026-11-10')).toBe(false)
	})
})

describe('submissionUrgency', () => {
	// Einzugstermin 10.11., Freigabe-Vorlauf 5 Tage: der Meilenstein liegt am 05.11.
	it('der Freigabe-Termin liegt Vorlauf-Puffer Tage vor dem Einzug', () => {
		expect(releaseDateOf('2026-11-10', 5)).toBe('2026-11-05')
	})

	it('vor dem Freigabe-Vorlauf ist nichts dringend', () => {
		expect(submissionUrgency(run(), '2026-11-04', 5)).toBe('none')
	})

	it('am Tag des Freigabe-Vorlaufs ist die Einreichung fällig', () => {
		expect(submissionUrgency(run(), '2026-11-05', 5)).toBe('due')
	})

	it('danach ist sie überfällig, nach dem Einzugstermin verspätet', () => {
		expect(submissionUrgency(run(), '2026-11-06', 5)).toBe('overdue')
		expect(submissionUrgency(run(), '2026-11-10', 5)).toBe('overdue')
		expect(submissionUrgency(run(), '2026-11-11', 5)).toBe('late')
	})

	it('nur ein freigegebener Lauf ist je dringend', () => {
		expect(submissionUrgency(run({ status: 'eingereicht' }), '2026-12-01', 5)).toBe('none')
		expect(submissionUrgency(run({ status: 'verworfen' }), '2026-12-01', 5)).toBe('none')
		expect(submissionUrgency(null, '2026-12-01', 5)).toBe('none')
	})
})

describe('submissionUrgencyText', () => {
	it('nennt bei jeder Stufe, dass nichts blockiert (außer „fällig“) und steigert sich', () => {
		expect(submissionUrgencyText('none', run(), 5)).toBe('')
		expect(submissionUrgencyText('due', run(), 5)).toBe('Einreichung fällig: Ab heute (05.11.2026) sollte die Datei bei der Bank eingereicht sein.')
		expect(submissionUrgencyText('overdue', run(), 5)).toContain('überfällig seit 05.11.2026')
		expect(submissionUrgencyText('overdue', run(), 5)).toContain('Das blockiert nichts')
		expect(submissionUrgencyText('late', run(), 5)).toContain('Der Einzugstermin ist verstrichen')
		expect(submissionUrgencyText('late', run(), 5)).toContain('Das blockiert nichts')
	})
})

describe('runActionsFor', () => {
	it('ein freigegebener Lauf lässt alle Aktionen zu', () => {
		expect(runActionsFor('freigegeben')).toEqual({ download: true, submit: true, discard: true, reschedule: true })
	})

	it('nach der Einreichung gibt es kein Storno und keine Verschiebung, nur noch den Download', () => {
		expect(runActionsFor('eingereicht')).toEqual({ download: true, submit: false, discard: false, reschedule: false })
	})

	it('ein verworfener Lauf behält nur seine Historie', () => {
		expect(runActionsFor('verworfen')).toEqual({ download: false, submit: false, discard: false, reschedule: false })
	})
})
