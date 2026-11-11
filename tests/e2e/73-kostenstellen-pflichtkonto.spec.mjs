import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BASE_URL, neuAnlegen, openApp, pickNcSelectOption, selectPeriod, skipBookingTour, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Kennzeichen „Kostenstelle verpflichtend“ am Konto (Issue #334): eine
// Erinnerung, keine Sperre. Der Buchungsdialog warnt mit dem offenen Betrag
// und lässt trotzdem speichern; der Kostenstellen-Bericht listet, was auf
// Pflichtkonten noch keiner Kostenstelle zugeordnet ist.

const EXPENSE_ACCOUNT = '5200' // Veranstaltungskosten
const YEAR = '2031'

async function setModule(request, enabled) {
	await api.updateSettings(request, { cost_centers_enabled: enabled ? '1' : '0' })
}

/** Frischer Bestand, Modul ein, eine Kostenstelle, das Aufwandskonto als Pflichtkonto. */
async function setup(request, { required = true } = {}) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	await setModule(request, true)
	const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)
	const costCenter = await (await api.raw(request, 'POST', '/booking-cost-centers', { expectOk: true, data: { code: 'SEN', name: 'Senioren' } })).json()
	if (required) {
		await api.updateAccount(request, expense.id, { costCenterRequired: true })
	}
	return { bank, expense, costCenter }
}

test.describe('Kostenstelle verpflichtend', () => {
	test.afterAll(async ({ request }) => {
		await setModule(request, false)
	})

	test('Schalter im Kontodialog setzt das Kennzeichen', async ({ page, request }) => {
		const { expense } = await setup(request, { required: false })
		expect((await api.accountByNumber(request, EXPENSE_ACCOUNT)).costCenterRequired).toBe(false)

		await openApp(page, USERS.verwalter)
		await page.goto(`${BASE_URL}/apps/vereinsbuchhaltung/accounts/${expense.id}/edit`)
		const dialog = page.getByRole('dialog')
		await dialog.getByText('Kostenstelle verpflichtend', { exact: true }).click()
		await expect(dialog.getByText('Beim Buchen auf dieses Konto erinnert die App an die Kostenstelle')).toBeVisible()
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		await expect.poll(async () => (await api.accountByNumber(request, EXPENSE_ACCOUNT)).costCenterRequired).toBe(true)
	})

	test('Buchungsdialog warnt mit dem offenen Betrag und speichert trotzdem', async ({ page, request }) => {
		const { expense } = await setup(request)

		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const dialog = page.getByRole('dialog')
		await dialog.getByRole('button', { name: 'Ausgabe' }).click()
		await skipBookingTour(dialog)
		await dialog.getByLabel('Betrag (€)', { exact: true }).fill('250')
		await pickNcSelectOption(dialog, '– Kategorie wählen –', 'Veranstaltungskosten')
		await dialog.locator('input[type="date"]').fill(`${YEAR}-04-01`)
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill('Hallenmiete Turnier')

		const hint = dialog.locator('.vbh-alloc-required')
		await expect(hint).toContainText('250,00')

		// Mit einer Kostenstelle über den ganzen Betrag verschwindet der Hinweis …
		const select = dialog.locator('.vbh-allocblock').getByLabel('Kostenstelle', { exact: true })
		await select.selectOption({ label: 'SEN · Senioren' })
		await expect(hint).toHaveCount(0)
		// … und ohne ist er wieder da. Gespeichert wird trotzdem.
		await select.selectOption('')
		await expect(hint).toContainText('250,00')
		await dialog.getByRole('button', { name: 'Buchen', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		const entry = (await api.listJournal(request)).find((e) => e.journal.description === 'Hallenmiete Turnier')
		expect(entry.lines.find((l) => l.accountId === expense.id).allocations).toEqual([])
	})

	test('Bericht listet Pflichtkonten mit offenem Betrag, bis alles zugeordnet ist', async ({ page, request }) => {
		const { bank, expense, costCenter } = await setup(request)
		const created = await (await api.raw(request, 'POST', '/journal', {
			expectOk: true,
			data: {
				date: `${YEAR}-04-02`,
				description: 'Trikots',
				lines: [
					{ accountId: expense.id, debit: 1000, credit: 0, allocations: [{ costCenterId: costCenter.id, amount: 600 }] },
					{ accountId: bank.id, debit: 0, credit: 1000 },
				],
			},
		})).json()

		const report = await api.getJson(request, '/report/cost-center-allocations')
		expect(report.requiredOpen).toEqual([{ accountId: expense.id, number: EXPENSE_ACCOUNT, name: 'Veranstaltungskosten', amountCents: 40000, kind: 'expense' }])

		await openApp(page, USERS.verwalter)
		await selectPeriod(page, YEAR)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Kostenstellen', exact: true }).click()
		const block = visibleSection(page).locator('.vbh-ccreport-required')
		await expect(block.getByRole('heading', { name: 'Pflichtkonten mit offenem Betrag' })).toBeVisible()
		await expect(block.locator('tr', { hasText: 'Veranstaltungskosten' })).toContainText('400,00')

		// Druckseite und CSV nennen es ebenfalls.
		expect(await (await api.raw(request, 'GET', '/export/cost-center-allocations')).text()).toContain('Pflichtkonten mit offenem Betrag')
		expect(await (await api.raw(request, 'GET', '/export/cost-center-allocations-csv')).text()).toContain('"Pflichtkonto offen";"Ausgabe";"5200"')

		// Vollständig zugeordnet: der Block ist weg.
		await api.raw(request, 'PUT', `/journal/${created.id}`, {
			expectOk: true,
			data: {
				date: `${YEAR}-04-02`,
				description: 'Trikots',
				updatedAt: created.updatedAt,
				lines: [
					{ accountId: expense.id, debit: 1000, credit: 0, allocations: [{ costCenterId: costCenter.id, amount: 1000 }] },
					{ accountId: bank.id, debit: 0, credit: 1000 },
				],
			},
		})
		expect((await api.getJson(request, '/report/cost-center-allocations')).requiredOpen).toEqual([])
	})

	test('Kennzeichen fällt weg, wenn das Konto keine Kostenstelle mehr trägt', async ({ request }) => {
		const { expense } = await setup(request)
		expect((await api.accountByNumber(request, EXPENSE_ACCOUNT)).costCenterRequired).toBe(true)

		await api.updateAccount(request, expense.id, { type: 'asset' })
		expect((await api.accountByNumber(request, EXPENSE_ACCOUNT)).costCenterRequired).toBe(false)
		// Auch nach dem Zurückstellen bleibt es aus: es wurde wirklich gelöscht, nicht nur verdeckt.
		await api.updateAccount(request, expense.id, { type: 'expense' })
		expect((await api.accountByNumber(request, EXPENSE_ACCOUNT)).costCenterRequired).toBe(false)
	})

	test('ausgeschaltet: kein Schalter im Kontodialog, kein Hinweis beim Buchen', async ({ page, request }) => {
		const { expense } = await setup(request)
		await setModule(request, false)

		// Kontodialog: kein Schalter, und das Speichern lässt das Kennzeichen stehen.
		await openApp(page, USERS.verwalter)
		await page.goto(`${BASE_URL}/apps/vereinsbuchhaltung/accounts/${expense.id}/edit`)
		const dialog = page.getByRole('dialog')
		await expect(dialog.getByRole('button', { name: 'Speichern', exact: true })).toBeVisible()
		await expect(dialog.getByText('Kostenstelle verpflichtend', { exact: true })).toHaveCount(0)
		const saved = page.waitForRequest((r) => r.method() === 'PUT' && r.url().includes(`/api/accounts/${expense.id}`))
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		expect((await saved).postDataJSON()).not.toHaveProperty('costCenterRequired')
		await expect(page.getByRole('dialog')).toBeHidden()

		// Buchungsdialog: kein Feld, kein Hinweis.
		await neuAnlegen(page)
		const booking = page.getByRole('dialog')
		await booking.getByRole('button', { name: 'Ausgabe' }).click()
		await skipBookingTour(booking)
		await booking.getByLabel('Betrag (€)', { exact: true }).fill('10')
		await pickNcSelectOption(booking, '– Kategorie wählen –', 'Veranstaltungskosten')
		await expect(booking.locator('.vbh-alloc-required')).toHaveCount(0)
		await expect(booking.locator('.vbh-allocblock')).toHaveCount(0)

		await setModule(request, true)
		expect((await api.accountByNumber(request, EXPENSE_ACCOUNT)).costCenterRequired).toBe(true)
	})
})
