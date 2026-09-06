import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, selectYear, visibleSection, pickNcSelectOption, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Der Kern der App: Buchungen anlegen (Einfach-Modus über den Dialog),
// im Journal wiederfinden, bearbeiten und löschen.

test.describe('Buchungen', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('Einnahme im Einfach-Modus über den Dialog buchen', async ({ page }) => {
		await openApp(page, USERS.verwalter)

		await page.getByRole('button', { name: 'Buchung', exact: true }).click()
		const dialog = page.getByRole('dialog')
		await dialog.getByRole('button', { name: 'Einnahme' }).click()

		// Die Kurztour für Erstnutzer weg, wenn sie auftaucht.
		const tourSkip = dialog.getByRole('button', { name: 'Überspringen', exact: true })
		if (await tourSkip.isVisible().catch(() => false)) {
			await tourSkip.click()
		}

		// Ueber das Label statt ueber den Feldtyp: Betragsfelder sind seit
		// Issue #34 Textfelder, die ihren Wert selbst formatieren.
		await dialog.getByLabel('Betrag (€)', { exact: true }).fill('120')
		await pickNcSelectOption(dialog, '– Kategorie wählen –', 'Mitgliedsbeiträge')
		// Das Geldkonto ist mit dem ersten Bank-/Kassenkonto vorbelegt – passt.
		await dialog.locator('input[type="date"]').fill('2031-03-15')
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill('Beitrag Erika Beispiel')

		await dialog.getByRole('button', { name: 'Buchen', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		await switchTab(page, 'Buchungen')
		await selectYear(page, 2031)
		// Großzügiges Timeout: direkt nach dem Buchen laufen Journal-,
		// Salden- und Umsatz-Reload parallel – das erste Rendering des
		// gewechselten Jahres kann die Standard-5s gelegentlich reißen.
		await expect(visibleSection(page).getByText('Beitrag Erika Beispiel').first()).toBeVisible({ timeout: 15000 })
	})

	test('per API angelegte Buchung erscheint im Journal (Experten-Felder)', async ({ page, request }) => {
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: '2031-04-01',
			description: 'Spende Vereinsfest',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 250,
		})

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Buchungen')
		await selectYear(page, 2031)
		await expect(visibleSection(page).getByText('Spende Vereinsfest').first()).toBeVisible()
	})

	test('Buchung bearbeiten: geänderter Text landet im Journal', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await selectYear(page, 2031)

		const row = visibleSection(page).locator('tr', { hasText: 'Spende Vereinsfest' }).first()
		await row.getByRole('button', { name: 'Bearbeiten' }).click()
		const dialog = page.getByRole('dialog')
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill('Spende Sommerfest')
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		await expect(visibleSection(page).getByText('Spende Sommerfest').first()).toBeVisible()
		await expect(visibleSection(page).getByText('Spende Vereinsfest')).toHaveCount(0)
	})

	test('Buchung löschen entfernt sie aus dem Journal', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await selectYear(page, 2031)

		// Löschen liegt im Drei-Punkte-Menü der Journalzeile.
		const row = visibleSection(page).locator('tr', { hasText: 'Spende Sommerfest' }).first()
		await row.locator('.action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: 'Löschen' }).click()

		// Die Rückfrage bestätigen (eigener Bestätigungs-Dialog).
		await page.getByRole('dialog').getByRole('button', { name: 'Löschen', exact: true }).click()

		await expect(visibleSection(page).getByText('Spende Sommerfest')).toHaveCount(0)
		const journal = await api.listJournal(request, { year: 2031 })
		expect(journal.some((j) => j.description === 'Spende Sommerfest')).toBe(false)
	})
})
