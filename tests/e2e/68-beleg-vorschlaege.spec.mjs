import { expect, test } from '@playwright/test'
import { api, dav, neuAnlegen, openApp, skipBookingTour, USERS } from './fixtures/nextcloud.mjs'
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
