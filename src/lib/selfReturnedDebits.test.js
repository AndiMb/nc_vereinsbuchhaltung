import { describe, expect, it } from 'vitest'
import { returnedDebitRows } from './selfReturnedDebits.js'

// Anzeige der eigenen Rücklastschriften in „Mein Beitrag" (Issue #122): der
// Leerfall (dann – und nur dann – steht „Bisher keine Rücklastschrift.” da),
// der gefüllte Fall mit Datum, Betrag, betroffenem Beitrag und Klartext, und die
// Reihenfolge „neueste zuerst”. Die Antwort der API trägt nie einen Rückgabecode
// – die Anzeige zeigt deshalb auch nichts davon.

// Intl setzt vor das Währungszeichen einen geschützten Zwischenraum (siehe
// format.test.js) – \s deckt alle Varianten ab.
const normalize = (s) => s.replace(/\s/g, ' ')

const INSUFFICIENT = 'Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.'
const DISPUTED = 'Die Lastschrift wurde auf Ihren Widerspruch hin von Ihrer Bank zurückgebucht.'

function debit(over = {}) {
	return {
		receivedAt: '2026-10-05',
		amountCents: 1250,
		description: 'Vollmitglied',
		periodStart: '2026-10-01',
		periodEnd: '2026-12-31',
		reason: INSUFFICIENT,
		...over,
	}
}

describe('returnedDebitRows', () => {
	it('ergibt ohne Rücklastschrift eine leere Liste – der Leer-Hinweis hängt daran', () => {
		expect(returnedDebitRows([])).toEqual([])
	})

	it('behandelt eine fehlende Antwort wie eine leere Liste', () => {
		expect(returnedDebitRows(null)).toEqual([])
		expect(returnedDebitRows(undefined)).toEqual([])
	})

	it('zeigt Datum, Betrag, betroffenen Beitrag mit Zeitraum und den Klartext des Grundes', () => {
		const [row, ...rest] = returnedDebitRows([debit()])

		expect(rest).toEqual([])
		expect(row.date).toBe('05.10.2026')
		expect(normalize(row.amount)).toBe('12,50 €')
		expect(row.subject).toBe('Vollmitglied · 01.10.2026–31.12.2026')
		expect(row.reason).toBe(INSUFFICIENT)
	})

	it('führt keine Felder der API durch, die nicht zur Anzeige gehören', () => {
		// Selbst wenn die Antwort je Rückgabecode oder Freitext der Bank mitbrächte: die Zeile gibt sie nicht weiter.
		const [row] = returnedDebitRows([debit({ reasonCode: 'AM04', reasonText: 'Insufficient funds', iban: 'DE02120300000000202051' })])

		expect(Object.keys(row).sort()).toEqual(['amount', 'date', 'reason', 'subject'])
		expect(JSON.stringify(row)).not.toMatch(/AM04|Insufficient|DE02/)
	})

	it('sortiert neueste zuerst, unabhängig von der Reihenfolge der Antwort', () => {
		const rows = returnedDebitRows([
			debit({ receivedAt: '2026-08-12', reason: DISPUTED }),
			debit({ receivedAt: '2026-10-05' }),
			debit({ receivedAt: '2026-09-01' }),
		])

		expect(rows.map((r) => r.date)).toEqual(['05.10.2026', '01.09.2026', '12.08.2026'])
		expect(rows[2].reason).toBe(DISPUTED)
	})

	it('behält bei gleichem Datum die Reihenfolge der API (zuletzt erfasste zuerst)', () => {
		const rows = returnedDebitRows([
			debit({ description: 'Zweite' }),
			debit({ description: 'Erste' }),
		])

		expect(rows.map((r) => r.subject.split(' · ')[0])).toEqual(['Zweite', 'Erste'])
	})

	it('verändert die übergebene Liste nicht', () => {
		const input = [debit({ receivedAt: '2026-01-01' }), debit({ receivedAt: '2026-02-01' })]

		returnedDebitRows(input)

		expect(input.map((d) => d.receivedAt)).toEqual(['2026-01-01', '2026-02-01'])
	})

	it('lässt weg, was fehlt: ohne Bezeichnung nur der Zeitraum, ohne beides keine Zeile „betrifft“', () => {
		const [onlyPeriod, none] = returnedDebitRows([
			debit({ receivedAt: '2026-10-05', description: null }),
			debit({ receivedAt: '2026-09-05', description: null, periodStart: null, periodEnd: null }),
		])

		expect(onlyPeriod.subject).toBe('01.10.2026–31.12.2026')
		expect(none.subject).toBe('')
	})

	it('übernimmt Sonderzeichen der Bezeichnung unverändert (kein HTML-Escaping vorab)', () => {
		const [row] = returnedDebitRows([debit({ description: 'Echo & Söhne', periodStart: null, periodEnd: null })])

		expect(row.subject).toBe('Echo & Söhne')
	})
})
