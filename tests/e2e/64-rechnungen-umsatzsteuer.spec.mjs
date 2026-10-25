import { test, expect } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Rechnungen bei Regelbesteuerung (Epic #168, Stufe 2): Steuer je Position,
// Summen mit Steuerzeilen, Zahlung auf Erlös- und Umsatzsteuerkonto.

const YEAR = new Date().getFullYear()
const TODAY = `${YEAR}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`

test.describe.serial('Rechnungen mit Umsatzsteuer', () => {
	let bank
	let income
	let vat19
	let vat7
	let debtor
	let invoice

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		bank = await api.accountByNumber(request, BANK_ACCOUNT)
		income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.updateAccount(request, bank.id, { ...bank, iban: BANK_ACCOUNT_IBAN })
		vat19 = await api.createAccount(request, { number: '1776', name: 'Umsatzsteuer 19 %', type: 'liability', category: 'Verbindlichkeiten' })
		vat7 = await api.createAccount(request, { number: '1771', name: 'Umsatzsteuer 7 %', type: 'liability', category: 'Verbindlichkeiten' })
		await api.updateSettings(request, {
			club_name: 'TSV E2E e. V.',
			club_street: 'Hauptstr. 1',
			club_postal_code: '12345',
			club_city: 'Beispielstadt',
			club_tax_number: '12/345/67890',
			invoice_module_active: '1',
			invoice_account_id: bank.id,
			invoice_tax_mode: 'regular',
			invoice_default_exemption_text: 'Steuerfrei nach § 4 Nr. 12 UStG',
		})
		debtor = await (await api.raw(request, 'POST', '/debtors', { data: { kind: 'organisation', name: 'Steuer E2E GmbH', street: 'Weg 2', postalCode: '54321', city: 'Musterstadt' } })).json()
	})

	test('Ein Geldkonto ist als Umsatzsteuerkonto nicht erlaubt', async ({ request }) => {
		const rejected = await api.raw(request, 'POST', '/settings', { data: { invoice_vat_account_19: bank.id } })
		expect(rejected.status()).toBe(400)
	})

	test('Ohne Umsatzsteuerkonto lässt sich eine Rechnung mit Steuer nicht festschreiben', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', { data: draftBody() })).json()
		const rejected = await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })
		expect(rejected.status()).toBe(400)
		expect((await rejected.json()).problems).toContain('Umsatzsteuerkonto für jeden verwendeten Steuersatz (Einstellungen)')
		await api.raw(request, 'DELETE', `/invoices/${draft.id}`)
	})

	test('Festschreiben mit 19 %, 7 % und steuerfreier Position: Summen und offener Posten', async ({ request }) => {
		await api.updateSettings(request, { invoice_vat_account_19: vat19.id, invoice_vat_account_7: vat7.id })
		const draft = await (await api.raw(request, 'POST', '/invoices', { data: draftBody() })).json()
		expect(draft.netCents).toBe(18000)
		expect(draft.taxCents).toBe(2250)
		expect(draft.grossCents).toBe(20250)

		invoice = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })).json()
		expect(invoice.status).toBe('issued')
		expect(invoice.remainingCents).toBe(20250)
		expect(invoice.exemptionText).toBe('Steuerfrei nach § 4 Nr. 12 UStG')
	})

	test('Teilzahlung und Rest: Erlöse, Umsatzsteuer 19 % und 7 % stimmen am Ende auf den Cent', async ({ request }) => {
		await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { data: { date: TODAY, accountId: bank.id, amountCents: 5000 } })
		const paid = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { data: { date: TODAY, accountId: bank.id } })).json()
		expect(paid.status).toBe('paid')

		const sums = {}
		for (const entry of (await api.listJournal(request)).filter((e) => e.journal.documentRef === invoice.number)) {
			for (const line of entry.lines) {
				sums[line.accountId] = (sums[line.accountId] || 0) + line.creditCents - line.debitCents
			}
		}
		expect(sums[bank.id]).toBe(-20250)
		expect(sums[income.id]).toBe(18000)
		expect(sums[vat19.id]).toBe(1900)
		expect(sums[vat7.id]).toBe(350)
	})

	test('Storno kehrt Netto, Steuer und Brutto um', async ({ request }) => {
		const result = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/cancel`, { data: { reason: 'Test' } })).json()
		expect(result.credit.netCents).toBe(-18000)
		expect(result.credit.taxCents).toBe(-2250)
		expect(result.credit.grossCents).toBe(-20250)
	})

	test('Rolle: Revisor darf die Rechnungen lesen', async ({ request }) => {
		expect((await api.raw(request, 'GET', `/invoices/${invoice.id}`, { user: USERS.revisor })).status()).toBe(200)
	})

	function draftBody() {
		return {
			debtorId: debtor.id,
			taxMode: 'regular',
			issueDate: TODAY,
			deliveryDate: TODAY,
			lines: [
				{ name: 'Kursgebühr', quantity: 1, unitCode: 'LS', unitPriceCents: 10000, accountId: income.id, taxCategory: 'S', taxRateBp: 1900 },
				{ name: 'Eintritt', quantity: 2, unitCode: 'C62', unitPriceCents: 2500, accountId: income.id, taxCategory: 'S', taxRateBp: 700 },
				{ name: 'Raumnutzung', quantity: 1, unitCode: 'LS', unitPriceCents: 3000, accountId: income.id, taxCategory: 'E' },
			],
		}
	}
})
