import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, dav, INCOME_ACCOUNT, neuAnlegen, openApp, skipBookingTour, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'
import { textPdf } from './fixtures/textPdf.mjs'

// Belegvorschläge (#222): die Seitenleiste „Aus Ordner wählen" zeigt oben die
// Dateien, die zur Buchung im Formular passen – mit den Gründen.

const FOLDER = 'E2E-Vorschlaege'

test.describe('Belegvorschläge im Buchungsdialog', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await dav.mkcol(request, 'admin', FOLDER)
		await api.updateSettings(request, { storage_mode: 'watch', storage_user: 'admin', storage_path: FOLDER })
	})

	test.afterAll(async ({ request }) => {
		await api.resetBook(request)
		await api.updateSettings(request, { storage_mode: 'appdata', storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
		await dav.remove(request, 'admin', FOLDER)
	})

	test('Seitenleiste zeigt den passenden Beleg oben mit Gründen (#222)', async ({ page, request }) => {
		await dav.put(request, 'admin', `${FOLDER}/RG-28-26-test.pdf`, textPdf(['Rechnung', 'Gesamt 119,00 EUR']), 'application/pdf')
		await dav.put(request, 'admin', `${FOLDER}/andere-datei.pdf`, textPdf(['Quittung', 'Gesamt 5,00 EUR']), 'application/pdf')

		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const bookingDialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await skipBookingTour(bookingDialog)
		await expect(bookingDialog.locator(':focus')).toHaveCount(1)
		await bookingDialog.getByRole('button', { name: 'Ausgabe', exact: true }).click()
		await bookingDialog.getByLabel('Betrag (€)', { exact: true }).fill('119')
		await bookingDialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill('ReNr 28/26')

		await bookingDialog.getByRole('button', { name: 'Aus Ordner wählen' }).click()
		const folderPanel = bookingDialog.getByRole('complementary', { name: 'Beleg aus Ordner wählen' })
		await expect(folderPanel).toBeVisible()

		const section = folderPanel.getByRole('region', { name: 'Vorschläge' })
		const item = section.locator('.vbh-suggest-item', { hasText: 'RG-28-26-test.pdf' })
		await expect(item).toBeVisible()
		await expect(item).toContainText('Rechnungsnr. 28/26 im Dateinamen')
		await expect(item).toContainText('Betrag 119,00 € im Beleg')
		await expect(section.locator('.vbh-suggest-item')).toHaveCount(1)

		// Ein Klick übernimmt die Datei in die Warteliste, der Vorschlag verschwindet.
		await item.getByRole('button', { name: /Auswählen: RG-28-26-test.pdf/ }).click()
		await expect(bookingDialog.locator('.vbh-booking-main').getByText('RG-28-26-test.pdf')).toBeVisible()
		await expect(section).toBeHidden()
	})
})

// Buchungsliste (#229): Symbole je Stufe und Sammelbestätigung.

async function seedBooking(request, description, amount, incomeId, bankId) {
	return (await api.createBooking(request, {
		date: '2026-10-05',
		description,
		debitAccountId: bankId,
		creditAccountId: incomeId,
		amount,
	})).json()
}

async function resetWithBookings(request, bookings) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
	for (const [description, amount] of bookings) {
		await seedBooking(request, description, amount, income.id, bank.id)
	}
}

function bookingRow(page, text) {
	return visibleSection(page).locator('tr[data-id]', { hasText: text })
}

test.describe('Belegvorschläge in der Buchungsliste', () => {
	test.beforeAll(async ({ request }) => {
		await dav.mkcol(request, 'admin', FOLDER)
		await api.updateSettings(request, { storage_mode: 'watch', storage_user: 'admin', storage_path: FOLDER })
	})

	test.afterAll(async ({ request }) => {
		await api.resetBook(request)
		await api.updateSettings(request, { storage_mode: 'appdata', storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
		await dav.remove(request, 'admin', FOLDER)
	})

	test('Buchungsliste zeigt sichere und mögliche Vorschläge (#229)', async ({ page, request }) => {
		await resetWithBookings(request, [['ReNr 28/26', 119], ['ReNr 77/26', 50], ['Ohne Bezug', 12]])
		await dav.put(request, 'admin', `${FOLDER}/RG-28-26-test.pdf`, textPdf(['Rechnung', 'Gesamt 119,00 EUR']), 'application/pdf')
		await dav.put(request, 'admin', `${FOLDER}/RG-77-26.pdf`, textPdf(['Rechnung', 'Gesamt 99,00 EUR']), 'application/pdf')

		await page.setViewportSize({ width: 1920, height: 1080 })
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')

		const sure = bookingRow(page, 'ReNr 28/26').getByRole('button', { name: 'Passender Beleg gefunden: RG-28-26-test.pdf' })
		await expect(sure).toBeVisible()
		await expect(bookingRow(page, 'ReNr 77/26').getByRole('button', { name: '1 möglicher Beleg' })).toBeVisible()
		await expect(bookingRow(page, 'Ohne Bezug').getByRole('button', { name: /Beleg/ })).toHaveCount(0)

		// Ein Klick öffnet die Buchung mit offener Seitenleiste, der Vorschlag steht oben –
		// ohne die gemerkte Wahl (#230) zu überschreiben.
		await sure.click()
		const bookingDialog = page.getByRole('dialog', { name: /Buchung bearbeiten/ })
		await skipBookingTour(bookingDialog)
		const folderPanel = bookingDialog.getByRole('complementary', { name: 'Beleg aus Ordner wählen' })
		await expect(folderPanel).toBeVisible()
		await expect(folderPanel.getByRole('region', { name: 'Vorschläge' }).locator('.vbh-suggest-item').first()).toContainText('RG-28-26-test.pdf')
		expect(await page.evaluate(() => localStorage.getItem('vbh_booking_folder_panel'))).toBeNull()
	})

	test('Sammelbestätigung ordnet alle sicheren Belege zu', async ({ page, request }) => {
		await resetWithBookings(request, [['ReNr 28/26', 119], ['ReNr 41/26', 30], ['ReNr 77/26', 50]])
		await dav.put(request, 'admin', `${FOLDER}/RG-28-26-test.pdf`, textPdf(['Rechnung', 'Gesamt 119,00 EUR']), 'application/pdf')
		await dav.put(request, 'admin', `${FOLDER}/RG-41-26.pdf`, textPdf(['Rechnung', 'Gesamt 30,00 EUR']), 'application/pdf')
		await dav.put(request, 'admin', `${FOLDER}/RG-77-26.pdf`, textPdf(['Rechnung', 'Gesamt 99,00 EUR']), 'application/pdf')

		await page.setViewportSize({ width: 1920, height: 1080 })
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')

		const bulk = visibleSection(page).getByRole('button', { name: '2 Belege zuordnen' })
		await expect(bulk).toBeVisible()
		await bulk.click()
		const dialog = page.getByRole('dialog', { name: 'Passende Belege zuordnen' })
		await expect(dialog.getByRole('checkbox')).toHaveCount(2)
		await expect(dialog).toContainText('RG-28-26-test.pdf')
		await expect(dialog).toContainText('RG-41-26.pdf')
		await dialog.getByRole('button', { name: 'Zuordnen', exact: true }).click()
		await expect(dialog).toBeHidden()

		await expect(bookingRow(page, 'ReNr 28/26').getByRole('button', { name: '1 Beleg(e)' })).toBeVisible()
		await expect(bookingRow(page, 'ReNr 41/26').getByRole('button', { name: '1 Beleg(e)' })).toBeVisible()
		await expect(visibleSection(page).getByRole('button', { name: /Belege? zuordnen/ })).toHaveCount(0)
		// Die mögliche Buchung behält ihr gelbes Symbol.
		await expect(bookingRow(page, 'ReNr 77/26').getByRole('button', { name: '1 möglicher Beleg' })).toBeVisible()
	})

	test('Revisor sieht weder Symbole noch Sammelknopf', async ({ page, request }) => {
		await resetWithBookings(request, [['ReNr 28/26', 119]])
		await dav.put(request, 'admin', `${FOLDER}/RG-28-26-test.pdf`, textPdf(['Rechnung', 'Gesamt 119,00 EUR']), 'application/pdf')

		await openApp(page, USERS.revisor)
		const intro = page.getByRole('button', { name: 'Verstanden' })
		if (await intro.isVisible().catch(() => false)) { await intro.click() }
		await switchTab(page, 'Buchungen')
		await expect(bookingRow(page, 'ReNr 28/26')).toBeVisible()
		await expect(visibleSection(page).getByRole('button', { name: /Passender Beleg|möglicher? Belege?|Belege? zuordnen/ })).toHaveCount(0)
	})
})
