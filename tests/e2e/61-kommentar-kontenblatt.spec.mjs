import { expect, test } from '@playwright/test'
import { api, authHeaders, BANK_ACCOUNT, BASE_URL, INCOME_ACCOUNT, openAccountTreeNode, openApp, selectPeriod, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Kontoauszug (Tab Konten): Spaltensortierung, Kontenblatt-Export (CSV und
// Druckansicht), Tooltip langer Kontennamen – und der Kommentar an
// Buchungen, der auch im abgeschlossenen Geschäftsjahr änderbar bleibt.

/** Zeile des Kontoauszugs zu einer Buchungsbeschreibung. */
function auszugsZeile(page, text) {
	return visibleSection(page).locator('.vbh-tablecard tbody tr', { hasText: text }).first()
}

/** Beschreibungen der Auszugszeilen in angezeigter Reihenfolge (ohne Vortrag). */
async function beschreibungen(page) {
	return visibleSection(page).locator('.vbh-tablecard tbody tr:not(.vbh-carryrow):not(.vbh-ccdetail) td.vbh-purpose').allInnerTexts()
}

/** Spaltenüberschrift des Kontoauszugs anklicken. */
async function sortiereNach(page, spalte) {
	await visibleSection(page).locator('.vbh-tablecard thead th', { hasText: spalte }).click()
}

test.describe('Kontoauszug: Sortierung, Kontenblatt-Export, Tooltip', () => {
	const JAHR = 2033

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		const buche = (date, description, amount) => api.createBooking(request, { date, description, debitAccountId: bank.id, creditAccountId: income.id, amount })
		// Vorjahr: ergibt den Saldovortrag von 100,00 € auf dem Geldkonto.
		await buche(`${JAHR - 1}-12-01`, 'Beitrag Vorjahr', 100)
		await buche(`${JAHR}-01-10`, 'Beitrag Anna', 30)
		await buche(`${JAHR}-03-05`, 'Beitrag Bert', 50)
		await buche(`${JAHR}-04-01`, 'Beitrag Carl', 10)
	})

	test('Spalten sortieren, Saldo und Vortrag bleiben stehen', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)

		const vortrag = visibleSection(page).locator('.vbh-tablecard tbody tr').first()
		await expect(vortrag).toHaveClass(/vbh-carryrow/, { timeout: 15000 })
		await expect.poll(() => beschreibungen(page)).toEqual(['Beitrag Anna', 'Beitrag Bert', 'Beitrag Carl'])

		await sortiereNach(page, 'Soll')
		await expect(visibleSection(page).locator('.vbh-tablecard thead th', { hasText: 'Soll' })).toContainText('▲')
		await expect.poll(() => beschreibungen(page)).toEqual(['Beitrag Carl', 'Beitrag Anna', 'Beitrag Bert'])
		await sortiereNach(page, 'Soll')
		await expect.poll(() => beschreibungen(page)).toEqual(['Beitrag Bert', 'Beitrag Anna', 'Beitrag Carl'])

		// Vortrag bleibt oben, und jede Zeile behält ihren chronologisch
		// berechneten Saldo (100 + 30 + 50 = 180 bei Bert).
		await expect(vortrag).toHaveClass(/vbh-carryrow/)
		await expect(auszugsZeile(page, 'Beitrag Bert').locator('td').nth(6)).toHaveText(/^180,00\s*€$/)

		// Ein anderes Konto beginnt wieder chronologisch.
		await openAccountTreeNode(page, INCOME_ACCOUNT)
		await expect(visibleSection(page).locator('.vbh-tablecard thead th', { hasText: /[▲▼]/ })).toHaveCount(0)
	})

	test('Kontenblatt als CSV und Druckansicht', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)

		const exportLeiste = visibleSection(page).locator('.vbh-statementexport')
		const csvHref = await exportLeiste.getByRole('link', { name: 'CSV' }).getAttribute('href')
		const druckHref = await exportLeiste.getByRole('link', { name: 'Drucken' }).getAttribute('href')

		const csv = await request.get(`${BASE_URL}${csvHref}`, { headers: authHeaders(USERS.verwalter) })
		expect(csv.ok()).toBe(true)
		expect(csv.headers()['content-disposition']).toContain(`kontenblatt_${BANK_ACCOUNT}_${JAHR}.csv`)
		const zeilen = (await csv.text()).trim().split('\n')
		expect(zeilen[1]).toContain('"Saldovortrag"')
		expect(zeilen[1]).toContain('"100,00"')
		// Export immer chronologisch, unabhängig von der Sortierung am Bildschirm.
		expect(zeilen.slice(2, 5).map((z) => z.split(';')[2])).toEqual(['"Beitrag Anna"', '"Beitrag Bert"', '"Beitrag Carl"'])
		expect(zeilen.at(-1)).toContain('"Summe"')
		expect(zeilen.at(-1)).toContain('"190,00"')

		const druck = await request.get(`${BASE_URL}${druckHref}`, { headers: authHeaders(USERS.verwalter) })
		expect(druck.ok()).toBe(true)
		const html = await druck.text()
		expect(html).toContain(`Kontenblatt ${BANK_ACCOUNT}`)
		expect(html).toContain('Saldovortrag')
		expect(html).toContain('Beitrag Carl')
	})

	test('lange Kontennamen zeigen den vollen Namen als Tooltip', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		const name = visibleSection(page).locator('.vbh-treenode', { hasText: BANK_ACCOUNT }).first().locator('.vbh-treename')
		await expect(name).toHaveAttribute('title', new RegExp(`^${BANK_ACCOUNT} \\S`))
	})
})

test.describe('Kommentar an Buchungen', () => {
	const JAHR = 2034
	const TEXT = 'Beleg nachgereicht'

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, { date: `${JAHR}-05-02`, description: 'Beitrag Dora', debitAccountId: bank.id, creditAccountId: income.id, amount: 40 })
	})

	async function buchung(request) {
		const journal = await api.listJournal(request, { period: await api.periodIdForDate(request, `${JAHR}-05-02`) })
		return journal.find((e) => e.journal.description === 'Beitrag Dora').journal
	}

	test('Kommentar anlegen: Symbol mit Tooltip in Kontoauszug und Journal', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)

		const zeile = auszugsZeile(page, 'Beitrag Dora')
		await expect(zeile).toBeVisible({ timeout: 15000 })
		await expect(zeile.locator('.vbh-commentmark')).toHaveCount(0)
		await zeile.getByRole('button', { name: /bearbeiten/i }).click()

		const dialog = page.getByRole('dialog')
		await dialog.getByRole('textbox', { name: 'Kommentar' }).fill(TEXT)
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		await expect(zeile.locator('.vbh-commentmark')).toHaveAttribute('aria-label', `Kommentar: ${TEXT}`, { timeout: 15000 })

		await switchTab(page, 'Buchungen')
		await expect(visibleSection(page).locator('tr', { hasText: 'Beitrag Dora' }).locator('.vbh-commentmark')).toHaveAttribute('aria-label', `Kommentar: ${TEXT}`)
	})

	test('im abgeschlossenen Geschäftsjahr bleibt nur der Kommentar änderbar', async ({ page, request }) => {
		const periodId = await api.periodIdForDate(request, `${JAHR}-05-02`)
		await api.closePeriod(request, periodId)
		try {
			await openApp(page, USERS.verwalter)
			await switchTab(page, 'Konten')
			// Abgeschlossene Zeiträume tragen in der Auswahl ein Schloss.
			await selectPeriod(page, `${JAHR} 🔒`)
			await openAccountTreeNode(page, BANK_ACCOUNT)
			await auszugsZeile(page, 'Beitrag Dora').getByRole('button', { name: /bearbeiten/i }).click()

			const dialog = page.getByRole('dialog')
			await expect(dialog).toContainText('nur noch der Kommentar')
			await expect(dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann')).toBeDisabled()
			const kommentar = dialog.getByRole('textbox', { name: 'Kommentar' })
			await expect(kommentar).toBeEnabled()
			await expect(kommentar).toHaveValue(TEXT)
			// Erst eine Änderung bringt den Knopf.
			const speichern = dialog.getByRole('button', { name: 'Kommentar speichern' })
			await expect(speichern).toHaveCount(0)

			await kommentar.fill('Kassenprüfung: geprüft')
			await speichern.click()
			await expect(page.getByRole('dialog')).toBeHidden()

			await expect.poll(async () => (await buchung(request)).comment).toBe('Kassenprüfung: geprüft')
			// Der Buchungssatz selbst bleibt gesperrt (423), nicht etwa wegen
			// ungültiger Eingaben abgelehnt.
			const b = await buchung(request)
			const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
			const resp = await request.fetch(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/api/journal/${b.id}`, {
				method: 'PUT',
				headers: authHeaders(USERS.verwalter),
				data: { date: b.date, description: 'geändert', debitAccountId: bank.id, creditAccountId: income.id, amount: 40, updatedAt: b.updatedAt },
			})
			expect(resp.status()).toBe(423)
		} finally {
			await api.reopenPeriod(request, periodId)
		}
	})

	test('Revisor sieht den Kommentar, darf ihn aber nicht ändern', async ({ page, request }) => {
		await openApp(page, USERS.revisor)
		const intro = page.getByRole('button', { name: 'Verstanden' })
		if (await intro.isVisible().catch(() => false)) {
			await intro.click()
		}
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)
		await expect(auszugsZeile(page, 'Beitrag Dora').locator('.vbh-commentmark')).toHaveAttribute('aria-label', 'Kommentar: Kassenprüfung: geprüft', { timeout: 15000 })

		const b = await buchung(request)
		const resp = await api.setBookingComment(request, b.id, 'vom Revisor', b.updatedAt, { user: USERS.revisor, expectOk: false })
		expect(resp.status()).toBe(403)
		expect((await buchung(request)).comment).toBe('Kassenprüfung: geprüft')
	})

	test.describe('mobil', () => {
		test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

		test('Karten zeigen den Kommentar als Text', async ({ page }) => {
			await openApp(page, USERS.verwalter)
			await selectPeriod(page, String(JAHR))
			const nav = page.locator('.vbh-bottomnav')

			await nav.getByRole('button', { name: /^Buchungen/ }).click()
			await expect(visibleSection(page).locator('.vbh-mcard', { hasText: 'Beitrag Dora' }).locator('.vbh-mcard-comment')).toHaveText('Kassenprüfung: geprüft')

			await nav.getByRole('button', { name: 'Konten' }).click()
			await openAccountTreeNode(page, BANK_ACCOUNT)
			await expect(visibleSection(page).locator('.vbh-cardlist .vbh-mcard', { hasText: 'Beitrag Dora' }).locator('.vbh-mcard-comment')).toHaveText('Kassenprüfung: geprüft')
		})
	})
})
