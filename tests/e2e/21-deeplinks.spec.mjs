import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BASE_URL, INCOME_ACCOUNT, openAccountTreeNode, openApp, selectPeriod, switchTab, USERS, visibleSection, waitForAppLoaded } from './fixtures/nextcloud.mjs'

// Deep-Linking (vue-router, History-Mode): jede Navigation in der App
// verändert die URL, und jede so erzeugte URL lädt – auch als frisch
// geöffneter Link, ohne vorherigen Klick in der Oberfläche – direkt den
// richtigen Screen. Das Laden "von außen" ist der eigentliche Beweis: es
// prüft die neue Wildcard-Route in appinfo/routes.php, nicht nur den
// client-seitigen Router.

const JAHR = 2032
const APP_URL = `${BASE_URL}/index.php/apps/vereinsbuchhaltung`

test.describe('Deep-Linking', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: `${JAHR}-06-01`,
			description: 'Spende Deep-Link-Test',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 42,
		})
	})

	test('Tab- und Unterreiter-Wechsel per Klick ändern die URL', async ({ page }) => {
		await openApp(page, USERS.verwalter)

		await switchTab(page, 'Buchungen')
		await expect(page).toHaveURL(/\/bookings$/)

		await visibleSection(page).getByRole('button', { name: 'Zuzuordnen', exact: true }).click()
		await expect(page).toHaveURL(/\/bookings\/unassigned$/)

		await switchTab(page, 'Konten')
		await expect(page).toHaveURL(/\/accounts$/)
	})

	test('Direkt geladene Deep-Link-URL zeigt sofort den richtigen Screen (kein Klick)', async ({ page }) => {
		// openApp() meldet nur an - der eigentliche Vollzugriff ist der goto()
		// danach: eine neue Server-Antwort auf einen client-seitigen Pfad.
		await openApp(page, USERS.verwalter)
		await page.goto(`${APP_URL}/bookings/unassigned`)
		await waitForAppLoaded(page)

		await expect(page).toHaveURL(/\/bookings\/unassigned$/)
		await expect(visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Zuzuordnen', exact: true })).toHaveClass(/active/)
	})

	test('Buchung bearbeiten: URL trägt die ID, Reload öffnet den Dialog erneut', async ({ page, request }) => {
		const journal = await api.listJournal(request, { period: await api.periodIdForDate(request, `${JAHR}-06-01`) })
		const buchung = journal.find((e) => e.journal.description === 'Spende Deep-Link-Test').journal

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await selectPeriod(page, String(JAHR))

		const row = visibleSection(page).locator('tr', { hasText: 'Spende Deep-Link-Test' }).first()
		await row.getByRole('button', { name: 'Bearbeiten' }).click()
		await expect(page.getByRole('dialog')).toBeVisible()
		await expect(page).toHaveURL(new RegExp(`[?&]booking=${buchung.id}(&|$)`))

		// Dieselbe URL frisch geladen: der Dialog muss von selbst wieder aufgehen.
		await page.reload()
		await waitForAppLoaded(page)
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible({ timeout: 15000 })
		await expect(dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann')).toHaveValue('Spende Deep-Link-Test')
	})

	test('Konto auswählen ändert die URL, Reload öffnet direkt dessen Kontoauszug', async ({ page, request }) => {
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await openAccountTreeNode(page, BANK_ACCOUNT)
		await expect(page).toHaveURL(new RegExp(`/accounts/${bank.id}$`))

		await page.reload()
		await waitForAppLoaded(page)
		await expect(visibleSection(page).locator('.vbh-treenode.selected', { hasText: BANK_ACCOUNT })).toBeVisible({ timeout: 15000 })
		await expect(visibleSection(page).locator('.vbh-detail h3', { hasText: BANK_ACCOUNT })).toBeVisible()
	})

	test('Browser-Zurück schließt den Buchungsdialog wieder', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')

		await page.getByRole('button', { name: 'Buchung', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeVisible()
		await expect(page).toHaveURL(/[?&]booking=new(&|$)/)

		await page.goBack()
		await expect(page.getByRole('dialog')).toBeHidden()
		await expect(page).toHaveURL(/\/bookings$/)
	})
})
