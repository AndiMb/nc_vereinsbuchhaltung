import { test, expect } from '@playwright/test'
import { api, openApp, tabButton, USERS, BASE_URL } from './fixtures/nextcloud.mjs'

// Grundgerüst: die App lädt, zeigt die Navigation, und die Zugriffskontrolle
// greift an der Oberfläche. Bestand: leer.

test.describe('App-Grundgerüst', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
	})

	test('Verwalter sieht die App-Shell mit allen Tabs', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await expect(page.getByRole('heading', { name: 'Vereinsbuchhaltung' })).toBeVisible()

		// Auf leerem Bestand begrüßt der Setup-Assistent – erst schließen,
		// dann ist die Navigation frei.
		await expect(page.getByText('Willkommen bei der Vereinsbuchhaltung!')).toBeVisible()
		await page.getByRole('button', { name: 'Überspringen, ich schaue mich selbst um' }).click()

		for (const tab of ['Übersicht', 'Buchungen', 'Konten', 'Berichte']) {
			await expect(tabButton(page, tab)).toBeVisible()
		}
		// Das Beitragsmodul ist aus – sein Tab existiert nicht.
		await expect(tabButton(page, 'Beiträge')).toHaveCount(0)
	})

	test('Nutzer ohne Rolle sieht nur "Kein Zugriff"', async ({ page }) => {
		await openApp(page, USERS.ohneRolle)
		await expect(page.getByRole('heading', { name: 'Kein Zugriff' })).toBeVisible()
		await expect(page.locator('.vbh-tabs')).toHaveCount(0)
	})

	test('ohne Anmeldung leitet die App zur Login-Seite um', async ({ page }) => {
		await page.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/`)
		await expect(page).toHaveURL(/\/login/)
	})
})
