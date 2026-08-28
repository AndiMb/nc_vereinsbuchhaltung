import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Erster Start: der Setup-Assistent legt den Standard-Kontenrahmen an,
// und der Konten-Tab zeigt ihn.

test.describe('Setup-Assistent', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
	})

	test('"Ich fange neu an" legt den Standard-Kontenrahmen an', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await expect(page.getByText('Willkommen bei der Vereinsbuchhaltung!')).toBeVisible()
		await page.getByRole('button', { name: 'Ich fange neu an' }).click()

		// Der Assistent schließt sich, der Kontenrahmen ist da.
		await expect(page.getByText('Willkommen bei der Vereinsbuchhaltung!')).toBeHidden()
		await switchTab(page, 'Konten')
		await expect(visibleSection(page).getByText('Bankkonto').first()).toBeVisible()
		await expect(visibleSection(page).getByText('Mitgliedsbeiträge').first()).toBeVisible()

		const accounts = await api.listAccounts(request)
		expect(accounts.length).toBeGreaterThan(10)
		expect(accounts.some((a) => a.isBank)).toBe(true)
	})
})
