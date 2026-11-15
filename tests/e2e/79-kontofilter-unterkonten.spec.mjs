import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BASE_URL, openApp, USERS, visibleSection, waitForAppLoaded } from './fixtures/nextcloud.mjs'

// Kontofilter „inkl. Unterkonten“ in der Buchungsliste (Issue #326): das Konto
// samt allen Unterkonten in jeder Tiefe – so, wie der Gliederungsbericht die
// Zeile eines Oberkontos rechnet. Die Option steht als sub=1 in der URL, und
// Oberkonten der visuellen Ansicht führen mit ihr in die Buchungsliste.
//
// Bestand: 5000 Raum- / Mietkosten
//            5000 1 Halle            (Unterkonto)
//              5000 11 Hallenstrom   (Unterkonto des Unterkontos)
// je eine Buchung direkt auf dem Oberkonto (100 €), dem Unterkonto (20 €) und
// dem Enkel (3 €); dazu eine Buchung auf einem fremden Konto.

const APP_URL = `${BASE_URL}/index.php/apps/vereinsbuchhaltung`
const PARENT = '5000'
const PARENT_LABEL = `${PARENT} Raum- / Mietkosten`
const OWN = ['Buchung Oberkonto (E2E)', 'Buchung Unterkonto (E2E)', 'Buchung Enkelkonto (E2E)']
const FOREIGN = 'Buchung fremdes Konto (E2E)'

/** Welche Buchungen sind zu sehen? Ausgefilterte Zeilen bleiben im DOM (nur hidden). */
async function expectVisibleRows(page, expected) {
	const section = visibleSection(page)
	for (const row of [...OWN, FOREIGN]) {
		await expect(section.getByText(row).filter({ visible: true })).toHaveCount(expected.includes(row) ? 1 : 0)
	}
}

test.describe('Buchungsliste: Kontofilter inkl. Unterkonten', () => {
	let parent
	let child
	let grandchild
	let period
	let template

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, expense, office] = await api.accountsByNumber(request, BANK_ACCOUNT, PARENT, '5100')
		parent = expense
		child = await api.createAccount(request, { number: `${PARENT} 1`, name: 'Halle', type: 'expense', parentId: parent.id })
		grandchild = await api.createAccount(request, { number: `${PARENT} 11`, name: 'Hallenstrom', type: 'expense', parentId: child.id })
		const today = new Date().toISOString().slice(0, 10)
		const id = await api.periodIdForDate(request, today)
		period = (await api.listPeriods(request)).find((p) => p.id === id)
		// Plan unter dem Ist: das Oberkonto steht damit auch in den Überschreitungen.
		await api.raw(request, 'POST', '/budget', { data: { accountId: parent.id, period: period.id, amount: 50 } })
		const created = await api.raw(request, 'POST', '/report-templates', {
			data: {
				name: 'Ausgaben nach Konten (E2E)',
				definition: {
					columns: [{ source: 'plan', periodOffset: 0 }, { source: 'actual', periodOffset: 0 }],
					sections: [{ title: 'Ausgaben', filter: { types: ['expense'] }, levels: [{ by: 'account', depth: 'all' }] }],
				},
			},
		})
		expect(created.status()).toBe(201)
		template = await created.json()
		const book = (description, account, amount) => api.createBooking(request, { date: period.startDate, description, debitAccountId: account.id, creditAccountId: bank.id, amount })
		await book(OWN[0], parent, 100)
		await book(OWN[1], child, 20)
		await book(OWN[2], grandchild, 3)
		await book(FOREIGN, office, 7)
	})

	test('die Option erscheint nur an einem Konto mit Unterkonten, zeigt den ganzen Ast und steht in der URL', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.goto(`${APP_URL}/bookings?period=${period.id}&account=${parent.id}`)
		await waitForAppLoaded(page)
		const section = visibleSection(page)
		const option = section.getByLabel('inkl. Unterkonten')

		// Ohne die Option: genau das Konto.
		await expect(option).not.toBeChecked()
		await expectVisibleRows(page, [OWN[0]])

		await option.check()
		await expectVisibleRows(page, OWN)
		await expect(page).toHaveURL(/[?&]sub=1(&|$)/)

		// Der Link übersteht das Neuladen.
		await page.reload()
		await waitForAppLoaded(page)
		await expect(visibleSection(page).getByLabel('inkl. Unterkonten')).toBeChecked()
		await expectVisibleRows(page, OWN)

		await visibleSection(page).getByLabel('inkl. Unterkonten').uncheck()
		await expectVisibleRows(page, [OWN[0]])
		await expect(page).not.toHaveURL(/[?&]sub=/)

		// Der Filter aufgehoben: keine Option mehr, alle Buchungen.
		await visibleSection(page).locator('.vbh-filter-select .vs__clear').click()
		await expect(visibleSection(page).getByLabel('inkl. Unterkonten')).toHaveCount(0)
		await expectVisibleRows(page, [...OWN, FOREIGN])
	})

	test('Unterkonto mit eigenem Unterkonto: der Ast beginnt dort; ein Konto ohne Unterkonten hat die Option nicht', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.goto(`${APP_URL}/bookings?period=${period.id}&account=${child.id}&sub=1`)
		await waitForAppLoaded(page)
		await expect(visibleSection(page).getByLabel('inkl. Unterkonten')).toBeChecked()
		await expectVisibleRows(page, [OWN[1], OWN[2]])

		// sub ohne Wirkung verschwindet aus der URL: Konto ohne Unterkonten bleibt filterbar,
		// ein sub mit fremdem Wert oder ohne Konto gilt nicht.
		await page.goto(`${APP_URL}/bookings?period=${period.id}&account=${grandchild.id}&sub=ja`)
		await waitForAppLoaded(page)
		await expect(page).not.toHaveURL(/[?&]sub=/, { timeout: 15000 })
		await expect(visibleSection(page).getByLabel('inkl. Unterkonten')).toHaveCount(0)
		await expectVisibleRows(page, [OWN[2]])

		await page.goto(`${APP_URL}/bookings?period=${period.id}&sub=1`)
		await waitForAppLoaded(page)
		await expect(page).not.toHaveURL(/[?&]sub=/, { timeout: 15000 })
		await expectVisibleRows(page, [...OWN, FOREIGN])
	})

	/** Öffnet die visuelle Ansicht mit der Vorlage und dem Stand „Aktuell“. */
	async function openVisual(page, templateId) {
		await openApp(page, USERS.verwalter)
		await page.evaluate((id) => {
			localStorage.setItem('vbh.structuredReport.template', id)
			localStorage.setItem('vbh.structuredReport.view', 'visual')
		}, String(templateId))
		await page.goto(`${APP_URL}/reports/structure?view=visual&period=${period.id}`)
		await waitForAppLoaded(page)
		const visual = visibleSection(page)
		await expect(visual.locator('.vbh-visual-tiles')).toBeVisible()
		const asOf = visual.locator('.vbh-visual-asof')
		await asOf.locator('.vs__dropdown-toggle').click()
		await page.locator('li.vs__dropdown-option', { hasText: 'Aktuell (alle Buchungen)' }).first().click()
		await expect(asOf.locator('.vs__selected')).toHaveText('Aktuell (alle Buchungen)')
		return visual
	}

	const LINK = `Buchungen von ${PARENT_LABEL} zeigen`

	test('Oberkonto in der visuellen Ansicht ist ein Link: die Liste zeigt, was die Zeile zählt', async ({ page, request }) => {
		const data = await api.getJson(request, `/report/structured/visual?template=${template.id}&asOf=current`)
		const row = data.rows.find((r) => r.kind === 'account' && r.accountId === parent.id)
		// 100 + 20 + 3: das Ist des Oberkontos schließt Unterkonto und Enkel ein.
		expect(row.actual).toBe(12300)
		expect(row.flags).toContain('parent')

		const visual = await openVisual(page, template.id)
		const parentRow = visual.locator('.vbh-visual-breakdown .vbh-visual-row', { has: page.getByRole('button', { name: LINK }) })
		await expect(parentRow.locator('.vbh-visual-row__actual')).toContainText('123,00')
		await parentRow.getByRole('button', { name: LINK }).click()

		await expect(page).toHaveURL(new RegExp(`/bookings\\?.*account=${parent.id}(&|$)`))
		await expect(page).toHaveURL(/[?&]sub=1(&|$)/)
		await expect(visibleSection(page).getByLabel('inkl. Unterkonten')).toBeChecked()
		await expectVisibleRows(page, OWN)
	})

	test('auch die Abweichungsliste führt beim Oberkonto samt Unterkonten in die Buchungen', async ({ page }) => {
		const visual = await openVisual(page, template.id)
		const gaps = visual.locator('.vbh-visual-dev')
			.filter({ has: page.getByRole('heading', { name: 'Größte Überschreitungen (Ausgaben)' }) })
		await gaps.getByRole('button', { name: LINK }).click()
		await expect(page).toHaveURL(new RegExp(`/bookings\\?.*account=${parent.id}(&|$)`))
		await expect(page).toHaveURL(/[?&]sub=1(&|$)/)
		await expectVisibleRows(page, OWN)
	})

	// Die mitgelieferte Vorlage trennt nach Sphären: die neuen Unterkonten haben
	// keine und stehen unter „Ohne Sphäre“. Die Zeile des Oberkontos im ideellen
	// Bereich zählt dann nur das Konto selbst – der Sprung filtert genau dieses.
	test('zählt die Zeile die Unterkonten nicht mit, filtert der Sprung nur das Konto', async ({ page, request }) => {
		const { templates } = await api.getJson(request, '/report-templates')
		const builtin = templates.find((t) => t.name === 'Haushaltsplan nach Titelgruppen (Zuwendungsgeber)')
		const data = await api.getJson(request, `/report/structured/visual?template=${builtin.id}&asOf=current`)
		const rows = data.rows.filter((r) => r.kind === 'account' && r.accountId === parent.id)
		const ownOnly = rows.find((r) => r.actual === 10000)
		expect(ownOnly, 'Zeile im ideellen Bereich').toBeTruthy()
		expect(ownOnly.flags).not.toContain('parent')
		expect(ownOnly.flags).not.toContain('mixed')

		const visual = await openVisual(page, builtin.id)
		const ownRow = visual.locator('.vbh-visual-breakdown .vbh-visual-row', { has: page.getByRole('button', { name: LINK }) })
			.filter({ has: page.locator('.vbh-visual-row__actual', { hasText: '100,00' }) }).first()
		await ownRow.getByRole('button', { name: LINK }).click()
		await expect(page).toHaveURL(new RegExp(`/bookings\\?.*account=${parent.id}(&|$)`))
		await expect(page).not.toHaveURL(/[?&]sub=/)
		await expect(visibleSection(page).getByLabel('inkl. Unterkonten')).not.toBeChecked()
		await expectVisibleRows(page, [OWN[0]])
	})

	test.describe('Handybreite', () => {
		test.use({ viewport: { width: 390, height: 844 }, hasTouch: true })

		test('die Option steckt hinter dem Filter-Knopf und wirkt', async ({ page }) => {
			await openApp(page, USERS.verwalter)
			await page.goto(`${APP_URL}/bookings?period=${period.id}&account=${parent.id}`)
			await waitForAppLoaded(page)
			const section = visibleSection(page)
			await expectVisibleRows(page, [OWN[0]])
			await expect(section.getByLabel('inkl. Unterkonten')).toHaveCount(0)

			await section.getByRole('button', { name: 'Filter (aktiv)', exact: true }).click()
			await section.getByLabel('inkl. Unterkonten').check()
			await expectVisibleRows(page, OWN)
			const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
			expect(pageOverflow, 'Seite scrollt waagerecht').toBeLessThanOrEqual(0)
		})
	})
})
