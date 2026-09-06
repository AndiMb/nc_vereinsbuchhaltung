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

	// Issue #34: die Planwerte standen als nackte Zahl ("20000") neben den
	// formatierten Spalten "Ist" und "Differenz". Geprueft wird die ganze
	// Kette: eingetippt wird wie ein Mensch tippt, angezeigt wird formatiert,
	// und beim Neuladen ist der Wert auch wirklich im Backend angekommen.
	test('Planwerte werden formatiert angezeigt und eingelesen', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Finanzplan', exact: true }).click()

		const feld = visibleSection(page).locator('.vbh-planinput').first()
		await expect(feld).toBeVisible()

		// Tausenderpunkt: eingetippt "20000", angezeigt "20.000,00 €".
		await feld.fill('20000')
		await feld.blur()
		await expect(feld).toHaveValue(/^20\.000,00\s*€$/)

		// Beim Bearbeiten steht der nackte Wert da - niemand soll gegen eine
		// mitlaufende Maske antippen muessen.
		await feld.focus()
		await expect(feld).toHaveValue('20000')
		await feld.blur()

		// Deutsche Schreibweise mit Punkt UND Komma wird richtig gelesen.
		await feld.fill('50.000,50')
		await feld.blur()
		await expect(feld).toHaveValue(/^50\.000,50\s*€$/)

		// Unlesbares springt auf den letzten gueltigen Wert zurueck, statt
		// den Planwert stillschweigend auf 0 zu setzen.
		await feld.fill('keine Zahl')
		await feld.blur()
		await expect(feld).toHaveValue(/^50\.000,50\s*€$/)

		// Gespeichert wird auch wirklich.
		await page.reload()
		await page.locator('.vbh').waitFor()
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Finanzplan', exact: true }).click()
		await expect(visibleSection(page).locator('.vbh-planinput').first()).toHaveValue(/^50\.000,50\s*€$/)
	})
})
