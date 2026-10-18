import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Gliederungsbericht: die Standardvorlagen entstehen beim ersten Abruf als
// gewöhnliche Vorlagen, lassen sich bearbeiten, löschen und zurückholen; eine
// Vorlage lässt sich auch bei null anlegen. Ein Revisor liest nur.

const GRANTOR = 'Haushaltsplan nach Titelgruppen (Zuwendungsgeber)'
const AREAS = 'Ergebnis je Bereich und Projekt'

async function openStructure(page, user) {
	await openApp(page, user)
	const intro = page.getByRole('button', { name: 'Verstanden' })
	if (await intro.isVisible().catch(() => false)) { await intro.click() }
	await switchTab(page, 'Berichte')
	await visibleSection(page).getByRole('button', { name: 'Gliederung', exact: true }).click()
}

test.describe('Gliederungsbericht', () => {
	test.beforeAll(async ({ request }) => {
		// Der Reset setzt auch den Vermerk zurück: der nächste Abruf legt die
		// Standardvorlagen neu an.
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

	test('Standardvorlagen entstehen einmal als gewöhnliche Vorlagen (API)', async ({ request }) => {
		const first = await api.getJson(request, '/report-templates')
		expect(first.templates.map((t) => t.name)).toEqual([GRANTOR, AREAS])
		expect(first.templates.every((t) => /^\d+$/.test(t.id))).toBe(true)

		const second = await api.getJson(request, '/report-templates')
		expect(second.templates.map((t) => t.id)).toEqual(first.templates.map((t) => t.id))

		const areas = first.templates.find((t) => t.name === AREAS)
		const report = await api.getJson(request, `/report/structured?template=${areas.id}`)
		const total = report.rows[report.rows.length - 1]
		expect(total.label).toBe('Saldo')
		// Spalte 4 ist „Ist" der gewählten Periode
		expect(total.values[3]).toBe(12000)

		// Die bis 0.38.0 mitgelieferten Kennungen gibt es nicht mehr
		expect((await api.raw(request, 'GET', '/report/structured?template=builtin:bereiche')).status()).toBe(404)
		expect((await api.raw(request, 'PUT', '/report-templates/builtin:bereiche', { data: { name: 'X', definition: {} } })).status()).toBe(404)
	})

	test('ungültige Vorlagen werden abgelehnt, Revisoren ändern nichts (API)', async ({ request }) => {
		const invalid = await api.raw(request, 'POST', '/report-templates', { data: { name: 'Leer', definition: { sections: [] } } })
		expect(invalid.status()).toBe(422)
		expect((await invalid.json()).errors).toContain('Die Vorlage braucht mindestens eine Spalte.')

		const revisor = await api.raw(request, 'POST', '/report-templates', {
			user: USERS.revisor,
			data: { name: 'Revisor', definition: { columns: [{ source: 'actual' }], sections: [{ levels: [{ by: 'type' }] }] } },
		})
		expect(revisor.status()).toBe(403)
		expect((await api.raw(request, 'POST', '/report-templates/standard', { user: USERS.revisor })).status()).toBe(403)
	})

	test('Kopie anlegen und Abschnitte umstellen', async ({ page }) => {
		await openStructure(page, USERS.verwalter)
		const table = visibleSection(page).locator('.vbh-structured-table')
		await expect(table).toBeVisible()
		await expect(visibleSection(page).locator('.vbh-structured-picker')).toContainText(GRANTOR)

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
		await expect(visibleSection(page).getByRole('link', { name: 'Drucken / PDF' })).toHaveAttribute('href', /export\/gliederungsbericht\?template=\d+/)
	})

	test('neue Vorlage bei null: nur ein Name, schon steht der Saldo da', async ({ page }) => {
		await openStructure(page, USERS.verwalter)
		await visibleSection(page).getByRole('button', { name: 'Neue Vorlage' }).click()
		const dialog = page.locator('.vbh-rtpl')
		await expect(dialog.getByText('Die Vorlage braucht einen Namen.')).toBeVisible()
		await dialog.locator('input').first().fill('Alle Konten')
		await dialog.getByRole('button', { name: 'Anlegen' }).click()
		await expect(dialog).toHaveCount(0)

		await expect(visibleSection(page).locator('.vbh-structured-picker')).toContainText('Alle Konten')
		const table = visibleSection(page).locator('.vbh-structured-table')
		await expect(table.locator('.vbh-structured-total')).toContainText('Saldo')
		await expect(table.getByText('Mitgliedsbeiträge')).toBeVisible()
	})

	test('alle Vorlagen löschen und die Standardvorlagen zurückholen', async ({ page, request }) => {
		const { templates } = await api.getJson(request, '/report-templates')
		for (const t of templates) {
			await api.raw(request, 'DELETE', `/report-templates/${t.id}`)
		}
		// Gelöscht bleibt gelöscht – der nächste Abruf legt nichts neu an
		expect((await api.getJson(request, '/report-templates')).templates).toEqual([])

		await openStructure(page, USERS.verwalter)
		await expect(visibleSection(page).getByText('Noch keine Vorlage')).toBeVisible()
		await expect(visibleSection(page).getByRole('link', { name: 'Drucken / PDF' })).toHaveCount(0)

		await visibleSection(page).locator('.vbh-structured-empty-actions').getByRole('button', { name: 'Standardvorlagen hinzufügen' }).click()
		await expect(visibleSection(page).locator('.vbh-structured-table')).toBeVisible()
		await expect(visibleSection(page).locator('.vbh-structured-picker')).toContainText(GRANTOR)
		expect((await api.getJson(request, '/report-templates')).templates.map((t) => t.name)).toEqual([GRANTOR, AREAS])
	})

	test('Revisor sieht den Bericht, aber keine Bearbeiten-Knöpfe', async ({ page }) => {
		await openStructure(page, USERS.revisor)
		await expect(visibleSection(page).locator('.vbh-structured-table')).toBeVisible()
		await expect(visibleSection(page).getByRole('button', { name: 'Kopieren und anpassen' })).toHaveCount(0)
		await expect(visibleSection(page).getByRole('button', { name: 'Neue Vorlage' })).toHaveCount(0)
		await expect(visibleSection(page).getByRole('button', { name: 'Weitere Aktionen' })).toHaveCount(0)
	})
})
