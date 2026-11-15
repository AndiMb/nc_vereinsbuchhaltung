import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BASE_URL, openApp, switchTab, USERS, visibleSection, waitForAppLoaded } from './fixtures/nextcloud.mjs'

// Datumsfilter der Buchungsliste (Issue #328): Spanne von/bis über das
// Buchungsdatum, beide Tage eingeschlossen, in der URL als from=/to=. Der
// Sprung aus dem visuellen Gliederungsbericht setzt „bis“ auf das Ende des
// Stand-Monats, damit die Liste sich zum angeklickten Ist summiert.
//
// Bestand: Ausgabenkonto 5000 mit drei Buchungen in den ersten drei Monaten
// der laufenden Periode (100 €, 200 €, 400 €).

const APP_URL = `${BASE_URL}/index.php/apps/vereinsbuchhaltung`
const EXPENSE_ACCOUNT = '5000'
const ROWS = ['Miete Monat 0', 'Miete Monat 1', 'Miete Monat 2']

/** `YYYY-MM` um n Monate verschoben. */
function addMonths(ym, n) {
	const [year, month] = ym.split('-').map(Number)
	const index = year * 12 + month - 1 + n
	return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`
}

/** Letzter Tag des Monats `YYYY-MM` als `YYYY-MM-TT`. */
function lastDay(ym) {
	const [year, month] = ym.split('-').map(Number)
	return `${ym}-${String(new Date(Date.UTC(year, month, 0)).getUTCDate()).padStart(2, '0')}`
}

/** Welche der drei Buchungen sind zu sehen? Ausgefilterte Zeilen bleiben im DOM (nur hidden). */
async function expectVisibleRows(page, expected) {
	const section = visibleSection(page)
	for (const row of ROWS) {
		await expect(section.getByText(row).filter({ visible: true })).toHaveCount(expected.includes(row) ? 1 : 0)
	}
}

test.describe('Buchungsliste: Datumsfilter', () => {
	let expense
	let period
	let months

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, exp] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)
		expense = exp
		const today = new Date().toISOString().slice(0, 10)
		const id = await api.periodIdForDate(request, today)
		period = (await api.listPeriods(request)).find((p) => p.id === id)
		const startYm = period.startDate.slice(0, 7)
		months = [0, 1, 2].map((n) => addMonths(startYm, n))
		for (const [i, amount] of [100, 200, 400].entries()) {
			await api.createBooking(request, { date: `${months[i]}-15`, description: ROWS[i], debitAccountId: expense.id, creditAccountId: bank.id, amount })
		}
	})

	test('Von und Bis grenzen die Liste ein (beide Tage eingeschlossen), stehen in der URL und lassen sich aufheben', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		const section = visibleSection(page)
		const dates = section.getByRole('group', { name: 'Buchungsdatum' })
		await expectVisibleRows(page, ROWS)
		await expect(dates.getByRole('button', { name: 'Datumsfilter aufheben' })).toHaveCount(0)

		// Der Buchungstag selbst zählt mit - am Anfang wie am Ende der Spanne.
		await dates.getByLabel('Von').fill(`${months[1]}-15`)
		await expectVisibleRows(page, [ROWS[1], ROWS[2]])
		await expect(page).toHaveURL(new RegExp(`[?&]from=${months[1]}-15(&|$)`))

		await dates.getByLabel('Bis').fill(`${months[1]}-15`)
		await expectVisibleRows(page, [ROWS[1]])
		await expect(page).toHaveURL(new RegExp(`[?&]to=${months[1]}-15(&|$)`))

		// Die gefilterte Liste übersteht das Neuladen.
		await page.reload()
		await waitForAppLoaded(page)
		await expect(visibleSection(page).getByRole('group', { name: 'Buchungsdatum' }).getByLabel('Von')).toHaveValue(`${months[1]}-15`)
		await expectVisibleRows(page, [ROWS[1]])

		await visibleSection(page).getByRole('group', { name: 'Buchungsdatum' }).getByRole('button', { name: 'Datumsfilter aufheben' }).click()
		await expectVisibleRows(page, ROWS)
		await expect(page).not.toHaveURL(/[?&](from|to)=/)
	})

	test('Link mit to= filtert; ein ungültiges Datum im Link wird ignoriert und entfernt', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.goto(`${APP_URL}/bookings?period=${period.id}&account=${expense.id}&to=${lastDay(months[0])}`)
		await waitForAppLoaded(page)
		await expectVisibleRows(page, [ROWS[0]])
		await expect(visibleSection(page).getByRole('group', { name: 'Buchungsdatum' }).getByLabel('Bis')).toHaveValue(lastDay(months[0]))

		await page.goto(`${APP_URL}/bookings?period=${period.id}&from=2026-02-31&to=gestern`)
		await waitForAppLoaded(page)
		await expect(page).not.toHaveURL(/[?&](from|to)=/, { timeout: 15000 })
		await expectVisibleRows(page, ROWS)
	})

	test('Sprung aus dem visuellen Gliederungsbericht: mit einem Monat als Stand endet die Liste an dessen letztem Tag', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		const intro = page.getByRole('button', { name: 'Verstanden' })
		if (await intro.isVisible().catch(() => false)) { await intro.click() }
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Gliederung', exact: true }).click()
		await visibleSection(page).getByRole('button', { name: 'Visuell', exact: true }).click()
		await expect(visibleSection(page).locator('.vbh-visual-tiles')).toBeVisible()

		// Stand: der erste Monat der Periode.
		const label = new Intl.DateTimeFormat('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${months[0]}-01T00:00:00Z`))
		const asOf = visibleSection(page).locator('.vbh-visual-asof')
		if ((await asOf.locator('.vs__selected').textContent()).trim() !== label) {
			await asOf.locator('.vs__dropdown-toggle').click()
			await page.locator('li.vs__dropdown-option', { hasText: label }).first().click()
		}
		await expect(asOf.locator('.vs__selected')).toHaveText(label)

		// Das Ist der Zeile ist die Buchung des ersten Monats …
		const breakdown = visibleSection(page).locator('.vbh-visual-breakdown')
		const linkName = `Buchungen von ${EXPENSE_ACCOUNT} Raum- / Mietkosten zeigen`
		const row = breakdown.locator('.vbh-visual-row', { has: page.getByRole('button', { name: linkName }) }).first()
		await expect(row.locator('.vbh-visual-row__actual')).toContainText('100,00')
		await row.getByRole('button', { name: linkName }).click()

		// … und genau die zeigt die Liste.
		await expect(page).toHaveURL(new RegExp(`/bookings\\?.*account=${expense.id}(&|$)`))
		await expect(page).toHaveURL(new RegExp(`[?&]to=${lastDay(months[0])}(&|$)`))
		await expect(page).not.toHaveURL(/[?&]from=/)
		await expectVisibleRows(page, [ROWS[0]])
		await expect(visibleSection(page).getByRole('group', { name: 'Buchungsdatum' }).getByLabel('Bis')).toHaveValue(lastDay(months[0]))

		// Mit „Aktuell“ als Stand bleibt die Liste ohne Datumsfilter.
		await page.goBack()
		await expect(visibleSection(page).locator('.vbh-visual-tiles')).toBeVisible()
		const asOfAgain = visibleSection(page).locator('.vbh-visual-asof')
		await asOfAgain.locator('.vs__dropdown-toggle').click()
		await page.locator('li.vs__dropdown-option', { hasText: 'Aktuell (alle Buchungen)' }).first().click()
		await expect(asOfAgain.locator('.vs__selected')).toHaveText('Aktuell (alle Buchungen)')
		await visibleSection(page).locator('.vbh-visual-breakdown').getByRole('button', { name: `Buchungen von ${EXPENSE_ACCOUNT} Raum- / Mietkosten zeigen` }).first().click()
		await expect(page).toHaveURL(new RegExp(`/bookings\\?.*account=${expense.id}(&|$)`))
		await expect(page).not.toHaveURL(/[?&](from|to)=/)
		await expectVisibleRows(page, ROWS)
	})

	test.describe('Handybreite', () => {
		test.use({ viewport: { width: 390, height: 844 }, hasTouch: true })

		test('der Datumsfilter steckt hinter dem Filter-Knopf und passt in die Breite', async ({ page }) => {
			await openApp(page, USERS.verwalter)
			const intro = page.getByRole('button', { name: 'Verstanden' })
			if (await intro.isVisible().catch(() => false)) { await intro.click() }
			await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Buchungen' }).click()
			const section = visibleSection(page)
			await expect(section.getByRole('group', { name: 'Buchungsdatum' })).toHaveCount(0)

			await section.getByRole('button', { name: 'Filter', exact: true }).click()
			const dates = section.getByRole('group', { name: 'Buchungsdatum' })
			await dates.getByLabel('Bis').fill(lastDay(months[0]))
			await expectVisibleRows(page, [ROWS[0]])
			// Ein aktiver Filter färbt den Knopf.
			await expect(section.getByRole('button', { name: 'Filter (aktiv)', exact: true })).toBeVisible()

			const box = await dates.boundingBox()
			expect(box.x).toBeGreaterThanOrEqual(0)
			expect(box.x + box.width).toBeLessThanOrEqual(390)
			const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
			expect(pageOverflow, 'Seite scrollt waagerecht').toBeLessThanOrEqual(0)
		})
	})
})
