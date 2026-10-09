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

	test('Reiter-Badge, Chip-Zähler und Überfällig-Filter nennen dieselben Zahlen', async ({ page, request }) => {
		await api.createOpenItem(request, { debtor: 'Lena Altschuld', description: 'Lange fällig', amount: 12, dueDate: '2020-01-01' })
		await api.createOpenItem(request, { debtor: 'Paul Zukunft', description: 'Noch Zeit', amount: 12, dueDate: '2099-01-01' })

		const items = await api.getJson(request, '/open-items')
		const open = items.filter((i) => i.status === 'open').length
		const overdue = items.filter((i) => i.status === 'open' && i.overdue).length
		expect(overdue).toBeGreaterThan(0)
		expect(open).toBeGreaterThan(overdue)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		const tab = visibleSection(page).getByRole('button', { name: 'Offene Posten' })
		// Der rote Badge am Reiter zählt nur das Überfällige und sagt das im Tooltip.
		await expect(tab.locator('.vbh-badge')).toHaveText(String(overdue))
		await expect(tab.locator('.vbh-badge')).toHaveAttribute('title', /überfällig/)
		await tab.click()

		// Die Chips nennen ihre Zähler: „Offen“ alle offenen, „Überfällig“ genau die Badge-Zahl.
		const chip = (label) => visibleSection(page).locator('.vbh-chip', { hasText: new RegExp(`^${label}\\s*\\d+$`) })
		await expect(chip('Offen')).toHaveText(new RegExp(`^\\D+${open}$`))
		await expect(chip('Überfällig')).toHaveText(new RegExp(`^\\D+${overdue}$`))

		await chip('Überfällig').click()
		const rows = visibleSection(page).locator('tbody tr')
		await expect(rows).toHaveCount(overdue)
		await expect(rows.filter({ hasText: 'Lena Altschuld' })).toHaveCount(1)
		await expect(rows.filter({ hasText: 'Paul Zukunft' })).toHaveCount(0)
		// Beim Wechsel zurück auf „Offen“ erscheinen auch die noch nicht fälligen.
		await chip('Offen').click()
		await expect(rows).toHaveCount(open)
		await expect(rows.filter({ hasText: 'Paul Zukunft' })).toHaveCount(1)
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
