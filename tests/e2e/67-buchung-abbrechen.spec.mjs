import { expect, test } from '@playwright/test'
import { api, neuAnlegen, openApp, skipBookingTour, USERS } from './fixtures/nextcloud.mjs'

// "Abbrechen" fragt nur nach, wenn der Mensch etwas eingegeben hat – nicht,
// wenn die App das Standard-Geldkonto erst nach dem Oeffnen nachtraegt.

test.describe('Buchungsdialog abbrechen', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test.afterAll(async ({ request }) => {
		await api.resetBook(request)
	})

	test('Abbrechen ohne Eingabe fragt nicht nach, auch wenn das Geldkonto nachgeladen wird', async ({ page }) => {
		// Konten verzoegert laden: Der Dialog oeffnet, bevor das Standard-Geldkonto feststeht.
		await page.route(/\/apps\/vereinsbuchhaltung\/api\/accounts(\?|$)/, (r) => {
			if (r.request().method() === 'GET') {
				setTimeout(() => r.continue(), 2500)
			} else {
				r.continue()
			}
		})
		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const dialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await skipBookingTour(dialog)
		await expect(dialog.getByText('1000 Kasse').first()).toBeVisible({ timeout: 10000 })

		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(page.getByRole('dialog')).toHaveCount(0)
		await expect(page.getByText('Änderungen verwerfen?')).toHaveCount(0)
	})

	test('Abbrechen nach Eingabe im Buchungstext fragt weiterhin nach', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const dialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await skipBookingTour(dialog)
		await expect(dialog.getByText('1000 Kasse').first()).toBeVisible()
		await dialog.getByLabel('Buchungstext').fill('getippt')

		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(page.getByText('Änderungen verwerfen?')).toBeVisible()
	})
})
