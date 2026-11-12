import { describe, expect, it } from 'vitest'
import { bookingLink, breakdownRows, isClean, reconciliationCsv } from './reconciliation.js'

const balance = {
	bankOpeningCents: 100000,
	bankClosingCents: 81000,
	appOpeningCents: 100500,
	appClosingCents: 87500,
	diffOpeningCents: 500,
	diffClosingCents: 6500,
	surplusCents: 6000,
	missingCents: -2500,
	shiftedCents: 2500,
	restCents: 0,
	gaps: [],
}

const result = {
	account: { id: 3, number: '1200', name: 'Girokonto' },
	period: { from: '2026-01-02', to: '2026-01-05', statementFrom: '2026-01-02', statementTo: '2026-01-05' },
	surplus: [{ kind: 'booking', journalIds: [12], bankTxId: null, date: '2026-01-02', amountCents: 6000, text: 'Max; "Beitrag"' }],
	missing: [{ date: '2026-01-05', valueDate: '2026-01-05', amountCents: -2500, text: 'Miete\nJanuar', counterparty: 'Stadtwerke' }],
	shifted: [],
	balance,
}

const labels = {
	surplus: 'Zu viel in der App',
	missing: 'Fehlt in der App',
	shifted: 'Zeitlich verschoben',
	balance: 'Saldenprüfung',
	date: 'Datum',
	text: 'Text',
	counterparty: 'Zahlungsbeteiligter',
	amount: 'Betrag',
	kind: 'Art',
	appDate: 'Datum in der App',
	kinds: { booking: 'Buchung ohne Bankumsatz', assigned: 'Zugeordneter Bankumsatz', unassigned: 'Nicht zugeordneter Bankumsatz' },
	breakdown: {
		diffOpening: 'Differenz am Anfang',
		surplus: 'Zu viel in der App',
		missing: 'Fehlt in der App',
		shifted: 'Zeitlich verschoben',
		rest: 'Nicht erklärter Rest',
		diffClosing: 'Differenz am Ende',
	},
}

describe('breakdownRows', () => {
	it('zerlegt die Differenz am Ende; „fehlt“ und „verschoben“ gehen mit umgekehrtem Vorzeichen ein', () => {
		expect(breakdownRows(balance)).toEqual([
			{ key: 'diffOpening', cents: 500 },
			{ key: 'surplus', cents: 6000 },
			{ key: 'missing', cents: 2500 },
			{ key: 'shifted', cents: -2500 },
			{ key: 'diffClosing', cents: 6500 },
		])
	})

	it('die Posten ergeben zusammen die Differenz am Ende', () => {
		const rows = breakdownRows({ ...balance, restCents: -40, diffClosingCents: 6460 })
		const parts = rows.filter((r) => r.key !== 'diffClosing').reduce((sum, r) => sum + r.cents, 0)
		expect(parts).toBe(6460)
	})

	it('nennt „verschoben“ nur, wenn es verschobene Paare gibt', () => {
		expect(breakdownRows({ ...balance, shiftedCents: 0, diffClosingCents: 9000 }).some((r) => r.key === 'shifted')).toBe(false)
	})

	it('nennt den Rest nur, wenn es einen gibt', () => {
		expect(breakdownRows(balance).some((r) => r.key === 'rest')).toBe(false)
		expect(breakdownRows({ ...balance, restCents: -40 })).toContainEqual({ key: 'rest', cents: -40 })
	})

	it('ohne Salden gibt es nichts zu zerlegen', () => {
		expect(breakdownRows(null)).toEqual([])
	})
})

describe('isClean', () => {
	const clean = { surplus: [], missing: [], shifted: [], balance: { ...balance, diffClosingCents: 0 } }

	it('stimmt überein, wenn nichts übrig bleibt und die Salden gleich sind', () => {
		expect(isClean(clean)).toBe(true)
		expect(isClean({ ...clean, balance: null })).toBe(true)
	})

	it('nicht bei offenen Zeilen', () => {
		expect(isClean({ ...clean, surplus: result.surplus })).toBe(false)
		expect(isClean({ ...clean, missing: result.missing })).toBe(false)
		expect(isClean({ ...clean, shifted: [{}] })).toBe(false)
	})

	it('nicht bei abweichendem Endsaldo, auch wenn alle Zeilen passen', () => {
		expect(isClean({ ...clean, balance: { ...balance, diffClosingCents: 500 } })).toBe(false)
	})

	it('ohne Ergebnis nicht', () => {
		expect(isClean(null)).toBe(false)
	})
})

describe('bookingLink', () => {
	it('führt zur Buchungsliste, gefiltert auf Konto und Tag', () => {
		expect(bookingLink(3, { kind: 'booking', date: '2026-01-02' }))
			.toEqual({ name: 'bookings', query: { period: 'all', account: 3, from: '2026-01-02', to: '2026-01-02' } })
		expect(bookingLink(3, { kind: 'assigned', date: '2026-01-05' }).name).toBe('bookings')
	})

	it('führt bei einem nicht zugeordneten Umsatz zu „Zuzuordnen“', () => {
		expect(bookingLink(3, { kind: 'unassigned', date: '2026-01-02' })).toEqual({ name: 'bookings-unassigned' })
	})
})

describe('reconciliationCsv', () => {
	const csv = reconciliationCsv(result, labels)
	const lines = csv.replace(/^\uFEFF/, '').split('\r\n')

	it('beginnt mit BOM, damit Excel die Umlaute liest', () => {
		expect(csv.charCodeAt(0)).toBe(0xFEFF)
	})

	it('schreibt je Liste einen Abschnitt mit Kopfzeile', () => {
		expect(lines).toContain('Zu viel in der App')
		expect(lines).toContain('Datum;Text;Art;Betrag')
		expect(lines).toContain('Fehlt in der App')
		expect(lines).toContain('Datum;Text;Zahlungsbeteiligter;Betrag')
	})

	it('setzt Felder mit Trennzeichen, Anführungszeichen oder Zeilenumbruch in Anführungszeichen', () => {
		expect(csv).toContain('2026-01-02;"Max; ""Beitrag""";Buchung ohne Bankumsatz;60,00')
		expect(csv).toContain('2026-01-05;"Miete\nJanuar";Stadtwerke;-25,00')
	})

	it('entschärft Texte, die eine Tabellenkalkulation als Formel läse – Beträge bleiben Zahlen', () => {
		const evil = reconciliationCsv({
			...result,
			surplus: [],
			missing: [
				{ date: '2026-01-05', valueDate: null, amountCents: -2500, text: '=HYPERLINK("http://x")', counterparty: '@SUM(A1)' },
				{ date: '2026-01-06', valueDate: null, amountCents: 100, text: '+49 30 1234', counterparty: '-Rabatt' },
			],
			balance: null,
		}, labels)
		expect(evil).toContain('2026-01-05;"\'=HYPERLINK(""http://x"")";\'@SUM(A1);-25,00')
		expect(evil).toContain('2026-01-06;\'+49 30 1234;\'-Rabatt;1,00')
	})

	it('lässt leere Abschnitte weg', () => {
		expect(lines).not.toContain('Zeitlich verschoben')
	})

	it('hängt die Zerlegung der Salden an', () => {
		expect(lines).toContain('Saldenprüfung')
		expect(lines).toContain('Differenz am Anfang;5,00')
		expect(lines).toContain('Differenz am Ende;65,00')
	})

	it('kommt ohne Salden und mit verschobenen Paaren zurecht', () => {
		const other = reconciliationCsv({
			...result,
			surplus: [],
			missing: [],
			balance: null,
			shifted: [{
				statement: { date: '2026-01-31', amountCents: 123456, text: 'Zweck', counterparty: 'Wer' },
				app: { kind: 'assigned', date: '2026-02-02', amountCents: 123456, text: 'Wer – Zweck' },
			}],
		}, labels)
		expect(other).toContain('Zeitlich verschoben')
		expect(other).toContain('2026-01-31;Zweck;Wer;2026-02-02;Zugeordneter Bankumsatz;1234,56')
		expect(other).not.toContain('Saldenprüfung')
		expect(other).not.toContain('Zu viel in der App')
	})
})
