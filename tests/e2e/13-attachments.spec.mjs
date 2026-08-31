import { test, expect } from '@playwright/test'
import { api, openApp, pickNcSelectOption, switchTab, visibleSection, BANK_ACCOUNT, BELEG_PNG, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Belegablage (app-intern): Beleg an eine Buchung hängen, Büroklammer im
// Journal, Download und das Prüf-ZIP für die Kassenprüfung.

async function ensureBookingWithAttachment(request) {
	const journal = await api.listJournal(request)
	let booking = journal.find((j) => j.description === 'Buchung mit Beleg')
	if (!booking) {
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		booking = await (await api.createBooking(request, {
			date: '2026-04-10',
			description: 'Buchung mit Beleg',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 75,
		})).json()
	}

	let [attachment] = await api.listAttachments(request, booking.id)
	if (!attachment) {
		attachment = await api.addAttachment(request, booking.id)
	}
	return { booking, attachment }
}

test.describe('Belegablage', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('Beleg hängt an der Buchung und zeigt sich als Büroklammer', async ({ page, request }) => {
		const { booking } = await ensureBookingWithAttachment(request)

		const counts = await api.getJson(request, '/attachments/counts')
		expect(counts[booking.id]?.count).toBe(1)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		const row = visibleSection(page).locator('tr', { hasText: 'Buchung mit Beleg' }).first()
		await expect(row.getByRole('button', { name: /Beleg/ })).toBeVisible()
	})

	test('Beleg schon beim Anlegen: falscher Typ fliegt raus, der Rest hängt nach dem Buchen dran', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)

		await page.getByRole('button', { name: 'Buchung', exact: true }).click()
		const dialog = page.getByRole('dialog')
		await dialog.getByRole('button', { name: 'Einnahme' }).click()
		const tourSkip = dialog.getByRole('button', { name: 'Überspringen', exact: true })
		if (await tourSkip.isVisible().catch(() => false)) {
			await tourSkip.click()
		}

		await dialog.locator('input[type="number"]').fill('42')
		await pickNcSelectOption(dialog, '– Kategorie wählen –', 'Mitgliedsbeiträge')
		await dialog.locator('input[type="date"]').fill('2026-05-04')
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill('Beleg direkt beim Anlegen')

		// Was der Server ablehnen wuerde, faellt schon hier auf – sonst kaeme das
		// Nein erst, wenn die Buchung bereits steht.
		const fileInput = dialog.locator('input[type="file"]')
		await fileInput.setInputFiles({ name: 'notiz.txt', mimeType: 'text/plain', buffer: Buffer.from('kein Beleg') })
		await expect(page.getByText(/Dieser Dateityp geht nicht/)).toBeVisible()
		await expect(dialog.getByText('notiz.txt')).toHaveCount(0)

		await fileInput.setInputFiles({ name: 'anlage.png', mimeType: 'image/png', buffer: BELEG_PNG })
		await expect(dialog.getByText('anlage.png')).toBeVisible()

		await dialog.getByRole('button', { name: 'Buchen', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		await switchTab(page, 'Buchungen')
		const row = visibleSection(page).locator('tr', { hasText: 'Beleg direkt beim Anlegen' }).first()
		await expect(row.getByRole('button', { name: /Beleg/ })).toBeVisible({ timeout: 15000 })

		const booking = (await api.listJournal(request)).find((j) => j.description === 'Beleg direkt beim Anlegen')
		const attachments = await api.listAttachments(request, booking.id)
		expect(attachments.map((a) => a.fileName)).toEqual(['anlage.png'])
	})

	test('Beleg lässt sich herunterladen, das Prüf-ZIP kommt an', async ({ request }) => {
		const { attachment } = await ensureBookingWithAttachment(request)

		const download = await api.raw(request, 'GET', `/attachments/${attachment.id}/download`)
		expect(download.status()).toBe(200)
		expect((await download.body()).length).toBe(BELEG_PNG.length)

		const zip = await api.raw(request, 'GET', '/export/attachments?year=2026', { user: USERS.revisor })
		expect(zip.status()).toBe(200)
		expect((await zip.body()).slice(0, 2).toString()).toBe('PK')
	})
})
