import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, INCOME_ACCOUNT, neuAnlegen, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Rechnungsmodul Stufe 3 (Epic #168): Positionsvorlagen, wiederkehrende Rechnungen,
// Mahnstand, RF-Referenz im Bankabgleich und die Rechnungen im Prüfungs-ZIP.

const YEAR = new Date().getFullYear()
const MONTH = String(new Date().getMonth() + 1).padStart(2, '0')
const DAY = String(new Date().getDate()).padStart(2, '0')
const TODAY = `${YEAR}-${MONTH}-${DAY}`

/** RF-Referenz nach ISO 11649 aus einer Rechnungsnummer (wie CreditorReference.php). */
function rfReference(number) {
	const base = number.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
	const numeric = (base + 'RF00').replace(/[A-Z]/g, (c) => String(c.charCodeAt(0) - 55))
	let rest = 0
	for (const ch of numeric) { rest = (rest * 10 + Number(ch)) % 97 }
	return 'RF' + String(98 - rest).padStart(2, '0') + base
}

function csv(...rows) {
	const header = 'Auftragskonto;Buchungstag;Valutadatum;Buchungstext;Verwendungszweck;Glaeubiger ID;Mandatsreferenz;Kundenreferenz (End-to-End);Sammlerreferenz;Lastschrift Ursprungsbetrag;Auslagenersatz Ruecklastschrift;Beguenstigter/Zahlungspflichtiger;Kontonummer/IBAN;BIC (SWIFT-Code);Betrag;Waehrung;Info'
	const day = `${DAY}.${MONTH}.${YEAR}`
	const lines = rows.map(({ purpose, who, amount }) => [BANK_ACCOUNT_IBAN, day, day, 'GUTSCHRIFT', purpose, '', '', '', '', '', '', who, 'DE89370400440532013000', 'COBADEFFXXX', amount, 'EUR', 'Umsatz gebucht'].join(';'))
	return [header, ...lines].join('\n')
}

test.describe.serial('Rechnungen Stufe 3', () => {
	let bank
	let income
	let debtor

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
		debtor = await (await api.raw(request, 'POST', '/debtors', {
			data: { kind: 'organisation', name: 'Sportstudio E2E GmbH', street: 'Weg 2', postalCode: '54321', city: 'Musterstadt', email: 'info@studio-e2e.example' },
		})).json()
	})

	test('Positionsvorlagen: anlegen, prüfen, ändern, löschen; Buchhalter dürfen nur lesen', async ({ request }) => {
		const created = await api.raw(request, 'POST', '/invoice-templates', { data: { name: 'Hallenmiete', unitCode: 'HUR', unitPriceCents: 4500, taxCategory: 'E', accountId: income.id } })
		expect(created.status()).toBe(201)
		const tpl = await created.json()
		expect(tpl).toMatchObject({ name: 'Hallenmiete', unitCode: 'HUR', unitPriceCents: 4500, accountId: income.id })

		expect((await api.raw(request, 'POST', '/invoice-templates', { data: { name: 'x', unitCode: 'XYZ' } })).status()).toBe(400)
		expect((await api.raw(request, 'POST', '/invoice-templates', { data: { name: '' } })).status()).toBe(400)
		expect((await api.raw(request, 'POST', '/invoice-templates', { user: USERS.buchhalter, data: { name: 'Nein' } })).status()).toBe(403)
		expect((await api.raw(request, 'GET', '/invoice-templates', { user: USERS.buchhalter })).status()).toBe(200)

		const changed = await (await api.raw(request, 'PUT', `/invoice-templates/${tpl.id}`, { data: { unitPriceCents: 5000 } })).json()
		expect(changed.unitPriceCents).toBe(5000)
		expect(changed.name).toBe('Hallenmiete')
	})

	test('Der Rechnungseditor fügt eine Vorlage als Position ein', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Rechnungen')
		await visibleSection(page).getByRole('button', { name: 'Neue Rechnung' }).first().click()
		await page.locator('select.vbh-inv-tplpick').selectOption({ label: 'Hallenmiete' })
		await expect(page.locator('.vbh-inv-name input').first()).toHaveValue('Hallenmiete')
	})

	test('Wiederkehrende Rechnung: Plan legt am Termin einen Entwurf mit Zeitraum an', async ({ request }) => {
		const plan = await (await api.raw(request, 'POST', '/invoice-plans', {
			data: {
				name: 'Miete monatlich',
				debtorId: debtor.id,
				intervalMonths: 1,
				startDate: TODAY,
				lines: [{ name: 'Hallenmiete {monat} {jahr}', quantity: '1', unitCode: 'LS', unitPriceCents: 12000, accountId: income.id }],
			},
		})).json()
		expect(plan.nextRun).toBe(TODAY)
		expect(plan.autoIssue).toBe(false)

		const ran = await (await api.raw(request, 'POST', '/invoice-plans/run-due')).json()
		expect(ran.created).toBe(1)

		const invoices = await api.getJson(request, '/invoices?status=draft')
		const draft = invoices.find((i) => i.debtorId === debtor.id)
		expect(draft, 'Entwurf aus dem Plan').toBeTruthy()
		const detail = await api.getJson(request, `/invoices/${draft.id}`)
		expect(detail.periodStart).toBe(TODAY)
		expect(detail.lines[0].name).toMatch(/^Hallenmiete .+ \d{4}$/)
		expect(detail.lines[0].name).not.toContain('{')

		const after = (await api.getJson(request, '/invoice-plans')).find((p) => p.id === plan.id)
		expect(after.runCount).toBe(1)
		expect(after.lastInvoiceId).toBe(draft.id)
		expect(after.nextRun > TODAY).toBe(true)

		// Pausiert läuft nichts; am selben Tag ist ohnehin nichts mehr fällig.
		const paused = await (await api.raw(request, 'POST', `/invoice-plans/${plan.id}/active`, { data: { active: false } })).json()
		expect(paused.active).toBe(false)
		expect((await (await api.raw(request, 'POST', '/invoice-plans/run-due')).json()).created).toBe(0)
		await api.raw(request, 'DELETE', `/invoice-plans/${plan.id}`)
		await api.raw(request, 'DELETE', `/invoices/${draft.id}`)
	})

	test('Pläne: Buchhalter dürfen sie sehen, nicht anlegen; ungültige Angaben werden abgelehnt', async ({ request }) => {
		expect((await api.raw(request, 'GET', '/invoice-plans', { user: USERS.buchhalter })).status()).toBe(200)
		expect((await api.raw(request, 'POST', '/invoice-plans', { user: USERS.buchhalter, data: { name: 'x' } })).status()).toBe(403)
		const bad = await api.raw(request, 'POST', '/invoice-plans', { data: { name: 'x', debtorId: debtor.id, intervalMonths: 5, startDate: TODAY, lines: [{ name: 'a', unitPriceCents: 100, accountId: income.id }] } })
		expect(bad.status()).toBe(400)
	})

	test('RF-Referenz im Verwendungszweck ordnet die Zahlung der Rechnung zu', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			data: { debtorId: debtor.id, issueDate: TODAY, deliveryDate: TODAY, lines: [{ name: 'Kursgebühr', quantity: 1, unitCode: 'LS', unitPriceCents: 7500, accountId: income.id }] },
		})).json()
		const invoice = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })).json()

		const reference = rfReference(invoice.number)
		// Ohne die Nummer im Text: nur die Referenz verrät die Rechnung.
		const result = await api.importStatement(request, csv({ purpose: `Zahlung ${reference.replace(/(.{4})/g, '$1 ')}`, who: 'Sportstudio E2E GmbH', amount: '75,00' }))
		expect(result.autoAssigned).toBe(1)

		const paid = await api.getJson(request, `/invoices/${invoice.id}`)
		expect(paid.status).toBe('paid')
	})

	test('Mahnstand: bezahlte Rechnungen werden nie gemahnt, Entwürfe haben keine Mahnvorgaben', async ({ request }) => {
		const list = await api.getJson(request, '/invoices')
		const paid = list.find((i) => i.status === 'paid')
		expect(paid.dunning.blocked).toBe('paid')
		expect(paid.dunning.due).toBe(false)

		const refused = await api.raw(request, 'GET', `/invoices/${paid.id}/dunning-defaults`)
		expect(refused.status()).toBe(400)
		expect((await api.raw(request, 'POST', `/invoices/${paid.id}/dunning`, { data: { to: 'a@b.example' } })).status()).toBe(400)
		expect((await api.raw(request, 'POST', `/invoices/${paid.id}/dunning`, { user: USERS.buchhalter, data: { to: 'a@b.example' } })).status()).toBe(403)
	})

	test('Eine überfällige Rechnung hat die Zahlungserinnerung als nächste Stufe, der Dialog nennt Text und Adresse', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			data: { debtorId: debtor.id, issueDate: '2026-01-05', dueDate: '2026-01-12', deliveryDate: '2026-01-05', lines: [{ name: 'Sponsoring', quantity: 1, unitCode: 'LS', unitPriceCents: 20000, accountId: income.id }] },
		})).json()
		const invoice = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: { issueDate: '2026-01-05' } })).json()

		const row = (await api.getJson(request, '/invoices')).find((i) => i.id === invoice.id)
		expect(row.overdue).toBe(true)
		expect(row.dunning).toMatchObject({ level: 0, next: 1, due: true, blocked: null })

		const defaults = await api.getJson(request, `/invoices/${invoice.id}/dunning-defaults`)
		expect(defaults).toMatchObject({ level: 1, to: 'info@studio-e2e.example', remainingCents: 20000 })
		expect(defaults.subject).toContain(invoice.number)
		expect(defaults.body).toContain('Sportstudio E2E GmbH')
		expect(defaults.body).toContain('200,00 €')

		const detail = await api.getJson(request, `/invoices/${invoice.id}`)
		expect(detail.dunning.state.due).toBe(true)
		expect(detail.dunning.history).toEqual([])
	})

	test('Das Prüfungs-ZIP enthält die Rechnungen mit Übersicht und Prüfsummen', async ({ request }) => {
		const response = await api.raw(request, 'GET', '/export/attachments')
		expect(response.status()).toBe(200)
		const zip = (await response.body()).toString('latin1')
		expect(zip).toContain('rechnungen/uebersicht.csv')
		expect(zip).toContain('rechnungen/pruefsummen.txt')
		expect(zip).toMatch(/rechnungen\/RE-\d{4}-\d{4}\/RE-\d{4}-\d{4}\.pdf/)
	})

	test('Oberfläche: Art „wiederkehrend“ im Editor, Plan aus einer Rechnung und Reiter Wiederkehrend', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Rechnungen')
		const section = visibleSection(page)

		// Neue Rechnung: die Art lässt sich wählen; ohne Empfänger gibt es keinen Plan.
		await section.getByRole('button', { name: 'Neue Rechnung' }).first().click()
		await page.getByLabel('Wiederkehrende Rechnung', { exact: true }).check()
		await expect(page.getByRole('heading', { name: 'Wiederholung' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Plan speichern' })).toBeDisabled()
		await page.getByRole('button', { name: 'Zur Liste' }).click()

		// Aus einer festgeschriebenen Rechnung: Empfänger und Positionen sind übernommen.
		await section.locator('tbody tr', { hasText: 'RE-' }).first().click()
		await page.getByRole('dialog').getByRole('button', { name: /Wiederkehrend/ }).click()
		await expect(page.getByText('Neuer Plan für wiederkehrende Rechnungen')).toBeVisible()
		await expect(page.getByLabel('Wiederkehrende Rechnung', { exact: true })).toHaveCount(0)
		await page.getByLabel('Name des Plans').fill('Plan aus Rechnung E2E')
		await page.getByRole('button', { name: 'Plan speichern' }).click()

		await expect(section.getByRole('tab', { name: /Wiederkehrend/ })).toHaveAttribute('aria-selected', 'true')
		const plan = section.locator('.vbh-inv-plan', { hasText: 'Plan aus Rechnung E2E' })
		await expect(plan).toBeVisible()

		// Der Plan lässt sich im selben Editor ändern, die Positionen eingeschlossen.
		await plan.getByRole('button', { name: 'Bearbeiten' }).click()
		await expect(page.getByText('Plan bearbeiten', { exact: true })).toBeVisible()
		await expect(page.locator('.vbh-inv-name input').first()).not.toHaveValue('')
		await page.getByLabel('Name des Plans').fill('Plan geändert E2E')
		await page.getByRole('button', { name: 'Plan speichern' }).click()
		await expect(section.locator('.vbh-inv-plan', { hasText: 'Plan geändert E2E' })).toBeVisible()
	})

	test('Das „+“ im Kopf bietet Buchung und Rechnung; „Rechnung“ öffnet den Editor', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.getByRole('button', { name: 'Neu', exact: true }).click()
		await expect(page.getByRole('menuitem', { name: 'Buchung', exact: true })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: 'Rechnung', exact: true })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: /Wiederkehrend/ })).toHaveCount(0)
		await page.keyboard.press('Escape')

		// „Rechnung“ springt zum Reiter und öffnet den Editor.
		await neuAnlegen(page, 'Rechnung')
		await expect(page.getByText('Neue Rechnung', { exact: true })).toBeVisible()
	})

	test('Das „+“ im Kopf bietet bei eingeschalteten Beiträgen auch „Mitglied“ und öffnet den Aufnahme-Dialog', async ({ page, request }) => {
		await api.updateSettings(request, { membership_enabled: '1' })
		await openApp(page, USERS.verwalter)
		await neuAnlegen(page, 'Mitglied')
		await expect(page.getByRole('dialog', { name: 'Mitglied aufnehmen' })).toBeVisible()
	})
})
