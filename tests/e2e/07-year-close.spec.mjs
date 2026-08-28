import { test, expect } from '@playwright/test'
import { api, openApp, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Jahresabschluss: ein festgeschriebenes Jahr nimmt keine Buchungen mehr an
// (HTTP 423 auf API-Ebene), die Oberfläche markiert es mit einem Schloss,
// und die Wiedereröffnung hebt beides auf.

test.describe('Jahresabschluss', () => {
	let bank, income

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		;[bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: '2030-06-01',
			description: 'Buchung vor dem Abschluss',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 100,
		})
	})

	test('abgeschlossenes Jahr: Schloss in der Jahresauswahl, Buchung wird abgewiesen', async ({ page, request }) => {
		await api.closeYear(request, 2030)

		await openApp(page, USERS.verwalter)
		await expect(page.locator('.vbh-yearsel option', { hasText: '2030 🔒' })).toHaveCount(1)

		const resp = await api.createBooking(request, {
			date: '2030-07-01',
			description: 'Darf nicht durchgehen',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 5,
			expectOk: false,
		})
		expect(resp.status()).toBe(423)
	})

	test('Wiedereröffnung lässt Buchungen wieder zu', async ({ request }) => {
		await api.reopenYear(request, 2030)
		const resp = await api.createBooking(request, {
			date: '2030-07-01',
			description: 'Nach der Wiedereröffnung',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 5,
		})
		expect(resp.status()).toBe(201)
	})

	test('nur Verwalter dürfen abschließen', async ({ request }) => {
		const resp = await api.closeYear(request, 2030, { user: USERS.buchhalter, expectOk: false })
		expect(resp.status()).toBe(403)
	})
})
