import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Erster Start: der Setup-Assistent legt den Standard-Kontenrahmen an,
// und der Konten-Tab zeigt ihn.

test.describe('Setup-Assistent', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
	})

	test('"Ich fange neu an" legt den Standard-Kontenrahmen an', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await expect(page.getByText('Willkommen bei der Vereinsbuchhaltung!')).toBeVisible()
		await page.getByRole('button', { name: 'Ich fange neu an' }).click()

		// Der Assistent schließt sich, der Kontenrahmen ist da.
		await expect(page.getByText('Willkommen bei der Vereinsbuchhaltung!')).toBeHidden()
		await switchTab(page, 'Konten')
		await expect(visibleSection(page).getByText('Bankkonto').first()).toBeVisible()
		await expect(visibleSection(page).getByText('Mitgliedsbeiträge').first()).toBeVisible()

		const accounts = await api.listAccounts(request)
		expect(accounts.length).toBeGreaterThan(10)
		expect(accounts.some((a) => a.isBank)).toBe(true)
	})
})

// Die "Erste Schritte"-Karte auf der Übersicht wertet Konten, Buchungen und
// Berechtigungen aus, die erst nach dem Öffnen der App nachgeladen werden.
// Sie darf sich dabei nicht auf Zwischenstände verlassen (leer wirkt sonst
// "nichts erledigt") und muss verschwinden, sobald wirklich alles erledigt
// ist - siehe App.vue setupReady.
test.describe('Erste-Schritte-Karte', () => {
	test('zeigt offene Punkte, solange nicht alles erledigt ist', async ({ page, request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// Ausdrücklich unbenannt lassen, unabhängig davon, was frühere Specs
		// im App-Config hinterlassen haben (resetBook() räumt das nicht ab).
		await api.updateSettings(request, { club_name: '' })

		await openApp(page, USERS.verwalter)
		await expect(visibleSection(page).getByText('Erste Schritte')).toBeVisible()
		await expect(visibleSection(page).getByText('Verein benennen')).toBeVisible()
		await expect(visibleSection(page).getByText('Erste Buchung erfassen')).toBeVisible()
	})

	test('blendet sich aus, sobald alle Punkte erledigt sind', async ({ page, request }) => {
		await api.resetBook(request)
		const accounts = await api.seedDefaultAccounts(request)
		await api.updateSettings(request, { club_name: 'Testverein e. V.' })
		await api.setRole(request, USERS.buchhalter, 'buchhalter')

		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: '2031-06-15',
			description: 'Spende Vereinsfest',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 100,
		})

		// Entspricht Account::isResultRelevant() (alles außer Geldkonten/Eigenkapital) -
		// dieselbe Auswahl, die SetupChecklist.vue für den Sphären-Punkt prüft.
		const relevantIds = accounts.filter((a) => a.type !== 'equity' && !a.isBank).map((a) => a.id)
		await api.bulkSphere(request, relevantIds, 'ideell')

		await openApp(page, USERS.verwalter)
		await expect(page.locator('.vbh-setupcard')).toBeHidden()
	})
})
