import { test, expect } from '@playwright/test'
import { api, openApp, USERS } from './fixtures/nextcloud.mjs'

// Onboarding-Rundreise: Beispielverein anlegen, Banner sehen, alles
// zurücksetzen – der Weg, den echte Erstnutzer nehmen.

test.describe('Beispielverein und Zurücksetzen', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
	})

	test('Beispieldaten über den Assistenten, dann Zurücksetzen über das Banner', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await page.getByRole('button', { name: 'Erst mit Beispieldaten ausprobieren' }).click()

		// Das Banner warnt vor den Spieldaten, der Bestand ist gefüllt.
		await expect(page.getByText('Beispieldaten aktiv.')).toBeVisible({ timeout: 15000 })
		expect((await api.listJournal(request)).length).toBeGreaterThan(0)

		// Zurücksetzen räumt alles ab.
		await page.getByRole('button', { name: 'Zurücksetzen & mit echten Daten starten' }).click()
		await page.getByRole('dialog').getByRole('button', { name: 'Löschen', exact: true }).click()

		await expect(page.getByText('Beispieldaten aktiv.')).toBeHidden({ timeout: 15000 })
		expect(await api.listJournal(request)).toHaveLength(0)
		expect(await api.listAccounts(request)).toHaveLength(0)
	})
})
