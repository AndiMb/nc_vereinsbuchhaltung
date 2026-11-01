import { describe, expect, it } from 'vitest'
import {
	countByStatus,
	emptyReimbursementForm,
	emptyReimbursementInvoice,
	matchesReimbursementSearch,
	missingReimbursementFields,
	reimbursementBookingPrefill,
	reimbursementEventText,
	reimbursementProgressText,
	reimbursementStatusClass,
	reimbursementStatusLabel,
} from './reimbursements.js'

describe('reimbursements', () => {
	it('benennt jeden Status', () => {
		expect(reimbursementStatusLabel('submitted')).toBe('Eingereicht')
		expect(reimbursementStatusLabel('partially_approved')).toBe('Teilweise freigegeben')
		expect(reimbursementStatusLabel('approved')).toBe('Freigegeben')
		expect(reimbursementStatusLabel('paid')).toBe('Ausgezahlt')
		expect(reimbursementStatusLabel('rejected')).toBe('Abgelehnt')
		expect(reimbursementStatusLabel('withdrawn')).toBe('Zurückgezogen')
	})

	it('färbt erledigte grün und abgelehnte rot', () => {
		expect(reimbursementStatusClass('paid')).toBe('paid')
		expect(reimbursementStatusClass('approved')).toBe('paid')
		expect(reimbursementStatusClass('rejected')).toBe('expense')
		expect(reimbursementStatusClass('submitted')).toBe('')
	})

	it('zeigt den Fortschritt nur, solange er etwas sagt', () => {
		const item = { status: 'partially_approved', requiredApprovals: 2, progress: { approved: 1, required: 2 } }
		expect(reimbursementProgressText(item)).toBe('1 von 2 Freigaben')
		expect(reimbursementProgressText({ ...item, status: 'rejected' })).toBe('')
		expect(reimbursementProgressText({ ...item, status: 'withdrawn' })).toBe('')
	})

	it('nennt in der Historie für Verwaltung Namen, fürs Mitglied nicht', () => {
		const event = { action: 'approved', actorName: 'Vera', actorUid: 'vera', data: { count: 1, required: 2 } }
		expect(reimbursementEventText(event, true)).toBe('Vera hat freigegeben (1 / 2)')
		const forMember = reimbursementEventText({ action: 'approved', data: { count: 1, required: 2 } }, false)
		expect(forMember).toBe('Freigabe durch einen Verwalter (1 / 2)')
		expect(forMember).not.toContain('Vera')
	})

	it('beschreibt Beleg- und Ablehnungsereignisse', () => {
		expect(reimbursementEventText({ action: 'receipt_added', data: { file: 'a.pdf' } }, false)).toBe('Beleg hinzugefügt: a.pdf')
		expect(reimbursementEventText({ action: 'rejected', actorName: 'Vera' }, true)).toBe('Vera hat abgelehnt')
		expect(reimbursementEventText({ action: 'rejected' }, false)).toBe('Antrag abgelehnt')
		expect(reimbursementEventText({ action: 'unbekannt' }, false)).toBe('unbekannt')
	})

	it('zählt Anträge je Status', () => {
		expect(countByStatus([{ status: 'paid' }, { status: 'paid' }, { status: 'submitted' }])).toEqual({ paid: 2, submitted: 1 })
	})

	it('sucht in Zweck, Name, Nummer und Betrag', () => {
		const item = { id: 7, purpose: 'Material Jugendfreizeit', memberName: 'Anna Musterfrau', amountCents: 1250 }
		expect(matchesReimbursementSearch(item, '')).toBe(true)
		expect(matchesReimbursementSearch(item, 'jugend')).toBe(true)
		expect(matchesReimbursementSearch(item, 'muster')).toBe(true)
		expect(matchesReimbursementSearch(item, '12,50')).toBe(true)
		expect(matchesReimbursementSearch(item, '12.5')).toBe(true)
		expect(matchesReimbursementSearch(item, '99')).toBe(false)
		expect(matchesReimbursementSearch(item, 'porto')).toBe(false)
	})

	it('belegt den Buchungsdialog für die Auszahlung vor', () => {
		const item = { id: 7, purpose: 'Material', memberName: 'Anna Musterfrau', amountCents: 1250 }
		expect(reimbursementBookingPrefill(item, '2026-11-02')).toEqual({
			date: '2026-11-02',
			amount: 12.5,
			kind: 'expense',
			description: 'Erstattung Auslage: Material (Anna Musterfrau)',
			documentRef: 'AUSL-7',
		})
	})

	describe('Antragsformular', () => {
		const filled = () => ({
			amount: 12.5,
			expenseDate: '2026-10-15',
			invoiceNumber: '',
			supplier: '',
			purpose: 'Noten',
			accountHolder: 'Anna Musterfrau',
			iban: 'DE89 3704 0044 0532 0130 00',
			files: [{ name: 'beleg.pdf' }],
			declaration: true,
		})

		it('beginnt mit leeren Feldern am angegebenen Tag', () => {
			expect(emptyReimbursementInvoice('2026-11-01')).toEqual({ amount: '', expenseDate: '2026-11-01', invoiceNumber: '', supplier: '', purpose: '', files: [] })
		})

		it('belegt die Kontoverbindung vor und lässt die Erklärung unbestätigt', () => {
			const form = emptyReimbursementForm('2026-11-01', { accountHolder: 'Anna', iban: 'DE89' })
			expect(form).toMatchObject({ expenseDate: '2026-11-01', accountHolder: 'Anna', iban: 'DE89', declaration: false })
			expect(emptyReimbursementForm('2026-11-01')).toMatchObject({ accountHolder: '', iban: '' })
		})

		it('meldet nichts als fehlend, wenn alles ausgefüllt ist', () => {
			expect(missingReimbursementFields(filled())).toEqual([])
		})

		it('nennt alle fehlenden Angaben in der Reihenfolge des Formulars', () => {
			const form = { ...filled(), amount: '', expenseDate: '', purpose: '  ', accountHolder: ' ', iban: '', files: [], declaration: false }
			expect(missingReimbursementFields(form)).toEqual(['Betrag', 'Rechnungsdatum', 'Zweck', 'Kontoinhaber', 'IBAN', 'Beleg', 'Erklärung'])
		})

		it('verlangt Rechnungsnummer und Lieferant nicht', () => {
			expect(missingReimbursementFields({ ...filled(), invoiceNumber: '', supplier: '' })).toEqual([])
		})
	})
})
