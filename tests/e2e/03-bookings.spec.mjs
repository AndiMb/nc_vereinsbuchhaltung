import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, selectPeriod, visibleSection, pickNcSelectOption, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

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
		await selectPeriod(page, '2031')
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
		await selectPeriod(page, '2031')
		await expect(visibleSection(page).getByText('Spende Vereinsfest').first()).toBeVisible()
		// Bank im Soll, Erlöskonto im Haben: eine Einnahme, grün und mit Plus.
		const row = visibleSection(page).locator('tr', { hasText: 'Spende Vereinsfest' }).first()
		await expect(row.locator('td.num.pos')).toHaveText(/^\+250,00\s*€$/)
	})

	test('Buchung bearbeiten: geänderter Text landet im Journal', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await selectPeriod(page, '2031')

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
		await selectPeriod(page, '2031')

		// Löschen liegt im Drei-Punkte-Menü der Journalzeile.
		const row = visibleSection(page).locator('tr', { hasText: 'Spende Sommerfest' }).first()
		await row.locator('.action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: 'Löschen' }).click()

		// Die Rückfrage bestätigen (eigener Bestätigungs-Dialog).
		await page.getByRole('dialog').getByRole('button', { name: 'Löschen', exact: true }).click()

		await expect(visibleSection(page).getByText('Spende Sommerfest')).toHaveCount(0)
		const journal = await api.listJournal(request, { period: await api.periodIdForDate(request, '2031-06-01') })
		expect(journal.some((j) => j.description === 'Spende Sommerfest')).toBe(false)
	})

	test('Kontofilter: Kategorie „Einnahmen“ trifft alle Einnahmekonten', async ({ page, request }) => {
		// Zwei Einnahmen auf verschiedenen Konten derselben Kategorie, eine Ausgabe
		// als Gegenprobe – alle über die API, damit der Test nicht von seinen
		// Vorgängern abhängt.
		const [bank, beitraege, spenden, miete] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT, '4100', '5000')
		await api.createBooking(request, { date: '2031-05-02', description: 'Filtertest Beitrag', debitAccountId: bank.id, creditAccountId: beitraege.id, amount: 30 })
		await api.createBooking(request, { date: '2031-05-03', description: 'Filtertest Spende', debitAccountId: bank.id, creditAccountId: spenden.id, amount: 40 })
		await api.createBooking(request, { date: '2031-05-04', description: 'Filtertest Miete', debitAccountId: miete.id, creditAccountId: bank.id, amount: 50 })

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Buchungen')
		await selectPeriod(page, '2031')
		const section = visibleSection(page)
		await expect(section.getByText('Filtertest Miete').first()).toBeVisible({ timeout: 15000 })

		// Die Kategorie-Überschrift ist im Filter eine echte Option.
		await pickNcSelectOption(section, 'Konto filtern', 'Einnahmen')
		await expect(section.getByText('Filtertest Beitrag').first()).toBeVisible()
		await expect(section.getByText('Filtertest Spende').first()).toBeVisible()
		await expect(section.getByText('Filtertest Miete')).toHaveCount(0)

		// Filter leeren: alles wieder da.
		await section.locator('.vbh-filter-select .vs__clear').click()
		await expect(section.getByText('Filtertest Miete').first()).toBeVisible()

		await pickNcSelectOption(section, 'Konto filtern', 'Ausgaben')
		await expect(section.getByText('Filtertest Miete').first()).toBeVisible()
		await expect(section.getByText('Filtertest Beitrag')).toHaveCount(0)
		await expect(section.getByText('Filtertest Spende')).toHaveCount(0)
	})
})
