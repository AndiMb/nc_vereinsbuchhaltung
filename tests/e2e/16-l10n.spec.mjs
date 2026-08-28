import { test, expect } from '@playwright/test'
import { api, openApp, tabButton, USERS } from './fixtures/nextcloud.mjs'

// Übersetzungen: test5 nutzt Nextcloud auf Englisch. Bis 0.27.2 scheiterte
// das Nachladen des Sprachpakets still an Nextclouds .htaccess-Endungsliste,
// und die Oberfläche blieb deutsch – genau dieser Fall steht hier unter Test.

test.describe('Englische Oberfläche', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// test5 braucht eine Leserolle, sonst gibt es nur die Zugriffs-Meldung.
		await api.setRole(request, USERS.englisch, 'revisor')
	})

	test('die Tabs erscheinen auf Englisch', async ({ page }) => {
		await openApp(page, USERS.englisch)
		for (const tab of ['Overview', 'Entries', 'Accounts', 'Reports']) {
			await expect(tabButton(page, tab)).toBeVisible()
		}
	})

	test('der Übersetzungs-Endpunkt liefert Bündel und weist Unfug ab', async ({ request }) => {
		const en = await api.raw(request, 'GET', '/l10n/en', { user: USERS.englisch })
		expect(en.status()).toBe(200)
		const bundle = await en.json()
		expect(Object.keys(bundle.translations).length).toBeGreaterThan(100)

		// Ein Sprachcode ist ein Sprachcode – anderes weist der Endpunkt ab.
		const evil = await api.raw(request, 'GET', '/l10n/xx123', { user: USERS.englisch })
		expect(evil.status()).toBe(400)
	})
})
