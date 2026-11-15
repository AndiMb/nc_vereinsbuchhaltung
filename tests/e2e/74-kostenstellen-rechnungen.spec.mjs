import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BASE_URL, INCOME_ACCOUNT, openApp, USERS } from './fixtures/nextcloud.mjs'

// Kostenstellen an Rechnungen (Issue #332): jede Position kann eine
// Kostenstelle tragen. Beim Buchen der Zahlung wird der Erlös je Konto
// anteilig auf die Kostenstellen der Positionen verteilt – die Aufteilung auf
// die Erlöskonten selbst bleibt, wie sie war.

const YEAR = new Date().getFullYear()
const TODAY = `${YEAR}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`

async function setModule(request, enabled) {
	await api.updateSettings(request, { cost_centers_enabled: enabled ? '1' : '0' })
}

/** Frischer Bestand: Rechnungsmodul und Kostenstellen ein, zwei Kostenstellen, ein Empfänger. */
async function setup(request) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	const income = await api.accountByNumber(request, INCOME_ACCOUNT)
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
		cost_centers_enabled: '1',
	})
	const post = async (path, data) => (await api.raw(request, 'POST', path, { expectOk: true, data })).json()
	const sen = await post('/booking-cost-centers', { code: 'SEN', name: 'Senioren' })
	const aju = await post('/booking-cost-centers', { code: 'AJU', name: 'A-Junioren' })
	const debtor = await post('/debtors', { kind: 'organisation', name: 'Sportstudio E2E GmbH', street: 'Weg 2', postalCode: '54321', city: 'Musterstadt', email: 'info@studio-e2e.example' })
	return { bank, income, sen, aju, debtor }
}

/** Entwurf mit zwei Positionen auf demselben Erlöskonto: 600,00 Senioren, 400,00 A-Junioren. */
async function draftWithTwoCostCenters(request, { income, sen, aju, debtor }) {
	return (await api.raw(request, 'POST', '/invoices', {
		expectOk: true,
		data: {
			debtorId: debtor.id,
			issueDate: TODAY,
			deliveryDate: TODAY,
			lines: [
				{ name: 'Startgeld Senioren', quantity: 1, unitCode: 'LS', unitPriceCents: 60000, accountId: income.id, bookingCostCenterId: sen.id },
				{ name: 'Startgeld A-Junioren', quantity: 1, unitCode: 'LS', unitPriceCents: 40000, accountId: income.id, bookingCostCenterId: aju.id },
			],
		},
	})).json()
}

/** Verteilung der Erlöszeile einer Zahlungsbuchung in Cent, als { Kostenstelle: Cent }. */
async function paymentAllocations(request, journalId, incomeId) {
	const entry = (await api.listJournal(request)).find((e) => e.journal.id === journalId)
	const line = entry.lines.find((l) => l.accountId === incomeId)
	return { credit: line.creditCents, byCostCenter: Object.fromEntries(line.allocations.map((a) => [a.costCenterId, Math.round(a.amount * 100)])) }
}

test.describe('Kostenstellen an Rechnungen', () => {
	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { cost_centers_enabled: '0', invoice_module_active: '0' })
	})

	test('Barzahlung verteilt den Erlös auf die Kostenstellen der Positionen', async ({ request }) => {
		const ctx = await setup(request)
		const draft = await draftWithTwoCostCenters(request, ctx)
		expect(draft.lines.map((l) => l.bookingCostCenterId)).toEqual([ctx.sen.id, ctx.aju.id])
		const invoice = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { expectOk: true, data: {} })).json()

		const paid = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { expectOk: true, data: { date: TODAY, accountId: ctx.bank.id } })).json()
		expect(paid.status).toBe('paid')

		const { credit, byCostCenter } = await paymentAllocations(request, paid.payments[0].journalId, ctx.income.id)
		expect(credit).toBe(100000)
		expect(byCostCenter).toEqual({ [ctx.sen.id]: 60000, [ctx.aju.id]: 40000 })

		// Im Bericht stehen die Beträge als Einnahmen der Kostenstellen.
		const report = await api.getJson(request, '/report/cost-center-allocations')
		const byCode = Object.fromEntries(report.centers.map((c) => [c.code, c.incomeCents]))
		expect(byCode).toEqual({ AJU: 40000, SEN: 60000 })
		expect(report.reconciled).toBe(true)
	})

	test('Teilzahlungen gehen je Buchung restlos auf, Zurücknehmen entfernt die Verteilung', async ({ request }) => {
		const ctx = await setup(request)
		const draft = await draftWithTwoCostCenters(request, ctx)
		const invoice = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { expectOk: true, data: {} })).json()

		// Krummer Teilbetrag: 333,33 von 1.000,00.
		const first = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { expectOk: true, data: { date: TODAY, accountId: ctx.bank.id, amountCents: 33333 } })).json()
		const a = await paymentAllocations(request, first.payments[0].journalId, ctx.income.id)
		expect(a.credit).toBe(33333)
		expect(a.byCostCenter[ctx.sen.id] + a.byCostCenter[ctx.aju.id]).toBe(33333)
		expect(Math.abs(a.byCostCenter[ctx.sen.id] - 20000)).toBeLessThanOrEqual(1)

		// Rest: die Summen über beide Zahlungen treffen die Positionen bis auf einen Cent je Zahlung.
		const second = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { expectOk: true, data: { date: TODAY, accountId: ctx.bank.id } })).json()
		expect(second.status).toBe('paid')
		const b = await paymentAllocations(request, second.payments[1].journalId, ctx.income.id)
		expect(b.byCostCenter[ctx.sen.id] + b.byCostCenter[ctx.aju.id]).toBe(66667)
		expect(Math.abs(a.byCostCenter[ctx.sen.id] + b.byCostCenter[ctx.sen.id] - 60000)).toBeLessThanOrEqual(2)

		// Zahlung zurücknehmen: Buchung und Verteilung sind weg.
		await api.raw(request, 'DELETE', `/invoices/${invoice.id}/payments/${second.payments[1].id}`, { expectOk: true })
		const report = await api.getJson(request, '/report/cost-center-allocations')
		expect(report.centers.reduce((sum, c) => sum + c.incomeCents, 0)).toBe(33333)
		expect(report.reconciled).toBe(true)
	})

	test('Position ohne Kostenstelle bleibt ohne; deaktivierte Kostenstelle zählt wie keine', async ({ request }) => {
		const ctx = await setup(request)
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			expectOk: true,
			data: {
				debtorId: ctx.debtor.id,
				issueDate: TODAY,
				deliveryDate: TODAY,
				lines: [
					{ name: 'Startgeld Senioren', quantity: 1, unitCode: 'LS', unitPriceCents: 60000, accountId: ctx.income.id, bookingCostCenterId: ctx.sen.id },
					{ name: 'Startgeld A-Junioren', quantity: 1, unitCode: 'LS', unitPriceCents: 30000, accountId: ctx.income.id, bookingCostCenterId: ctx.aju.id },
					{ name: 'Bearbeitung', quantity: 1, unitCode: 'LS', unitPriceCents: 10000, accountId: ctx.income.id },
				],
			},
		})).json()
		const invoice = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { expectOk: true, data: {} })).json()
		// Nach dem Festschreiben wird AJU deaktiviert: die Zahlung darf daran nicht scheitern.
		await api.raw(request, 'PUT', `/booking-cost-centers/${ctx.aju.id}`, { expectOk: true, data: { code: 'AJU', name: 'A-Junioren', active: false } })

		const paid = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { expectOk: true, data: { date: TODAY, accountId: ctx.bank.id } })).json()
		const { credit, byCostCenter } = await paymentAllocations(request, paid.payments[0].journalId, ctx.income.id)
		expect(credit).toBe(100000)
		expect(byCostCenter).toEqual({ [ctx.sen.id]: 60000 })
	})

	test('Editor: Feld je Position; ausgeschaltet bleibt jede Kostenstelle an ihrer Position', async ({ page, request }) => {
		const ctx = await setup(request)
		const draft = await draftWithTwoCostCenters(request, ctx)
		const stored = async () => (await api.getJson(request, `/invoices/${draft.id}`)).lines.map((l) => l.bookingCostCenterId)

		await openApp(page, USERS.verwalter)
		await page.goto(`${BASE_URL}/apps/vereinsbuchhaltung/invoices/${draft.id}`)
		await page.getByRole('button', { name: 'Entwurf bearbeiten', exact: true }).click()
		const selects = page.locator('.vbh-inv-l2 label', { hasText: 'Kostenstelle' }).locator('select')
		await expect(selects).toHaveCount(2)
		await expect(selects.first()).toHaveValue(String(ctx.sen.id))

		// Beide Positionen tauschen ihre Kostenstelle; speichern.
		const savedOn = page.waitForRequest((r) => r.method() === 'PUT' && r.url().includes(`/api/invoices/${draft.id}`))
		await selects.first().selectOption(String(ctx.aju.id))
		await selects.nth(1).selectOption(String(ctx.sen.id))
		await page.getByRole('button', { name: 'Entwurf speichern', exact: true }).click()
		expect((await savedOn).postDataJSON().lines.map((l) => l.bookingCostCenterId)).toEqual([ctx.aju.id, ctx.sen.id])
		await expect.poll(stored).toEqual([ctx.aju.id, ctx.sen.id])

		// Ausgeschaltet: kein Feld. Wird die erste Position gelöscht, behält die
		// zweite ihre eigene Kostenstelle - der Editor schickt die geladenen Werte
		// mit, statt den Server nach der Position raten zu lassen.
		await setModule(request, false)
		await page.goto(`${BASE_URL}/apps/vereinsbuchhaltung/invoices/${draft.id}`)
		await page.getByRole('button', { name: 'Entwurf bearbeiten', exact: true }).click()
		await expect(page.getByLabel('Bezeichnung', { exact: true }).first()).toHaveValue('Startgeld Senioren')
		await expect(page.locator('.vbh-inv-l2 label', { hasText: 'Kostenstelle' })).toHaveCount(0)
		const savedOff = page.waitForRequest((r) => r.method() === 'PUT' && r.url().includes(`/api/invoices/${draft.id}`))
		await page.getByRole('button', { name: 'Position entfernen' }).first().click()
		await page.getByRole('button', { name: 'Entwurf speichern', exact: true }).click()
		expect((await savedOff).postDataJSON().lines.map((l) => l.bookingCostCenterId)).toEqual([ctx.sen.id])
		await expect.poll(stored).toEqual([ctx.sen.id])
	})

	test('ausgeschaltet und nie benutzt: der Editor schickt das Feld nicht', async ({ page, request }) => {
		const ctx = await setup(request)
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			expectOk: true,
			data: { debtorId: ctx.debtor.id, issueDate: TODAY, deliveryDate: TODAY, lines: [{ name: 'Hallenmiete', quantity: 1, unitCode: 'LS', unitPriceCents: 5000, accountId: ctx.income.id }] },
		})).json()
		await setModule(request, false)

		await openApp(page, USERS.verwalter)
		await page.goto(`${BASE_URL}/apps/vereinsbuchhaltung/invoices/${draft.id}`)
		await page.getByRole('button', { name: 'Entwurf bearbeiten', exact: true }).click()
		const name = page.getByLabel('Bezeichnung', { exact: true }).first()
		await expect(name).toHaveValue('Hallenmiete')
		const saved = page.waitForRequest((r) => r.method() === 'PUT' && r.url().includes(`/api/invoices/${draft.id}`))
		await name.fill('Hallenmiete 2031')
		await page.getByRole('button', { name: 'Entwurf speichern', exact: true }).click()
		for (const line of (await saved).postDataJSON().lines) {
			expect(line).not.toHaveProperty('bookingCostCenterId')
		}
	})

	test('ausgeschaltet: die Zahlung wird ohne Kostenstelle gebucht; Kopie übernimmt die Kostenstellen', async ({ request }) => {
		const ctx = await setup(request)
		const draft = await draftWithTwoCostCenters(request, ctx)
		const copy = await (await api.raw(request, 'POST', `/invoices/${draft.id}/duplicate`, { expectOk: true, data: {} })).json()
		expect(copy.lines.map((l) => l.bookingCostCenterId)).toEqual([ctx.sen.id, ctx.aju.id])

		const invoice = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { expectOk: true, data: {} })).json()
		await setModule(request, false)
		const paid = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { expectOk: true, data: { date: TODAY, accountId: ctx.bank.id } })).json()
		expect(paid.status).toBe('paid')

		await setModule(request, true)
		const entry = (await api.listJournal(request)).find((e) => e.journal.id === paid.payments[0].journalId)
		expect(entry.lines.find((l) => l.accountId === ctx.income.id).allocations).toEqual([])
		// Eine Kostenstelle, auf die Rechnungspositionen zeigen, lässt sich nicht löschen.
		const blocked = await api.raw(request, 'DELETE', `/booking-cost-centers/${ctx.sen.id}`)
		expect(blocked.status()).toBe(400)
		expect((await blocked.json()).message).toContain('Rechnungspositionen')
	})
})
