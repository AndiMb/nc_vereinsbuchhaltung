import { describe, expect, it, vi } from 'vitest'
import { batchStats, failureMessage, issuableInvoices, printOnlyInvoices, runSequential, sendableInvoices } from './invoiceBatches.js'

const invoices = [
	{ id: 1, batchId: 5, kind: 'rechnung', status: 'draft', grossCents: 1000, settledCents: 0, remainingCents: 0, debtor: { email: 'a@example.org' } },
	{ id: 2, batchId: 5, kind: 'rechnung', status: 'issued', grossCents: 2000, settledCents: 0, remainingCents: 2000, debtor: { email: 'b@example.org' } },
	{ id: 3, batchId: 5, kind: 'rechnung', status: 'issued', grossCents: 3000, settledCents: 1000, remainingCents: 2000, debtor: { email: ' ' } },
	{ id: 4, batchId: 5, kind: 'rechnung', status: 'paid', grossCents: 4000, settledCents: 4000, remainingCents: 0, debtor: null },
	{ id: 5, batchId: 5, kind: 'rechnung', status: 'cancelled', grossCents: 5000, settledCents: 0, remainingCents: 0 },
	{ id: 6, batchId: 5, kind: 'storno', status: 'issued', grossCents: -5000 },
	{ id: 7, batchId: 9, kind: 'rechnung', status: 'draft', grossCents: 7000 },
	{ id: 8, batchId: null, kind: 'rechnung', status: 'draft', grossCents: 8000 },
]

describe('batchStats', () => {
	it('zählt je Status und lässt Stornos aus den Beträgen', () => {
		expect(batchStats(invoices, 5)).toEqual({
			total: 5,
			draft: 1,
			issued: 2,
			sent: 0,
			paid: 1,
			cancelled: 1,
			grossCents: 10000,
			settledCents: 5000,
			remainingCents: 4000,
		})
	})

	it('ein leerer Stapel hat nur Nullen', () => {
		expect(batchStats(invoices, 99).total).toBe(0)
	})
})

describe('Auswahl für die Sammelaktionen', () => {
	it('festschreiben nimmt nur Entwürfe des Stapels', () => {
		expect(issuableInvoices(invoices, 5).map((i) => i.id)).toEqual([1])
	})

	it('senden nimmt festgeschriebene mit Adresse, der Rest muss gedruckt werden', () => {
		expect(sendableInvoices(invoices, 5).map((i) => i.id)).toEqual([2])
		expect(printOnlyInvoices(invoices, 5).map((i) => i.id)).toEqual([3])
	})
})

describe('runSequential', () => {
	it('läuft trotz Fehlern weiter und meldet den Fortschritt', async () => {
		const progress = vi.fn()
		const order = []
		const result = await runSequential([1, 2, 3], async (n) => {
			order.push(n)
			if (n === 2) { throw new Error('kaputt') }
		}, progress)

		expect(order).toEqual([1, 2, 3])
		expect(result.ok).toBe(2)
		expect(result.failed).toEqual([{ item: 2, message: 'kaputt' }])
		expect(progress).toHaveBeenLastCalledWith(3, 3)
	})
})

describe('failureMessage', () => {
	it('hängt fehlende Pflichtangaben an die Meldung', () => {
		const e = { response: { data: { message: 'Die Rechnung ist noch nicht vollständig.', problems: ['Leistungsdatum', 'Steuernummer'] } } }
		expect(failureMessage(e, 'x', 'Es fehlt:')).toBe('Die Rechnung ist noch nicht vollständig. Es fehlt: Leistungsdatum; Steuernummer')
	})

	it('ohne Liste nur die Meldung, ohne Antwort der Rückfalltext oder die Ausnahme', () => {
		expect(failureMessage({ response: { data: { message: 'Gesperrt' } } }, 'x', 'Es fehlt:')).toBe('Gesperrt')
		expect(failureMessage({ response: { data: {} } }, 'Unbekannt', 'Es fehlt:')).toBe('Unbekannt')
		expect(failureMessage(new Error('Netzwerk'), 'Unbekannt', 'Es fehlt:')).toBe('Netzwerk')
	})
})
