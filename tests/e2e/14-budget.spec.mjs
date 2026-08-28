import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Finanzplan: Planwert setzen, im Berichts-Tab wiederfinden und einen
// eingefrorenen Planstand (Snapshot) anlegen und abrufen.

test.describe('Finanzplan', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.raw(request, 'POST', '/budget', {
			data: { accountId: income.id, year: 2026, amount: 500, note: 'Planwert für Beiträge' },
		})
	})

	test('gesetzter Planwert erscheint im Finanzplan', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Finanzplan' }).click()
		await expect(visibleSection(page).getByText('500,00').first()).toBeVisible()
	})

	test('Planstand einfrieren und wieder abrufen (API)', async ({ request }) => {
		const created = await api.raw(request, 'POST', '/budget/snapshots', {
			data: { year: 2026, label: 'Stand Mitgliederversammlung' },
		})
		expect(created.status()).toBe(200)
		const snapshot = await created.json()

		const list = await api.getJson(request, '/budget/snapshots?year=2026')
		expect(list.some((s) => s.id === snapshot.id)).toBe(true)

		const detail = await api.raw(request, 'GET', `/budget/snapshots/${snapshot.id}`)
		expect(detail.status()).toBe(200)
	})
})
