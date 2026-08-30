import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BELEG_PNG, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

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
