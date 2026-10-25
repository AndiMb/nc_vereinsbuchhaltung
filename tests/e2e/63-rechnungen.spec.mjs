import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Rechnungsmodul (Epic #168): Entwurf, Festschreiben mit Nummer und PDF,
// Zahlungsabgleich beim Import, Teil- und Barzahlung, Storno, Rollen und die
// Oberfläche. Die Specs bauen aufeinander auf (serial): erst die Rechnung,
// dann ihre Zahlung, dann die Oberfläche.

const YEAR = new Date().getFullYear()
const TODAY = `${YEAR}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`

/** CSV-CAMT-Zeile für das eigene Konto (Format wie tests/fixtures/beispiel-camt.csv). */
function csv(...rows) {
	const header = 'Auftragskonto;Buchungstag;Valutadatum;Buchungstext;Verwendungszweck;Glaeubiger ID;Mandatsreferenz;Kundenreferenz (End-to-End);Sammlerreferenz;Lastschrift Ursprungsbetrag;Auslagenersatz Ruecklastschrift;Beguenstigter/Zahlungspflichtiger;Kontonummer/IBAN;BIC (SWIFT-Code);Betrag;Waehrung;Info'
	const day = `${String(new Date().getDate()).padStart(2, '0')}.${String(new Date().getMonth() + 1).padStart(2, '0')}.${YEAR}`
	const lines = rows.map(({ purpose, who, amount }) => [BANK_ACCOUNT_IBAN, day, day, 'GUTSCHRIFT', purpose, '', '', '', '', '', '', who, 'DE89370400440532013000', 'COBADEFFXXX', amount, 'EUR', 'Umsatz gebucht'].join(';'))
	return [header, ...lines].join('\n')
}

test.describe.serial('Rechnungen', () => {
	let bank
	let income
	let debtor
	let first
	let second

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		bank = await api.accountByNumber(request, BANK_ACCOUNT)
		income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.updateAccount(request, bank.id, { ...bank, iban: BANK_ACCOUNT_IBAN })
		await api.updateSettings(request, {
			club_name: 'TSV E2E e. V.',
			club_street: 'Hauptstr. 1',
			club_postal_code: '12345',
			club_city: 'Beispielstadt',
			club_tax_number: '12/345/67890',
			invoice_module_active: '1',
			invoice_account_id: bank.id,
			invoice_tax_mode: 'kleinunternehmer',
		})
		debtor = (await api.raw(request, 'POST', '/debtors', {
			data: { kind: 'organisation', name: 'Sportstudio E2E GmbH', street: 'Weg 2', postalCode: '54321', city: 'Musterstadt', email: 'info@studio-e2e.example' },
		}).then((r) => r.json()))
	})

	const lines = (cents) => [{ name: 'Hallenmiete', quantity: 1, unitCode: 'LS', unitPriceCents: cents, accountId: undefined }]

	test('Entwurf anlegen und festschreiben: Nummer, offener Posten und PDF', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			data: { debtorId: debtor.id, issueDate: TODAY, deliveryDate: TODAY, lines: lines(8925).map((l) => ({ ...l, accountId: income.id })) },
		})).json()
		expect(draft.status).toBe('draft')
		expect(draft.number).toBeNull()
		expect(draft.checklist.filter((c) => c.required && !c.ok)).toEqual([])

		const issued = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })).json()
		expect(issued.status).toBe('issued')
		expect(issued.number).toMatch(new RegExp(`^RE-${YEAR}-\\d{4}$`))
		expect(issued.grossCents).toBe(8925)
		expect(issued.remainingCents).toBe(8925)
		expect(issued.exemptionText).toContain('§ 19 UStG')
		first = issued

		// Festgeschrieben ist unveränderlich.
		const edit = await api.raw(request, 'PUT', `/invoices/${issued.id}`, { data: { noteHead: 'geändert' } })
		expect(edit.status()).toBe(400)

		const pdf = await api.raw(request, 'GET', `/invoices/${issued.id}/pdf`)
		expect(pdf.status()).toBe(200)
		expect(pdf.headers()['content-type']).toContain('application/pdf')
		expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-')
	})

	test('Pflichtangaben fehlen: Festschreiben wird mit der Liste der Lücken abgelehnt', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			data: { debtorId: debtor.id, issueDate: TODAY, lines: lines(1000).map((l) => ({ ...l, accountId: income.id })) },
		})).json()
		const rejected = await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })
		expect(rejected.status()).toBe(400)
		expect((await rejected.json()).problems).toContain('Leistungsdatum oder Leistungszeitraum')
		await api.raw(request, 'DELETE', `/invoices/${draft.id}`)
	})

	test('Der Import bucht eine Zahlung mit Rechnungsnummer und genauem Betrag selbst', async ({ request }) => {
		const result = await api.importStatement(request, csv({ purpose: first.number.replace(/-/g, ' ') + ' Hallenmiete', who: 'Sportstudio E2E GmbH', amount: '89,25' }))
		expect(result.autoAssigned).toBe(1)

		const detail = await api.getJson(request, `/invoices/${first.id}`)
		expect(detail.status).toBe('paid')
		expect(detail.remainingCents).toBe(0)

		const booking = (await api.listJournal(request)).find((e) => e.journal.documentRef === first.number)
		expect(booking).toBeTruthy()
		const byAccount = Object.fromEntries(booking.lines.map((l) => [l.accountId, [l.debitCents, l.creditCents]]))
		expect(byAccount[bank.id]).toEqual([8925, 0])
		expect(byAccount[income.id]).toEqual([0, 8925])
	})

	test('Teilzahlung wird vorgeschlagen, Rest per Barzahlung, Zahlung lässt sich zurücknehmen', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			data: { debtorId: debtor.id, issueDate: TODAY, deliveryDate: TODAY, lines: lines(10000).map((l) => ({ ...l, accountId: income.id })) },
		})).json()
		second = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })).json()

		await api.importStatement(request, csv({ purpose: `Anzahlung ${second.number}`, who: 'Sportstudio E2E GmbH', amount: '40,00' }))
		const open = await api.listTransactions(request, { status: 'unassigned' })
		const tx = open.find((t) => (t.purpose || '').includes(second.number))
		expect(tx, 'Teilzahlung wartet auf Zuordnung, der Import bucht sie nicht selbst').toBeTruthy()

		const suggestions = await api.getJson(request, '/invoices/suggestions')
		expect(suggestions[tx.id][0]).toMatchObject({ invoiceId: second.id, reason: 'number', exact: false, overpay: false })

		const afterBank = await (await api.raw(request, 'POST', `/invoices/${second.id}/payments`, { data: { bankTxId: tx.id } })).json()
		expect(afterBank.status).toBe('issued')
		expect(afterBank.remainingCents).toBe(6000)

		const afterCash = await (await api.raw(request, 'POST', `/invoices/${second.id}/payments/manual`, { data: { date: TODAY, accountId: bank.id } })).json()
		expect(afterCash.status).toBe('paid')
		expect(afterCash.payments).toHaveLength(2)

		const reverted = await (await api.raw(request, 'DELETE', `/invoices/${second.id}/payments/${afterCash.payments[1].id}`)).json()
		expect(reverted.status).toBe('issued')
		expect(reverted.remainingCents).toBe(6000)
	})

	test('Storno: Stornorechnung mit negativem Betrag, Original storniert, Rückzahlbetrag genannt', async ({ request }) => {
		const result = await (await api.raw(request, 'POST', `/invoices/${second.id}/cancel`, { data: { reason: 'Test-Storno' } })).json()
		expect(result.credit.number).toMatch(new RegExp(`^ST-${YEAR}-\\d{4}$`))
		expect(result.credit.grossCents).toBe(-10000)
		expect(result.credit.refundDueCents).toBe(4000)
		expect(result.invoice.status).toBe('cancelled')

		const again = await api.raw(request, 'POST', `/invoices/${second.id}/cancel`, { data: { reason: 'noch einmal' } })
		expect(again.status()).toBe(400)
	})

	test('Rollen: Buchhalter legt Entwürfe an, festschreiben darf nur der Verwalter', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			user: USERS.buchhalter,
			data: { debtorId: debtor.id, issueDate: TODAY, deliveryDate: TODAY, lines: lines(500).map((l) => ({ ...l, accountId: income.id })) },
		})).json()
		expect(draft.status).toBe('draft')
		expect((await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { user: USERS.buchhalter, data: {} })).status()).toBe(403)
		expect((await api.raw(request, 'POST', '/invoices', { user: USERS.revisor, data: {} })).status()).toBe(403)
		expect((await api.raw(request, 'GET', '/invoices', { user: USERS.revisor })).status()).toBe(200)
		await api.raw(request, 'DELETE', `/invoices/${draft.id}`)
	})

	test('Oberfläche: Reiter Rechnungen zeigt Nummern und Status', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Rechnungen')
		const section = visibleSection(page)
		const row = section.locator('tbody tr', { hasText: first.number })
		await expect(row).toBeVisible()
		await expect(row.locator('.vbh-inv-status')).toHaveText('Bezahlt')
		await expect(section.locator('tbody tr', { hasText: second.number })).toBeVisible()

		// Ein Klick öffnet die Detailansicht mit den Positionen.
		await row.click()
		const dialog = page.getByRole('dialog')
		await expect(dialog.getByRole('heading', { name: new RegExp(first.number) })).toBeVisible()
		await expect(dialog.getByText('Hallenmiete')).toBeVisible()
	})
})
