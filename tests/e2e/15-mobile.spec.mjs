import { test, expect } from '@playwright/test'
import { api, openApp, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Die Ansicht für unterwegs: untere Navigationsleiste, Buchungen als
// Karten statt Tabelle, schwebender Buchen-Knopf.

test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

test.describe('Mobile Ansicht', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: '2026-05-05',
			description: 'Mobil sichtbare Buchung',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 15,
		})
	})

	test('untere Navigation führt zu den Buchungs-Karten', async ({ page }) => {
		await openApp(page, USERS.verwalter)

		const bottomNav = page.locator('.vbh-bottomnav')
		await expect(bottomNav).toBeVisible()
		await expect(page.locator('.vbh-fab')).toBeVisible()

		await bottomNav.getByRole('button', { name: 'Buchungen' }).click()
		// Gescopt auf den sichtbaren Abschnitt: die Übersicht hält dieselbe
		// Buchung als (per v-show versteckte) Karte ebenfalls im DOM.
		await expect(visibleSection(page).locator('.vbh-mcard', { hasText: 'Mobil sichtbare Buchung' }).first()).toBeVisible()
	})
})
