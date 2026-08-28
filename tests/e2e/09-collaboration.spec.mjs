import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, selectYear, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Kollaboration: die App pollt den Änderungsstand (alle 20 Sekunden) –
// was eine andere Person bucht, taucht ohne Neuladen im Journal auf.

test.describe('Mehrbenutzer-Sync', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('fremde Buchung erscheint ohne Neuladen im Journal', async ({ page, request }) => {
		test.setTimeout(60000)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await selectYear(page, 'Alle Jahre')

		// Der Buchhalter bucht in seinem eigenen Browser – hier per API.
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: '2026-03-01',
			description: 'Von zweiter Person gebucht',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 33,
			user: USERS.buchhalter,
		})

		// Ein Poll-Intervall abwarten, ohne selbst neu zu laden.
		await expect(visibleSection(page).getByText('Von zweiter Person gebucht').first()).toBeVisible({ timeout: 30000 })
	})
})
