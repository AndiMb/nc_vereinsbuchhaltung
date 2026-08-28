import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Offene Posten: unbezahlte Forderungen anlegen, in der Oberfläche sehen
// und über ihren Lebenszyklus führen (bezahlt, storniert, wiedereröffnet).

test.describe('Offene Posten', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('angelegter Posten erscheint in der Oberfläche', async ({ page, request }) => {
		await api.createOpenItem(request, {
			debtor: 'Max Mustermann',
			description: 'Beitrag 2026',
			amount: 60,
			dueDate: '2026-05-01',
		})

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Offene Posten' }).click()
		await expect(visibleSection(page).getByText('Max Mustermann').first()).toBeVisible()
	})

	test('bezahlt, storniert und wiedereröffnet (API)', async ({ request }) => {
		const paidItem = await api.createOpenItem(request, {
			debtor: 'Anna Schmidt', description: 'Beitrag', amount: 30, dueDate: '2026-06-01',
		})
		expect((await api.raw(request, 'POST', `/open-items/${paidItem.id}/pay`)).status()).toBe(200)

		const cancelItem = await api.createOpenItem(request, {
			debtor: 'Kurt Krause', description: 'Beitrag', amount: 30, dueDate: '2026-06-01',
		})
		expect((await api.raw(request, 'POST', `/open-items/${cancelItem.id}/cancel`)).status()).toBe(200)
		expect((await api.raw(request, 'POST', `/open-items/${cancelItem.id}/reopen`)).status()).toBe(200)

		const items = await api.getJson(request, '/open-items')
		expect(items.find((i) => i.id === paidItem.id).status).toBe('paid')
		expect(items.find((i) => i.id === cancelItem.id).status).toBe('open')
	})
})
