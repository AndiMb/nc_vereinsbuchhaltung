import { expect, test } from '@playwright/test'
import { api, authHeaders, BASE_URL, openApp, tabButton, USERS } from './fixtures/nextcloud.mjs'

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

// Niederländisch (Issue #279): die Oberfläche kommt aus l10n/nl.js, das
// Handbuch gibt es nur auf Deutsch und Englisch - wer weder Deutsch noch
// Englisch eingestellt hat, bekommt die englische Fassung.
async function setUserLanguage(request, uid, language) {
	const resp = await request.fetch(`${BASE_URL}/ocs/v2.php/cloud/users/${uid}?format=json`, {
		method: 'PUT',
		headers: authHeaders('admin'),
		data: { key: 'language', value: language },
	})
	expect(resp.ok(), `Sprache ${language} für ${uid} setzen`).toBe(true)
}

test.describe('Niederländische Oberfläche', () => {
	test.beforeAll(async ({ request }) => {
		await api.setRole(request, USERS.englisch, 'revisor')
		await setUserLanguage(request, USERS.englisch, 'nl')
	})

	test.afterAll(async ({ request }) => {
		await setUserLanguage(request, USERS.englisch, 'en')
	})

	test('die Tabs erscheinen auf Niederländisch, das Paket kommt als l10n/nl.js', async ({ page }) => {
		const urls = []
		page.on('request', (r) => urls.push(r.url()))
		await openApp(page, USERS.englisch)
		for (const tab of ['Overzicht', 'Boekingen', 'Rekeningen', 'Rapporten']) {
			await expect(tabButton(page, tab)).toBeVisible()
		}
		expect(urls.filter((u) => /\/vereinsbuchhaltung\/l10n\/nl\.js(\?|$)/.test(u)).length).toBeGreaterThan(0)
	})

	test('das Handbuch kommt auf Englisch', async ({ request }) => {
		const resp = await api.raw(request, 'GET', '/help/handbuch', { user: USERS.englisch })
		expect(resp.status()).toBe(200)
		const html = await resp.text()
		expect(html).toContain('<html lang="en"')
		expect(html).toContain('Vereinsbuchhaltung Manual')
	})
})
