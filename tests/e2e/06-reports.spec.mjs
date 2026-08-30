import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Berichte auf Basis des Beispielvereins: die Berichts-Ansichten rendern,
// die druckfertigen Seiten und die CSV-Exporte liefern Inhalt.

test.describe('Berichte und Exporte', () => {
	let demoYear

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDemo(request)
		const years = await api.getJson(request, '/journal/years')
		demoYear = years[years.length - 1]
	})

	test('die Berichts-Ansichten rendern mit Daten', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')

		// exact: true - "Auswertung" traefe sonst als Teilzeichenkette auch
		// den Nachbarknopf "Auswertungsgruppen".
		await expect(visibleSection(page).getByRole('button', { name: 'Auswertung', exact: true })).toBeVisible()
		for (const view of ['Sphären', 'Rücklagen', 'Finanzplan', 'Protokoll']) {
			await visibleSection(page).getByRole('button', { name: view, exact: true }).click()
		}
		// Das Protokoll kennt mindestens das Anlegen der Beispieldaten.
		await expect(visibleSection(page).getByText('Beispieldaten angelegt').first()).toBeVisible()
	})

	test('Kassenbericht und Kurzbericht sind druckfertige Seiten', async ({ request }) => {
		for (const report of ['kassenbericht', 'kurzbericht']) {
			const resp = await api.raw(request, 'GET', `/export/${report}?year=${demoYear}`, { user: USERS.revisor })
			expect(resp.status(), report).toBe(200)
			expect(await resp.text()).toContain('<html')
		}
	})

	test('CSV-Exporte liefern Journal und Saldenliste', async ({ request }) => {
		for (const path of [`/export/journal?year=${demoYear}`, `/export/balances?year=${demoYear}`]) {
			const resp = await api.raw(request, 'GET', path, { user: USERS.revisor })
			expect(resp.status(), path).toBe(200)
			const body = await resp.text()
			expect(body.split('\n').length, path).toBeGreaterThan(2)
		}
	})
})
