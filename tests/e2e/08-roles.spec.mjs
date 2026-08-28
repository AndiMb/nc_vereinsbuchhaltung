import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, tabButton, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Rollen: der Revisor liest alles und ändert nichts, der Buchhalter bucht,
// aber verwaltet keine Rechte – geprüft in der Oberfläche UND direkt an der
// API (die Middleware ist die eigentliche Verteidigungslinie).

test.describe('Rollen und Berechtigungen', () => {
	let bank, income

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		;[bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: '2026-02-01',
			description: 'Sichtbar für alle Rollen',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 10,
		})
	})

	test('Revisor: Lesezugriff mit Intro, aber ohne Schreib-Bedienelemente', async ({ page }) => {
		await openApp(page, USERS.revisor)
		await expect(page.getByText('Willkommen als Kassenprüfer/in')).toBeVisible()
		await page.getByRole('button', { name: 'Verstanden' }).click()

		// Lesen geht …
		await switchTab(page, 'Buchungen')
		await expect(visibleSection(page).getByText('Sichtbar für alle Rollen').first()).toBeVisible()

		// … aber es gibt weder den Buchen-Knopf noch den Beiträge-Tab.
		await expect(page.locator('.vbh-newbooking-btn')).toHaveCount(0)
		await expect(tabButton(page, 'Beiträge')).toHaveCount(0)
	})

	test('Revisor: Schreibversuche prallen an der API ab (403)', async ({ request }) => {
		const resp = await api.createBooking(request, {
			date: '2026-02-02',
			description: 'Revisor darf das nicht',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 1,
			user: USERS.revisor,
			expectOk: false,
		})
		expect(resp.status()).toBe(403)

		const reset = await api.raw(request, 'POST', '/reset', { user: USERS.revisor })
		expect(reset.status()).toBe(403)
	})

	test('Buchhalter: bucht, verwaltet aber keine Berechtigungen', async ({ request }) => {
		const booking = await api.createBooking(request, {
			date: '2026-02-03',
			description: 'Buchhalter bucht',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 20,
			user: USERS.buchhalter,
		})
		expect(booking.status()).toBe(201)

		const setRole = await api.setRole(request, USERS.ohneRolle, 'revisor', { user: USERS.buchhalter, expectOk: false })
		expect(setRole.status()).toBe(403)
	})

	test('ohne Rolle: nicht einmal Lesen (403)', async ({ request }) => {
		const resp = await api.raw(request, 'GET', '/accounts', { user: USERS.ohneRolle })
		expect(resp.status()).toBe(403)
	})
})
