import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Gliederungsbericht: mitgelieferte Vorlagen auswerten, eine Kopie anlegen und
// ihre Struktur umstellen; mitgelieferte Vorlagen bleiben schreibgeschützt,
// ein Revisor liest nur.

test.describe('Gliederungsbericht', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		// Der Standard-Kontenrahmen bringt Sphären mit; 4000 ist ideell.
		await api.createBooking(request, {
			date: new Date().toISOString().slice(0, 10),
			description: 'Beitrag für den Gliederungsbericht',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 120,
		})
	})

	test('mitgelieferte Vorlagen rechnen den Saldo aus (API)', async ({ request }) => {
		const list = await api.getJson(request, '/report-templates')
		expect(list.templates.filter((t) => t.builtin).map((t) => t.id)).toEqual(['builtin:zuwendungsgeber', 'builtin:bereiche'])

		const report = await api.getJson(request, '/report/structured?template=builtin:bereiche')
		const labels = report.rows.map((r) => r.label)
		expect(labels).toContain('Ergebnis ideeller Bereich')
		const total = report.rows[report.rows.length - 1]
		expect(total.label).toBe('Saldo')
		// Spalte 4 ist „Ist" der gewählten Periode
		expect(total.values[3]).toBe(12000)
	})

	test('mitgelieferte Vorlagen sind schreibgeschützt, ungültige werden abgelehnt (API)', async ({ request }) => {
		const builtin = await api.raw(request, 'PUT', '/report-templates/builtin:bereiche', { data: { name: 'X', definition: {} } })
		expect(builtin.status()).toBe(403)

		const invalid = await api.raw(request, 'POST', '/report-templates', { data: { name: 'Leer', definition: { sections: [] } } })
		expect(invalid.status()).toBe(422)
		expect((await invalid.json()).errors).toContain('Die Vorlage braucht mindestens eine Spalte.')

		const revisor = await api.raw(request, 'POST', '/report-templates', {
			user: USERS.revisor,
			data: { name: 'Revisor', definition: { columns: [{ source: 'actual' }], sections: [{ levels: [{ by: 'type' }] }] } },
		})
		expect(revisor.status()).toBe(403)
	})

	test('Kopie anlegen und Abschnitte umstellen', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Gliederung', exact: true }).click()
		const table = visibleSection(page).locator('.vbh-structured-table')
		await expect(table).toBeVisible()
		await expect(table.getByText('Differenz', { exact: true }).last()).toBeVisible()

		await visibleSection(page).getByRole('button', { name: 'Kopieren und anpassen' }).click()
		const dialog = page.locator('.vbh-rtpl')
		await expect(dialog).toBeVisible()
		await dialog.getByRole('tab', { name: 'Struktur' }).click()
		// „Ausgaben" vor „Einnahmen"
		await dialog.getByRole('button', { name: 'Ausgaben nach oben' }).click()
		await dialog.getByRole('button', { name: 'Anlegen' }).click()
		await expect(dialog).toHaveCount(0)

		await expect(visibleSection(page).locator('.vbh-structured-picker')).toContainText('(Kopie)')
		await expect(table.locator('.vbh-structured-top').first()).toContainText('Ausgaben')
		await expect(visibleSection(page).getByRole('button', { name: 'Löschen' })).toBeVisible()
		await expect(visibleSection(page).getByRole('link', { name: 'Drucken / PDF' })).toHaveAttribute('href', /export\/gliederungsbericht\?template=\d+/)
	})

	test('Revisor sieht den Bericht, aber keine Bearbeiten-Knöpfe', async ({ page }) => {
		await openApp(page, USERS.revisor)
		const intro = page.getByRole('button', { name: 'Verstanden' })
		if (await intro.isVisible().catch(() => false)) { await intro.click() }
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Gliederung', exact: true }).click()
		await expect(visibleSection(page).locator('.vbh-structured-table')).toBeVisible()
		await expect(visibleSection(page).getByRole('button', { name: 'Kopieren und anpassen' })).toHaveCount(0)
	})
})
