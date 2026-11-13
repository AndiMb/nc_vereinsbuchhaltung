import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BASE_URL, INCOME_ACCOUNT, openAccountTreeNode, openApp, switchTab, USERS, visibleSection, waitForAppLoaded } from './fixtures/nextcloud.mjs'

// Kontoabstimmung (Issue #159): ein Kontoauszug wird mit den Bewegungen des
// Geldkontos verglichen – ohne Import. Nicht der „Bankabgleich“ des
// SEPA-Moduls (Spec 44 ff.).
//
// Bestand: drei Einnahmen auf dem Bankkonto, die mittlere versehentlich
// doppelt gebucht (200 €). Der Auszug kennt jede nur einmal und trägt eine
// Saldospalte.

const APP_URL = `${BASE_URL}/index.php/apps/vereinsbuchhaltung`
const ROWS = [
	{ day: '10', text: 'Beitrag Abgleich Anton', amount: 100 },
	{ day: '11', text: 'Beitrag Abgleich Berta', amount: 200 },
	{ day: '12', text: 'Beitrag Abgleich Carla', amount: 400 },
]
const DUPLICATE = ROWS[1]

/** CSV-CAMT-Auszug mit Saldospalte; Anfangssaldo 0, wie das frisch angelegte Konto. */
function statementCsv(month) {
	const [year, mm] = month.split('-')
	const lines = ['Auftragskonto;Buchungstag;Valutadatum;Buchungstext;Verwendungszweck;Beguenstigter/Zahlungspflichtiger;Betrag;Waehrung;Saldo nach Buchung;Info']
	let balance = 0
	for (const row of ROWS) {
		balance += row.amount
		const date = `${row.day}.${mm}.${year}`
		lines.push([BANK_ACCOUNT_IBAN, date, date, 'GUTSCHRIFT', row.text, '', `${row.amount},00`, 'EUR', `${balance},00`, 'Umsatz gebucht'].join(';'))
	}
	return lines.join('\n') + '\n'
}

test.describe('Kontoabstimmung', () => {
	let bank
	let month

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bankAccount, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		bank = bankAccount
		await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
		const today = new Date().toISOString().slice(0, 10)
		const id = await api.periodIdForDate(request, today)
		const period = (await api.listPeriods(request)).find((p) => p.id === id)
		month = period.startDate.slice(0, 7)
		for (const row of [...ROWS, DUPLICATE]) {
			await api.createBooking(request, { date: `${month}-${row.day}`, description: row.text, debitAccountId: bank.id, creditAccountId: income.id, amount: row.amount })
		}
	})

	test('findet die doppelte Buchung, erklärt die Saldendifferenz und importiert nichts', async ({ page, request }) => {
		const before = JSON.stringify(await api.listTransactions(request))

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await openAccountTreeNode(page, BANK_ACCOUNT)
		await visibleSection(page).getByRole('button', { name: 'Kontoabstimmung' }).click()
		await expect(page).toHaveURL(new RegExp(`/accounts/${bank.id}/reconcile`))

		const view = page.locator('.vbh-reconcile')
		await view.locator('input[type="file"]').setInputFiles({ name: 'auszug.csv', mimeType: 'text/csv', buffer: Buffer.from(statementCsv(month), 'utf-8') })

		await expect(view.locator('.vbh-reconcile-summary')).toContainText('3 Zeilen im Auszug, 3 abgeglichen')

		// Zu viel in der App: genau die zweite Buchung des doppelten Beitrags.
		const surplus = view.locator('[data-test="reconcile-surplus"] tbody tr')
		await expect(surplus).toHaveCount(1)
		await expect(surplus).toContainText(DUPLICATE.text)
		await expect(surplus).toContainText('200,00')
		await expect(view.getByText('Im Auszug fehlt nichts.')).toBeVisible()
		await expect(view.locator('[data-test="reconcile-shifted"]')).toHaveCount(0)

		// Die Saldenprüfung führt die Differenz am Ende auf diese Buchung zurück.
		const breakdown = view.locator('.vbh-reconcile-breakdown')
		await expect(breakdown.locator('tr', { hasText: 'Zu viel in der App' })).toContainText('200,00')
		await expect(breakdown.locator('tr', { hasText: 'Differenz am Ende' })).toContainText('200,00')
		await expect(breakdown.locator('tr', { hasText: 'Nicht erklärter Rest' })).toHaveCount(0)

		// Der Sprung führt in die Buchungsliste, gefiltert auf Konto und Tag: beide Buchungen des Tages.
		await surplus.getByRole('link', { name: 'Zur Buchung' }).click()
		await expect(page).toHaveURL(new RegExp(`[?&]from=${month}-${DUPLICATE.day}(&|$)`))
		await expect(visibleSection(page).getByText(DUPLICATE.text).filter({ visible: true })).toHaveCount(2)

		// Nur verglichen: kein Bankumsatz ist entstanden.
		expect(JSON.stringify(await api.listTransactions(request))).toBe(before)
	})

	test('eine Datei, die kein Kontoauszug ist, wird mit einer Meldung abgelehnt', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.goto(`${APP_URL}/accounts/${bank.id}/reconcile`)
		await waitForAppLoaded(page)
		const view = page.locator('.vbh-reconcile')
		await view.locator('input[type="file"]').setInputFiles({ name: 'notiz.txt', mimeType: 'text/plain', buffer: Buffer.from('kein Auszug') })
		await expect(view.getByText('Das Dateiformat wurde nicht erkannt.', { exact: false })).toBeVisible()
		await expect(view.locator('.vbh-reconcile-summary')).toHaveCount(0)
	})

	test('ein Revisor kann abstimmen', async ({ page }) => {
		await openApp(page, USERS.revisor)
		await page.goto(`${APP_URL}/accounts/${bank.id}/reconcile`)
		await waitForAppLoaded(page)
		const view = page.locator('.vbh-reconcile')
		await view.locator('input[type="file"]').setInputFiles({ name: 'auszug.csv', mimeType: 'text/csv', buffer: Buffer.from(statementCsv(month), 'utf-8') })
		await expect(view.locator('[data-test="reconcile-surplus"] tbody tr')).toHaveCount(1)

		await view.getByRole('button', { name: 'Zurück zum Konto' }).click()
		await expect(page).toHaveURL(new RegExp(`/accounts/${bank.id}(\\?|$)`))
		await expect(page.locator('.vbh-reconcile')).toHaveCount(0)
	})
})
