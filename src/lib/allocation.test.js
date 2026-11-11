import { describe, expect, it } from 'vitest'
import {
	allocatedCents,
	allocationError,
	allocationSummary,
	bookingLines,
	carryingLines,
	firstAllocationError,
	followAmount,
	isSingleFull,
	remainderEuro,
	syncAllocations,
	toPayload,
	withAllocations,
} from './allocation.js'

// Regeln fuer die Verteilung einer Buchungszeile auf Kostenstellen (Issue #7).
// Sie entscheiden, ob gespeichert werden darf und was das Backend bekommt.
// Ein falsch gerundeter Rest machte eine korrekte Verteilung unspeicherbar,
// eine verlorene Verteilung beim Kontowechsel fiele erst im Bericht auf.

const SEN = 3
const AJU = 5

describe('allocatedCents / remainderEuro', () => {
	it('summiert in Cent', () => {
		// 33,33 + 33,33 + 33,34 ergibt in Gleitkomma nicht sauber 100
		const allocations = [{ costCenterId: 1, amount: 33.33 }, { costCenterId: 2, amount: 33.33 }, { costCenterId: 3, amount: 33.34 }]
		expect(allocatedCents(allocations)).toBe(10000)
		expect(remainderEuro(100, allocations)).toBe(0)
	})

	it('nennt den Rest bei Teilverteilung', () => {
		expect(remainderEuro(1000, [{ costCenterId: SEN, amount: 600 }])).toBe(400)
	})

	it('nennt einen negativen Rest bei Ueberverteilung', () => {
		expect(remainderEuro(1000, [{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 400.01 }])).toBe(-0.01)
	})

	it('kommt mit leeren und fehlenden Listen zurecht', () => {
		expect(allocatedCents([])).toBe(0)
		expect(allocatedCents(undefined)).toBe(0)
		expect(remainderEuro(50, undefined)).toBe(50)
	})
})

describe('allocationError', () => {
	it('laesst eine leere Liste zu', () => {
		expect(allocationError(1000, [])).toBeNull()
		expect(allocationError(1000, undefined)).toBeNull()
	})

	it('laesst die volle Verteilung zu', () => {
		expect(allocationError(1000, [{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 400 }])).toBeNull()
	})

	it('laesst eine Teilverteilung zu', () => {
		expect(allocationError(1000, [{ costCenterId: SEN, amount: 600 }])).toBeNull()
	})

	it('meldet exceeds bei Ueberverteilung', () => {
		expect(allocationError(1000, [{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 400.01 }])).toBe('exceeds')
	})

	it('meldet duplicate bei doppelter Kostenstelle', () => {
		expect(allocationError(1000, [{ costCenterId: SEN, amount: 100 }, { costCenterId: SEN, amount: 100 }])).toBe('duplicate')
	})

	it('meldet amount bei 0 oder negativem Betrag', () => {
		expect(allocationError(1000, [{ costCenterId: SEN, amount: 0 }])).toBe('amount')
		expect(allocationError(1000, [{ costCenterId: SEN, amount: -5 }])).toBe('amount')
		expect(allocationError(1000, [{ costCenterId: SEN, amount: null }])).toBe('amount')
	})

	it('meldet missing bei Betrag ohne Kostenstelle', () => {
		expect(allocationError(1000, [{ costCenterId: null, amount: 100 }])).toBe('missing')
	})

	it('uebergeht ganz leere Zeilen', () => {
		expect(allocationError(1000, [{ costCenterId: null, amount: null }, { costCenterId: SEN, amount: 100 }])).toBeNull()
	})
})

describe('isSingleFull / followAmount', () => {
	it('erkennt die eine Kostenstelle ueber den ganzen Betrag', () => {
		expect(isSingleFull(1000, [{ costCenterId: SEN, amount: 1000 }])).toBe(true)
		expect(isSingleFull(1000, [{ costCenterId: SEN, amount: 600 }])).toBe(false)
		expect(isSingleFull(1000, [{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 400 }])).toBe(false)
		expect(isSingleFull(1000, [])).toBe(false)
	})

	it('zieht den Einzelbetrag mit', () => {
		expect(followAmount(1000, 1200, [{ costCenterId: SEN, amount: 1000 }])).toEqual([{ costCenterId: SEN, amount: 1200 }])
	})

	it('laesst eine Mehrfachverteilung stehen', () => {
		const allocations = [{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 400 }]
		expect(followAmount(1000, 1200, allocations)).toBe(allocations)
	})

	it('gibt bei unveraendertem Betrag dieselbe Liste zurueck', () => {
		// Eine neue Liste hiesse fuer den Dialog "geaendert" - und fuer den
		// Watcher dort eine Endlosschleife.
		const allocations = [{ costCenterId: SEN, amount: 1000 }]
		expect(followAmount(1000, 1000, allocations)).toBe(allocations)
	})

	it('laesst eine Teilzuordnung stehen', () => {
		const allocations = [{ costCenterId: SEN, amount: 600 }]
		expect(followAmount(1000, 1200, allocations)).toBe(allocations)
	})
})

describe('toPayload', () => {
	it('laesst leere Eintraege weg und rundet auf Cent', () => {
		expect(toPayload([
			{ costCenterId: SEN, amount: 600 },
			{ costCenterId: null, amount: null },
			{ costCenterId: AJU, amount: 0 },
			{ costCenterId: AJU, amount: 33.335 },
		])).toEqual([{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 33.34 }])
	})

	it('liefert fuer nichts eine leere Liste', () => {
		expect(toPayload(undefined)).toEqual([])
	})
})

describe('bookingLines', () => {
	it('einfache Ausgabe: Kategorie im Soll, Geldkonto im Haben', () => {
		expect(bookingLines({ kind: 'expense', categoryId: 5300, moneyAccountId: 1200, amount: 1000 }, 'simple')).toEqual([
			{ accountId: 5300, amount: 1000, side: 'debit' },
			{ accountId: 1200, amount: 1000, side: 'credit' },
		])
	})

	it('einfache Einnahme: Geldkonto im Soll, Kategorie im Haben', () => {
		expect(bookingLines({ kind: 'income', categoryId: 4000, moneyAccountId: 1200, amount: 50 }, 'simple')).toEqual([
			{ accountId: 1200, amount: 50, side: 'debit' },
			{ accountId: 4000, amount: 50, side: 'credit' },
		])
	})

	it('Experten-Modus nimmt Soll und Haben direkt', () => {
		expect(bookingLines({ debitAccountId: 5300, creditAccountId: 5400, amount: 20 }, 'expert')).toEqual([
			{ accountId: 5300, amount: 20, side: 'debit' },
			{ accountId: 5400, amount: 20, side: 'credit' },
		])
	})

	it('Aufteilung: feste Seite ueber den Gesamtbetrag, Gegenkonten mit Teilbetraegen', () => {
		const form = { kind: 'expense', moneyAccountId: 1200, amount: 500, splitMode: true, splitLines: [{ accountId: 5300, amount: 300 }, { accountId: 5400, amount: 200 }] }
		expect(bookingLines(form, 'simple')).toEqual([
			{ accountId: 1200, amount: 500, side: 'credit' },
			{ accountId: 5300, amount: 300, side: 'debit' },
			{ accountId: 5400, amount: 200, side: 'debit' },
		])
	})

	it('Aufteilung im Experten-Modus folgt splitSide', () => {
		const form = { debitAccountId: 1200, amount: 500, splitMode: true, splitSide: 'credit', splitLines: [{ accountId: 4000, amount: 500 }] }
		expect(bookingLines(form, 'expert')).toEqual([
			{ accountId: 1200, amount: 500, side: 'debit' },
			{ accountId: 4000, amount: 500, side: 'credit' },
		])
	})

	it('laesst Zeilen ohne Konto weg', () => {
		expect(bookingLines({ kind: 'expense', categoryId: null, moneyAccountId: 1200, amount: 10 }, 'simple')).toEqual([
			{ accountId: 1200, amount: 10, side: 'credit' },
		])
	})
})

describe('carryingLines', () => {
	const accountsById = { 5300: { type: 'expense' }, 4000: { type: 'income' }, 1200: { type: 'asset', isBank: true } }

	it('behaelt nur Ertrags- und Aufwandskonten', () => {
		const lines = [{ accountId: 5300, amount: 1, side: 'debit' }, { accountId: 1200, amount: 1, side: 'credit' }, { accountId: 4000, amount: 1, side: 'credit' }]
		expect(carryingLines(lines, accountsById).map((l) => l.accountId)).toEqual([5300, 4000])
	})

	it('uebergeht unbekannte Konten', () => {
		expect(carryingLines([{ accountId: 9999, amount: 1, side: 'debit' }], accountsById)).toEqual([])
	})
})

describe('syncAllocations', () => {
	const split = [{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 400 }]

	it('laesst alles stehen, wenn sich nichts aendert', () => {
		const allocations = { 5300: split }
		const lines = [{ accountId: 5300, amount: 1000 }]
		expect(syncAllocations(lines, lines, allocations)).toBe(allocations)
	})

	it('laesst die eine Kostenstelle ueber den ganzen Betrag stehen, wenn sich nichts aendert', () => {
		// Der Fall nach dem Auswaehlen im einfachen Feld: gleiche Zeile, gleicher
		// Betrag. Kaeme hier ein neues Objekt zurueck, schriebe der Dialog es ins
		// Formular, der Watcher liefe erneut - ohne Ende.
		const allocations = { 5300: [{ costCenterId: SEN, amount: 1000 }] }
		const lines = [{ accountId: 5300, amount: 1000 }]
		expect(syncAllocations(lines, [{ accountId: 5300, amount: 1000 }], allocations)).toBe(allocations)
	})

	it('kommt nach einer Aenderung zur Ruhe', () => {
		// Zweimal hintereinander angewendet aendert der zweite Lauf nichts mehr.
		const prev = [{ accountId: 5300, amount: 1000 }]
		const next = [{ accountId: 5300, amount: 1200 }]
		const once = syncAllocations(prev, next, { 5300: [{ costCenterId: SEN, amount: 1000 }] })
		expect(syncAllocations(next, next, once)).toBe(once)
	})

	it('nimmt die Verteilung beim Kontowechsel mit', () => {
		const next = syncAllocations([{ accountId: 5300, amount: 1000 }], [{ accountId: 5400, amount: 1000 }], { 5300: split })
		expect(next).toEqual({ 5400: split })
	})

	it('verwirft die Verteilung, wenn die Zeile keine Kostenstelle mehr traegt', () => {
		// Konto auf ein Bankkonto geaendert: die Zeile faellt aus den tragenden Zeilen
		expect(syncAllocations([{ accountId: 5300, amount: 1000 }], [], { 5300: split })).toEqual({})
	})

	it('zieht die Einzel-Kostenstelle bei geaendertem Betrag mit', () => {
		const next = syncAllocations([{ accountId: 5300, amount: 1000 }], [{ accountId: 5300, amount: 1200 }], { 5300: [{ costCenterId: SEN, amount: 1000 }] })
		expect(next).toEqual({ 5300: [{ costCenterId: SEN, amount: 1200 }] })
	})

	it('laesst die Mehrfachverteilung bei geaendertem Betrag stehen', () => {
		const allocations = { 5300: split }
		expect(syncAllocations([{ accountId: 5300, amount: 1000 }], [{ accountId: 5300, amount: 900 }], allocations)).toBe(allocations)
	})

	it('raet nicht, wenn zwei Konten gleichzeitig wechseln', () => {
		const prev = [{ accountId: 5300, amount: 300 }, { accountId: 5400, amount: 200 }]
		const next = [{ accountId: 5500, amount: 300 }, { accountId: 5600, amount: 200 }]
		expect(syncAllocations(prev, next, { 5300: split })).toEqual({})
	})

	it('behaelt die Verteilung einer Zeile, wenn eine andere dazukommt', () => {
		const allocations = { 5300: split }
		expect(syncAllocations([{ accountId: 5300, amount: 1000 }], [{ accountId: 5300, amount: 1000 }, { accountId: 5400, amount: 50 }], allocations)).toBe(allocations)
	})
})

describe('firstAllocationError', () => {
	it('findet den Fehler der betroffenen Zeile', () => {
		const lines = [{ accountId: 5300, amount: 300 }, { accountId: 5400, amount: 200 }]
		expect(firstAllocationError(lines, { 5400: [{ costCenterId: SEN, amount: 200.01 }] })).toEqual({ accountId: 5400, error: 'exceeds' })
	})

	it('liefert null ohne Fehler', () => {
		expect(firstAllocationError([{ accountId: 5300, amount: 300 }], { 5300: [{ costCenterId: SEN, amount: 300 }] })).toBeNull()
		expect(firstAllocationError([{ accountId: 5300, amount: 300 }], {})).toBeNull()
	})
})

describe('withAllocations', () => {
	it('haengt jeder Zeile ihre Verteilung an, nicht tragenden eine leere', () => {
		const payloadLines = [{ accountId: 5300, debit: 1000, credit: 0 }, { accountId: 1200, debit: 0, credit: 1000 }]
		expect(withAllocations(payloadLines, { 5300: [{ costCenterId: SEN, amount: 600 }], 1200: [{ costCenterId: AJU, amount: 1 }] }, [5300])).toEqual([
			{ accountId: 5300, debit: 1000, credit: 0, allocations: [{ costCenterId: SEN, amount: 600 }] },
			{ accountId: 1200, debit: 0, credit: 1000, allocations: [] },
		])
	})
})

describe('allocationSummary', () => {
	const byId = { [SEN]: { code: 'SEN' }, [AJU]: { code: 'AJU' } }

	it('nennt das eine Kuerzel', () => {
		expect(allocationSummary([{ costCenterId: SEN, amount: 1000 }], byId)).toBe('SEN')
	})

	it('kuerzt mehrere ab', () => {
		expect(allocationSummary([{ costCenterId: SEN, amount: 600 }, { costCenterId: AJU, amount: 400 }], byId)).toBe('SEN +1')
	})

	it('ist leer ohne Verteilung', () => {
		expect(allocationSummary([], byId)).toBe('')
		expect(allocationSummary(undefined, byId)).toBe('')
	})

	it('faellt bei unbekannter Kostenstelle auf die Nummer zurueck', () => {
		expect(allocationSummary([{ costCenterId: 99, amount: 1 }], byId)).toBe('#99')
	})
})
