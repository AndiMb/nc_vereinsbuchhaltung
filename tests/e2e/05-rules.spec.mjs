import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, fixture, CAMT_STATEMENT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Auto-Zuordnungsregeln: eine Regel auf den Verwendungszweck ordnet
// wiederkehrende Umsätze schon beim Import zu.

test.describe('Zuordnungsregeln', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('Regel greift beim Import und ordnet automatisch zu', async ({ page, request }) => {
		const income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.createRule(request, {
			matchField: 'purpose',
			matchValue: 'Mitgliedsbeitrag',
			contraAccountId: income.id,
		})

		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.new).toBe(CAMT_STATEMENT.txCount)
		expect(result.autoAssigned).toBe(CAMT_STATEMENT.memberFeeCount)

		// Die automatisch zugeordneten Umsätze stehen als Buchungen im Journal.
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await expect(visibleSection(page).getByText('Mitgliedsbeitrag 2026 Max Mustermann').first()).toBeVisible()
	})

	test('Regel ist im Regel-Bereich sichtbar', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Regeln' }).click()
		await expect(visibleSection(page).getByText('Mitgliedsbeitrag').first()).toBeVisible()
	})
})
