import { describe, expect, it } from 'vitest'
import {
	accountLabel,
	blockerText,
	bookingRows,
	describeSettleError,
	detailStatusLabel,
	kindLabel,
	lineRoleLabel,
	pendingCount,
	progressText,
	returnConsequences,
	returnReasonLabel,
	settleHint,
	stageIsWeak,
	stageReason,
	summarize,
	takenItemIds,
	txStateLabel,
	unambiguousOpenDetails,
} from './bankReconciliation.js'

// Helfer des Segments „Bankabgleich“ (Issue #105): Fortschritt eines Sammlers,
// Klartext für Begründungen und Rückgabegründe, automatische Folgen einer
// Rücklastschrift, Zeilen der Buchungsvorschau und verständliche Fehler. Die
// Texte sind Teil der Abnahme, deshalb stehen die erwarteten Wörter hier
// ausgeschrieben.

function detail(status, candidates = [], over = {}) {
	return { id: 1, status, candidates, ...over }
}

function candidate(stage = 1, over = {}) {
	return { debitItemId: 10, stage, memberName: 'Anna Muster', ...over }
}

describe('Fortschritt eines Umsatzes', () => {
	it('zählt beurteilte Zeilen – zugeordnet, abgelehnt und nicht zuordenbar sind Urteile', () => {
		const entry = { details: [detail('zugeordnet'), detail('abgelehnt'), detail('nicht_zuordenbar'), detail('offen')] }

		expect(summarize(entry)).toEqual({ total: 4, judged: 3, assigned: 1, state: 'offen' })
	})

	it('wartet auf ein Urteil, solange eine Zeile offen ist, auch wenn die übrigen zugeordnet sind', () => {
		expect(summarize({ details: [detail('zugeordnet'), detail('offen')] }).state).toBe('offen')
	})

	it('ist bereit zum Verbuchen, sobald alle beurteilt sind und mindestens eine zugeordnet ist', () => {
		expect(summarize({ details: [detail('zugeordnet'), detail('abgelehnt')] }).state).toBe('bereit')
	})

	it('hat ohne jede Zuordnung nichts zu verbuchen', () => {
		expect(summarize({ details: [detail('abgelehnt'), detail('nicht_zuordenbar')] }).state).toBe('ohne_zuordnung')
	})

	it('nennt den Fortschritt wie das Ticket „4 von 12 beurteilt“', () => {
		expect(progressText({ total: 12, judged: 4 })).toBe('4 von 12 beurteilt')
	})

	it('erklärt, warum Verbuchen noch nicht geht – und schweigt, wenn es geht', () => {
		const open = summarize({ details: [detail('zugeordnet'), detail('offen'), detail('offen')] })
		expect(settleHint(open)).toBe('Verbuchen ist erst möglich, wenn alle 3 Zeilen beurteilt sind – noch 2 offen.')
		expect(settleHint(summarize({ details: [detail('zugeordnet')] }))).toBe('')
		expect(settleHint(summarize({ details: [detail('abgelehnt')] }))).toContain('nichts zu verbuchen')
	})

	it('zählt nur Umsätze, die noch etwas von jemandem wollen', () => {
		const items = [
			{ details: [detail('offen')] },
			{ details: [detail('zugeordnet')] },
			{ details: [detail('abgelehnt')] },
		]

		expect(pendingCount(items)).toBe(2)
	})
})

describe('Eindeutige Vorschläge', () => {
	it('nimmt Zeilen mit genau einem starken Kandidaten und ohne Urteil', () => {
		const strong = detail('offen', [candidate(1)], { id: 1 })
		const mandate = detail('offen', [candidate(2, { debitItemId: 11 })], { id: 2 })

		const result = unambiguousOpenDetails({ details: [strong, mandate] })

		expect(result.map((r) => r.detail.id)).toEqual([1, 2])
		expect(result[0].candidate.debitItemId).toBe(10)
	})

	it('lässt Mehrdeutiges aus: bei mehreren Kandidaten entscheidet ein Mensch, keiner ist vorausgewählt', () => {
		const ambiguous = detail('offen', [candidate(2, { debitItemId: 10 }), candidate(2, { debitItemId: 11 })])

		expect(unambiguousOpenDetails({ details: [ambiguous] })).toEqual([])
	})

	it('lässt den schwächsten Treffer (Betrag und IBAN) aus', () => {
		expect(unambiguousOpenDetails({ details: [detail('offen', [candidate(3)])] })).toEqual([])
	})

	it('lässt schon beurteilte Zeilen und Zeilen ohne Kandidaten aus', () => {
		const decided = detail('abgelehnt', [candidate(1)])
		const none = detail('offen', [])

		expect(unambiguousOpenDetails({ details: [decided, none] })).toEqual([])
	})
})

describe('Ein Posten gehört zu höchstens einer Zeile', () => {
	it('nennt die Posten, die eine andere Zeile derselben Richtung schon hat', () => {
		const mine = detail('offen', [], { id: 1, isReturn: false })
		const other = detail('zugeordnet', [], { id: 2, isReturn: false, debitItemId: 10 })
		const returned = detail('zugeordnet', [], { id: 3, isReturn: true, debitItemId: 11 })
		const rejected = detail('abgelehnt', [], { id: 4, isReturn: false, debitItemId: null })
		const entry = { details: [mine, other, returned, rejected] }

		expect(takenItemIds(entry, mine)).toEqual([10])
	})

	it('zählt die eigene Zuordnung der Zeile nicht als „schon vergeben“', () => {
		const mine = detail('zugeordnet', [], { id: 1, isReturn: false, debitItemId: 10 })

		expect(takenItemIds({ details: [mine] }, mine)).toEqual([])
	})

	it('hält einen Kandidaten nicht für eindeutig, den eine andere Zeile schon hat', () => {
		const taken = detail('zugeordnet', [], { id: 1, debitItemId: 10 })
		const open = detail('offen', [candidate(1, { debitItemId: 10 })], { id: 2 })

		expect(unambiguousOpenDetails({ details: [taken, open] })).toEqual([])
	})

	it('hält zwei offene Zeilen mit demselben einzigen Kandidaten beide nicht für eindeutig', () => {
		const first = detail('offen', [candidate(2, { debitItemId: 10 })], { id: 1 })
		const second = detail('offen', [candidate(2, { debitItemId: 10 })], { id: 2 })
		const third = detail('offen', [candidate(1, { debitItemId: 11 })], { id: 3 })

		const result = unambiguousOpenDetails({ details: [first, second, third] })

		expect(result.map((r) => r.detail.id)).toEqual([3])
	})
})

describe('Klartext', () => {
	it('begründet Vorschläge je Stufe wörtlich wie im Ticket, ohne Zahl', () => {
		expect(stageReason(1)).toBe('Gleiche End-to-End-ID')
		expect(stageReason(2)).toBe('Gleiche Mandatsreferenz und gleicher Betrag')
		expect(stageReason(3)).toBe('Gleicher Betrag und gleiche Zahler-IBAN')
		expect(stageReason(1)).not.toMatch(/\d\s?%|\d,\d/)
	})

	it('hält nur die schwächste Stufe für heikel', () => {
		expect([1, 2, 3].map(stageIsWeak)).toEqual([false, false, true])
	})

	it('nennt Art und Zustand eines Umsatzes', () => {
		expect(kindLabel('collection')).toBe('Einzugsgutschrift')
		expect(kindLabel('return')).toBe('Rücklastschrift')
		expect(txStateLabel('offen')).toBe('wartet auf Urteil')
		expect(txStateLabel('bereit')).toBe('bereit zum Verbuchen')
		expect(detailStatusLabel('nicht_zuordenbar')).toBe('nicht zuordenbar')
		expect(detailStatusLabel('offen')).toBe('noch nicht beurteilt')
	})

	it('beschreibt jeden Rückgabegrund in Klartext, nie als Code', () => {
		for (const reasonClass of ['insufficient_funds', 'account_unusable', 'disputed', 'deceased', 'technical', 'unknown']) {
			const text = returnReasonLabel(reasonClass)
			expect(text).not.toMatch(/\b[A-Z]{2}\d{2}\b/)
			expect(text.length).toBeGreaterThan(10)
		}
		expect(returnReasonLabel('insufficient_funds')).toContain('Kontodeckung')
		// Eine künftige Klasse, die der Client noch nicht kennt, fällt auf „unbekannt“, nicht auf einen leeren Text.
		expect(returnReasonLabel('etwas_neues')).toContain('unbekannt')
	})
})

describe('Folgen einer Rücklastschrift', () => {
	it('nennt immer, dass die Forderung wieder offen wird – und sonst nichts, wenn nichts automatisch geschieht', () => {
		const lines = returnConsequences({ suspendsMandate: false, paymentRequest: false, feeClaimCents: null })

		expect(lines).toHaveLength(1)
		expect(lines[0]).toContain('wieder offen')
	})

	it('nennt Sperre, Zahlungsaufforderung und Gebühren-Forderung mit Betrag', () => {
		const lines = returnConsequences({ suspendsMandate: true, paymentRequest: true, feeClaimCents: 350 })

		expect(lines).toHaveLength(4)
		expect(lines[1]).toContain('Mandat wird gesperrt')
		expect(lines[2]).toContain('Zahlungsaufforderung')
		expect(lines[3]).toMatch(/3,50\s*€/)
		expect(lines[3]).toContain('Gebühren-Forderung')
	})
})

describe('Buchungsvorschau', () => {
	const bank = { accountId: 1, number: '1200', name: 'Bank' }

	it('bucht eine Sammelgutschrift: Bank im Soll, Erlöskonten im Haben mit Zahl der Posten', () => {
		const preview = {
			direction: 'collection',
			amountCents: 9000,
			bank,
			lines: [
				{ accountId: 42, number: '4000', name: 'Mitgliedsbeiträge', side: 'haben', role: 'revenue', amountCents: 6000 },
				{ accountId: 43, number: '4100', name: 'Spenden', side: 'haben', role: 'revenue', amountCents: 3000 },
			],
			rows: [
				{ account: { accountId: 42 } },
				{ account: { accountId: 42 } },
				{ account: { accountId: 43 } },
			],
		}

		expect(bookingRows(preview)).toEqual([
			{ side: 'soll', account: '1200 Bank', amountCents: 9000, role: 'bank', count: null },
			{ side: 'haben', account: '4000 Mitgliedsbeiträge', amountCents: 6000, role: 'revenue', count: 2 },
			{ side: 'haben', account: '4100 Spenden', amountCents: 3000, role: 'revenue', count: 1 },
		])
	})

	it('bucht eine Rücklastschrift mit zwei Gegenkonto-Zeilen im Soll, die Bank im Haben', () => {
		const preview = {
			direction: 'return',
			amountCents: 5000,
			bank,
			lines: [
				{ accountId: 42, number: '4000', name: 'Mitgliedsbeiträge', side: 'soll', role: 'revenue_back', amountCents: 4500 },
				{ accountId: 99, number: '6800', name: 'Bankgebühren', side: 'soll', role: 'fee', amountCents: 500 },
			],
			rows: [],
		}

		const rows = bookingRows(preview)

		expect(rows.map((r) => [r.side, r.role, r.amountCents])).toEqual([
			['soll', 'revenue_back', 4500],
			['soll', 'fee', 500],
			['haben', 'bank', 5000],
		])
		expect(rows[2].account).toBe('1200 Bank')
	})

	it('zeigt ohne Richtung (Hindernis vor der Berechnung) keine Zeilen', () => {
		expect(bookingRows({ direction: null, lines: [] })).toEqual([])
		expect(bookingRows(null)).toEqual([])
	})

	it('nennt ein Konto mit Nummer und Name und kommt mit fehlenden Teilen klar', () => {
		expect(accountLabel({ number: '4000', name: 'Beiträge' })).toBe('4000 Beiträge')
		expect(accountLabel({ number: '', name: 'Nur Name' })).toBe('Nur Name')
		expect(accountLabel(null)).toBe('')
	})

	it('benennt die Rolle einer Gegenkonto-Zeile', () => {
		expect(lineRoleLabel('revenue_back')).toBe('Erlös zurück')
		expect(lineRoleLabel('fee')).toBe('Bankgebühr')
		expect(lineRoleLabel('bank')).toBe('')
	})
})

describe('Hindernisse und Fehler', () => {
	it('erklärt eine geschlossene Periode mit Datum und dem nächsten Schritt', () => {
		const text = blockerText({ code: 'period_closed', message: 'Das Geschäftsjahr 2025 ist abgeschlossen.' }, { bookingDate: '2025-03-01' })

		expect(text).toContain('01.03.2025')
		expect(text).toContain('abgeschlossenen Geschäftsjahr')
		expect(text).toContain('Verwalter')
		expect(text).toContain('wiedereröffnen')
	})

	it('reicht andere Hindernisse mit dem Text der App durch', () => {
		expect(blockerText({ code: 'sum_mismatch', message: 'Die zugeordneten Posten ergeben 45,00 €.' }, {})).toBe('Die zugeordneten Posten ergeben 45,00 €.')
	})

	it('übersetzt eine 423 in den Hinweis auf das abgeschlossene Geschäftsjahr, nicht in den Rohtext', () => {
		const error = { response: { status: 423, data: { message: 'Das Geschäftsjahr 2025 ist abgeschlossen.' } } }

		const text = describeSettleError(error, 'Verbuchen fehlgeschlagen')

		expect(text).toContain('abgeschlossenen Geschäftsjahr')
		expect(text).toContain('wiedereröffnen')
		expect(text).not.toBe('Verbuchen fehlgeschlagen')
	})

	it('reicht die Meldung der App bei einer 400 durch', () => {
		const error = { response: { status: 400, data: { message: 'Dieser Bankumsatz ist bereits gebucht.' } } }

		expect(describeSettleError(error, 'Verbuchen fehlgeschlagen')).toBe('Dieser Bankumsatz ist bereits gebucht.')
	})

	it('zeigt bei einem Serverfehler oder ohne Verbindung nur den Rückfalltext – nie technischen Text', () => {
		expect(describeSettleError({ response: { status: 500, data: { message: 'SQLSTATE[HY000]: General error' } } }, 'Verbuchen fehlgeschlagen')).toBe('Verbuchen fehlgeschlagen')
		expect(describeSettleError(new Error('Network Error'), 'Verbuchen fehlgeschlagen')).toBe('Verbuchen fehlgeschlagen')
		expect(describeSettleError(undefined, 'Verbuchen fehlgeschlagen')).toBe('Verbuchen fehlgeschlagen')
	})

	it('sagt bei fehlender Berechtigung und bei einem verschwundenen Eintrag, was zu tun ist', () => {
		expect(describeSettleError({ response: { status: 403 } }, 'x')).toContain('Buchhalter')
		expect(describeSettleError({ response: { status: 404 } }, 'x')).toContain('neu')
	})
})
