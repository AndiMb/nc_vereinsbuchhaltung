import { expect, test } from '@playwright/test'
import { api, openApp, tabButton, USERS } from './fixtures/nextcloud.mjs'

// Übersetzungen: test5 nutzt Nextcloud auf Englisch. Bis 0.27.2 scheiterte
// das Nachladen des Sprachpakets still an Nextclouds .htaccess-Endungsliste,
// und die Oberfläche blieb deutsch – genau dieser Fall steht hier unter Test.
// Seit Issue #264 bindet Nextcloud das Paket selbst als l10n/en.js ein; den
// eigenen Endpunkt /api/l10n/{lang} gibt es nicht mehr.

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

	test('die Übersetzungen kommen als l10n/en.js, ohne eigenen Endpunkt', async ({ page }) => {
		const urls = []
		page.on('request', (r) => urls.push(r.url()))
		await openApp(page, USERS.englisch)
		await expect(tabButton(page, 'Overview')).toBeVisible()

		expect(urls.filter((u) => /\/vereinsbuchhaltung\/l10n\/en\.js(\?|$)/.test(u)).length).toBeGreaterThan(0)
		expect(urls.filter((u) => u.includes('/api/l10n/'))).toEqual([])
	})
})
