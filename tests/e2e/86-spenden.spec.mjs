import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, openApp, pickNcSelectOption, switchTab } from './fixtures/nextcloud.mjs'

// Issue #10, Teil B: Geldspenden unter Buchungen → Spenden. Eine Buchung auf
// einem Spendenkonto bekommt einen externen Spender (Debitor) und wird einzeln
// bestätigt; danach ist die Zuordnung gesperrt, bis die Bestätigung storniert ist.

const today = new Date()
const DATE = today.toISOString().slice(0, 10)
const SPONSOR = `Autohaus E2E ${Date.now()} GmbH`
const DESCRIPTION = 'Spende Autohaus Sommerfest'

test.describe.serial('Spenden und Einzelbestätigung (#10)', () => {
	let journalId
	let debtorId

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)
		const spenden = await api.accountByNumber(request, '4100')
		await api.updateSettings(request, {
			club_name: 'TSV E2E e. V.',
			club_street: 'Hauptstr. 1',
			club_postal_code: '12345',
			club_city: 'Beispielstadt',
		})
		await api.raw(request, 'PUT', '/donation-receipts/settings', {
			data: {
				donation_receipts_enabled: true,
				// Sportverein: Beiträge nicht abziehbar, bestätigt werden nur Spenden.
				donation_fees_deductible: false,
				donation_basis: 'freistellung',
				donation_tax_office: 'Finanzamt Beispielstadt',
				donation_tax_number: '12/345/67890',
				donation_assessment_period: String(today.getFullYear() - 1),
				donation_notice_date: `${today.getFullYear() - 1}-03-01`,
				donation_purposes: 'des Sports (§ 52 Abs. 2 Satz 1 Nr. 21 AO)',
				donation_place: 'Beispielstadt',
				donation_signatory: 'Erika Muster, Kassenwartin',
				donation_accounts: [spenden.id],
			},
		}).then((r) => expect(r.ok()).toBe(true))
		debtorId = (await api.raw(request, 'POST', '/debtors', {
			data: { kind: 'organisation', name: SPONSOR, street: 'Werkstr. 5', postalCode: '54321', city: 'Musterstadt' },
		}).then((r) => r.json())).id
		journalId = (await api.createBooking(request, {
			date: DATE,
			description: DESCRIPTION,
			debitAccountId: bank.id,
			creditAccountId: spenden.id,
			amount: 250,
		}).then((r) => r.json())).id
	})

	test.afterAll(async ({ request }) => {
		// Andere Specs erwarten die informelle Beitragsbestätigung (Funktion aus).
		await api.raw(request, 'PUT', '/donation-receipts/settings', { data: { donation_receipts_enabled: false, donation_accounts: [] } })
	})

	test('Spender zuordnen und Spende einzeln bestätigen', async ({ page }) => {
		await openApp(page, 'admin')
		await switchTab(page, 'Buchungen')
		await page.locator('.vbh-subtabs').getByRole('button', { name: 'Spenden', exact: true }).click()

		const row = page.locator(`.vbh-donations tr[data-id="${journalId}"]`)
		await expect(row).toContainText(DESCRIPTION)
		await expect(row).toContainText('250,00')

		const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes(`/api/donations/${journalId}`))
		await pickNcSelectOption(row, '– Spender wählen –', SPONSOR)
		expect((await saved).ok()).toBe(true)

		const issued = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/donation-receipts/single'))
		await row.getByRole('button', { name: 'Einzeln bestätigen' }).click()
		expect((await issued).status()).toBe(201)

		await expect(row).toContainText(/ZB-\d{4}-\d{4}/)
		const receipts = page.locator('.vbh-donation-receipt-table')
		await expect(receipts).toContainText(SPONSOR)
		await expect(receipts).toContainText('Spende vom')
	})

	test('bestätigte Spende: Zuordnung gesperrt, Debitor nicht löschbar, PDF abrufbar', async ({ request }) => {
		const state = await api.getJson(request, `/donations?year=${today.getFullYear()}`)
		const donation = state.donations.find((d) => d.journalId === journalId)
		expect(donation.donor).toMatchObject({ kind: 'debtor', id: debtorId })
		expect(donation.receipt.type).toBe('einzel')

		const change = await api.raw(request, 'PUT', `/donations/${journalId}`, { data: { debtorId, waiver: true } })
		expect(change.status()).toBe(400)
		const remove = await api.raw(request, 'DELETE', `/debtors/${debtorId}`)
		expect(remove.ok()).toBe(false)

		const pdf = await api.raw(request, 'GET', `/donation-receipts/${donation.receipt.id}/pdf`)
		expect(pdf.status()).toBe(200)
		expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-')

		// Nach dem Stornieren lässt sich die Zuordnung wieder ändern.
		await api.raw(request, 'POST', `/donation-receipts/${donation.receipt.id}/void`, { data: { reason: 'E2E' } })
			.then((r) => expect(r.ok()).toBe(true))
		const again = await api.raw(request, 'PUT', `/donations/${journalId}`, { data: { debtorId, waiver: true } })
		expect(again.ok()).toBe(true)
	})
})
