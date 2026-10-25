import { describe, expect, it } from 'vitest'
import {
	daysBeforeLabel,
	dueDateLabel,
	exampleDueDate,
	joinOffset,
	leapShiftNote,
	offsetError,
	offsetMeaning,
	periodName,
	periodRange,
	scheduleChanged,
	splitOffset,
	violationMessage,
} from './dueDateSchedule.js'

// Helfer des Terminplans: die Eingabe „[Tage] [nach|vor] Beginn jeder Periode“
// und der Klartext zu dem, was die Vorschau des Servers liefert. Die Texte
// stehen ausgeschrieben, weil sie das Kryptische des alten Formulars
// („Versatz“, „Periodenindex“) ersetzen sollen.

const RANGE = { min: -90, max: 270 }

describe('Eingabe des Einzugstags', () => {
	it('zerlegt einen Versatz in Tage und Richtung, ohne negative Zahl im Feld', () => {
		expect(splitOffset(14)).toEqual({ days: 14, direction: 'after' })
		expect(splitOffset(-5)).toEqual({ days: 5, direction: 'before' })
		expect(splitOffset(0)).toEqual({ days: 0, direction: 'after' })
	})

	it('setzt Tage und Richtung wieder zum Versatz zusammen', () => {
		expect(joinOffset(14, 'after')).toBe(14)
		expect(joinOffset(5, 'before')).toBe(-5)
		// „0 Tage vor“ ist der Periodenbeginn, keine negative Null.
		expect(Object.is(joinOffset(0, 'before'), 0)).toBe(true)
	})

	it('lässt leere, negative und krumme Eingaben nicht durch', () => {
		expect(joinOffset('', 'after')).toBeNull()
		expect(joinOffset(-3, 'after')).toBeNull()
		expect(joinOffset(2.5, 'after')).toBeNull()
	})

	it('meldet Grenzen in Tagen vor und nach Periodenbeginn', () => {
		expect(offsetError(14, RANGE)).toBeNull()
		expect(offsetError(-90, RANGE)).toBeNull()
		expect(offsetError(-91, RANGE)).toBe('Möglich sind bis zu 90 Tage vor und 270 Tage nach Periodenbeginn.')
		expect(offsetError(null, RANGE)).toBe('Bitte eine ganze Zahl von Tagen eingeben.')
	})

	it('nennt den Tag im Monat nur, wo er jeden Monat derselbe ist', () => {
		expect(offsetMeaning(1, 14)).toBe('am 15. jedes Monats')
		expect(offsetMeaning(3, 0)).toBe('am 1. des ersten Periodenmonats')
		expect(offsetMeaning(1, 28)).toBe('')
		expect(offsetMeaning(1, -3)).toBe('')
	})
})

describe('Perioden und Termine', () => {
	it('benennt Perioden nach Turnus, gezählt ab Beginn des Beitragsjahrs', () => {
		expect(periodName(1, 0, '2027-01-01')).toBe('Januar')
		expect(periodName(3, 1, '2027-04-01')).toBe('2. Quartal')
		expect(periodName(6, 0, '2026-10-01')).toBe('1. Halbjahr')
		expect(periodName(4, 2, '2027-09-01')).toBe('3. Periode')
		expect(periodName(12, 0, '2027-01-01')).toBe('Ganzes Beitragsjahr')
	})

	it('zeigt die Monatsspanne, beim monatlichen Turnus keine', () => {
		// Die Kürzel liefert Intl, je nach ICU-Stand „Jan.–März“ oder „Jan–Mär“.
		expect(periodRange('2027-01-01', '2027-03-31')).toMatch(/^Jan\.?–Mär/)
		expect(periodRange('2027-02-01', '2027-02-28')).toBe('')
	})

	it('zeigt den Termin mit Wochentag', () => {
		expect(dueDateLabel('2027-01-15')).toMatch(/^Fr\.? 15\.01\.2027$/)
	})
})

describe('Schaltjahr-Hinweis für einen per Datum gewählten Termin', () => {
	it('schweigt, wenn kein Februar-Ende zwischen Periodenbeginn und Termin liegt', () => {
		expect(leapShiftNote('2027-04-01', '2027-05-02')).toBe('')
		expect(leapShiftNote('2027-01-01', '2027-02-28')).toBe('')
	})

	it('sagt, in welchen Jahren der Tag wandert', () => {
		// 2027: 59 Tage nach dem 1. Januar sind der 1. März, im Schaltjahr der 29. Februar.
		expect(leapShiftNote('2027-01-01', '2027-03-01')).toMatch(/^In Schaltjahren liegt dieser Termin einen Tag früher/)
		expect(leapShiftNote('2028-01-01', '2028-02-29')).toMatch(/^In Jahren ohne 29\. Februar liegt dieser Termin einen Tag später/)
		// Vor Periodenbeginn (1. März) zurückgezählt verschiebt es sich andersherum.
		expect(leapShiftNote('2027-03-01', '2027-02-24')).toMatch(/^In Schaltjahren liegt dieser Termin einen Tag später/)
	})
})

describe('Verstöße aus der Vorschau', () => {
	const periods = [
		{ periodIndex: 0, start: '2027-01-01' },
		{ periodIndex: 1, start: '2027-04-01' },
		{ periodIndex: 2, start: '2027-07-01' },
		{ periodIndex: 3, start: '2027-10-01' },
	]

	it('nennt beim Überholen beide Perioden mit ihrem Termin', () => {
		const violation = { kind: 'overtakes', periodIndex: 2, dueDate: '2027-07-01', previousPeriodIndex: 1, previousDueDate: '2027-07-10' }
		expect(violationMessage(violation, 3, periods)).toBe('3. Quartal: Der Einzug am 01.07.2027 läge nicht nach dem Einzug für 2. Quartal am 10.07.2027.')
	})

	it('nennt beim Termin außerhalb des Beitragsjahrs dessen Grenzen', () => {
		const violation = { kind: 'outsideYear', periodIndex: 0, dueDate: '2026-12-22', yearStart: '2027-01-01', yearEnd: '2027-12-31' }
		expect(violationMessage(violation, 12, [{ periodIndex: 0, start: '2027-01-01' }]))
			.toBe('Ganzes Beitragsjahr: Der Einzug am 22.12.2026 läge außerhalb des Beitragsjahrs (01.01.2027 bis 31.12.2027).')
	})
})

describe('Entwurf und Beispiel', () => {
	it('erkennt Änderungen am Standard und an den Überschreibungen', () => {
		const saved = { defaultOffsetDays: 14, overrides: { 1: 31 } }
		expect(scheduleChanged(saved, 14, { 1: 31 })).toBe(false)
		expect(scheduleChanged(saved, 15, { 1: 31 })).toBe(true)
		expect(scheduleChanged(saved, 14, {})).toBe(true)
		// Eine Überschreibung auf Index 0 kommt aus PHP als JSON-Liste.
		expect(scheduleChanged({ defaultOffsetDays: 0, overrides: [5] }, 0, { 0: 5 })).toBe(false)
	})

	it('nimmt für die Fristen den nächsten Termin ab heute, sonst den ersten', () => {
		expect(exampleDueDate(['2027-04-15', '2027-01-15', '2027-07-15'], '2027-02-01')).toBe('2027-04-15')
		expect(exampleDueDate(['2025-01-15', '2025-04-15'], '2027-02-01')).toBe('2025-01-15')
		expect(exampleDueDate([], '2027-02-01')).toBeNull()
	})

	it('zählt die Frist in Tagen vorher', () => {
		expect(daysBeforeLabel(21)).toBe('21 Tage vorher')
		expect(daysBeforeLabel(1)).toBe('1 Tag vorher')
	})
})
