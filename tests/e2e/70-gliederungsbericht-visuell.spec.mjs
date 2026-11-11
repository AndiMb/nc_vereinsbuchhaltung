import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BASE_URL, INCOME_ACCOUNT, openApp, switchTab, USERS, visibleSection, waitForAppLoaded } from './fixtures/nextcloud.mjs'

// Visuelle Plan-Ist-Ansicht des Gliederungsberichts: Datenroute (Status,
// Abweichungen, Stand, Rechte) und Oberfläche (Umschalter, Kacheln,
// Ampelfilter, Vergleichsbasis ohne Nachladen, URL, Handybreite).
//
// Bestand: Ausgabenkonto 5000 mit Plan 1.000 € und 1.300 € Ist (überschritten),
// Einnahmenkonto 4000 mit Plan 1.000 € und 990 € Ist – gegen den Jahresplan
// „beobachten“, zeitanteilig „im Plan“, solange die Periode nicht vorbei ist.

const GRANTOR = 'Haushaltsplan nach Titelgruppen (Zuwendungsgeber)'
const EXPENSE_ACCOUNT = '5000'
const VALUE_KINDS = ['line', 'account', 'footer', 'total']
const VISUAL_ROUTE = '/report/structured/visual'

async function openStructure(page, user) {
	await openApp(page, user)
	const intro = page.getByRole('button', { name: 'Verstanden' })
	if (await intro.isVisible().catch(() => false)) { await intro.click() }
	await switchTab(page, 'Berichte')
	await visibleSection(page).getByRole('button', { name: 'Gliederung', exact: true }).click()
	await expect(visibleSection(page).locator('.vbh-structured-picker')).toBeVisible()
}

/** Umschalter „Visuell“ und warten, bis die Kacheln stehen. */
async function showVisual(page) {
	await visibleSection(page).getByRole('button', { name: 'Visuell', exact: true }).click()
	await expect(visibleSection(page).locator('.vbh-visual-tiles')).toBeVisible()
}

function tile(page, title) {
	return visibleSection(page).locator('.vbh-visual-tile')
		.filter({ has: page.getByRole('heading', { name: title, exact: true }) })
}

/** Die laufende Periode und das Datum ihres ersten Tages (also im ersten Monat). */
async function currentPeriod(request) {
	const today = new Date().toISOString().slice(0, 10)
	const id = await api.periodIdForDate(request, today)
	const period = (await api.listPeriods(request)).find((p) => p.id === id)
	return { id, firstDay: period.startDate }
}

async function templateId(request, name) {
	const { templates } = await api.getJson(request, '/report-templates')
	return templates.find((t) => t.name === name).id
}

test.describe('Gliederungsbericht visuell', () => {
	let expense
	let income

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, inc, exp] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT, EXPENSE_ACCOUNT)
		expense = exp
		income = inc
		const { id: period, firstDay } = await currentPeriod(request)
		await api.raw(request, 'POST', '/budget', { data: { accountId: expense.id, period, amount: 1000 } })
		await api.raw(request, 'POST', '/budget', { data: { accountId: income.id, period, amount: 1000 } })
		await api.createBooking(request, {
			date: firstDay,
			description: 'Miete über Plan',
			debitAccountId: expense.id,
			creditAccountId: bank.id,
			amount: 1300,
		})
		await api.createBooking(request, {
			date: firstDay,
			description: 'Beiträge knapp unter Plan',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 990,
		})
	})

	test('Ausgaben über Plan: Kennzahl, Status und größte Abweichung (API)', async ({ request }) => {
		const id = await templateId(request, GRANTOR)
		const data = await api.getJson(request, `${VISUAL_ROUTE}?template=${id}&asOf=current`)

		expect(data.asOf.value).toBe('current')
		expect(data.kpis.expense.actual).toBe(130000)
		expect(data.kpis.expense.plan).toBe(100000)
		expect(data.kpis.expense.status.year.level).toBe('crit')
		expect(data.deviations.year.expense[0]).toMatchObject({ accountId: expense.id, number: EXPENSE_ACCOUNT, amount: 30000, level: 'crit' })
	})

	test('ungültiger Stand 422, unbekannte Vorlage 404, leerer Stand = Vorgabe (API)', async ({ request }) => {
		const id = await templateId(request, GRANTOR)

		const invalid = await api.raw(request, 'GET', `${VISUAL_ROUTE}?template=${id}&asOf=2026-13`)
		expect(invalid.status()).toBe(422)

		const unknown = await api.raw(request, 'GET', `${VISUAL_ROUTE}?template=999999&asOf=current`)
		expect(unknown.status()).toBe(404)

		const empty = await api.raw(request, 'GET', `${VISUAL_ROUTE}?template=${id}&asOf=`)
		expect(empty.status()).toBe(200)
		const { asOf } = await empty.json()
		expect(asOf.value).toBe(asOf.default)
	})

	test('Standardvorlage ohne Ist-Spalte liefert Plan und Ist je Wertzeile (API)', async ({ request }) => {
		const { templates } = await api.getJson(request, '/report-templates')
		const grantor = templates.find((t) => t.name === GRANTOR)
		// Die Vorlage selbst kennt nur Planspalten.
		expect(grantor.definition.columns.every((c) => c.source === 'plan')).toBe(true)

		const data = await api.getJson(request, `${VISUAL_ROUTE}?template=${grantor.id}&asOf=current`)
		const valueRows = data.rows.filter((r) => VALUE_KINDS.includes(r.kind))
		expect(valueRows.length).toBeGreaterThan(0)
		for (const row of valueRows) {
			expect(Number.isInteger(row.plan), `plan in ${row.kind} „${row.label}“`).toBe(true)
			expect(Number.isInteger(row.actual), `actual in ${row.kind} „${row.label}“`).toBe(true)
		}
		const rent = valueRows.find((r) => r.kind === 'account' && r.accountId === expense.id)
		expect(rent).toMatchObject({ plan: 100000, actual: 130000 })
	})

	test('Revisor liest, ohne Rolle kein Zugriff (API)', async ({ request }) => {
		const id = await templateId(request, GRANTOR)
		const revisor = await api.raw(request, 'GET', `${VISUAL_ROUTE}?template=${id}`, { user: USERS.revisor })
		expect(revisor.status()).toBe(200)
		const none = await api.raw(request, 'GET', `${VISUAL_ROUTE}?template=${id}`, { user: USERS.ohneRolle })
		expect(none.status()).toBe(403)
	})

	test('Umschalten, Ampelfilter, Neuladen und zurück zur Tabelle', async ({ page }) => {
		await openStructure(page, USERS.verwalter)
		const table = visibleSection(page).locator('.vbh-structured-table')
		await expect(table).toBeVisible()
		const tableBefore = await table.innerText()

		await showVisual(page)
		await expect(page).toHaveURL(/[?&]view=visual/)
		await expect(tile(page, 'Ausgaben').locator('.vbh-visual-pill')).toHaveText(/überschritten/)

		const breakdown = visibleSection(page).locator('.vbh-visual-breakdown')
		await expect(breakdown).toContainText('Mitgliedsbeiträge')
		await expect(breakdown).toContainText('Raum- / Mietkosten')

		// Rote Stufe im Verteilungsbalken der Ausgaben
		const expenseDist = visibleSection(page).locator('.vbh-visual-dist')
			.filter({ has: page.getByRole('heading', { name: 'Ampel der Ausgabenkonten' }) })
		// Nach dem Klick (Fokus und Maus auf dem Segment) bleibt es rot und ist
		// als gewählt markiert – Nextclouds Hover-/Fokusstil nackter Knöpfe
		// färbte es vorher grau.
		const critSegment = expenseDist.getByRole('button', { name: 'überschritten: 1' })
		const colourOf = (el) => getComputedStyle(el).backgroundColor
		const colourBefore = await critSegment.evaluate(colourOf)
		expect(colourBefore).not.toBe('rgba(0, 0, 0, 0)')
		await critSegment.click()
		await expect(critSegment).toHaveAttribute('aria-pressed', 'true')
		await expect.poll(() => critSegment.evaluate(colourOf)).toBe(colourBefore)
		await expect(breakdown.locator('.vbh-visual-filter')).toContainText('Ausgaben – überschritten')
		// Die Vorlage führt das Konto zweimal (Abschnitt und Zusammenfassung);
		// der Filter zeigt es nur einmal.
		const accounts = breakdown.locator('.vbh-visual-row__link, .vbh-visual-row__text--account')
		await expect(accounts.first()).toBeVisible()
		const shown = await accounts.allInnerTexts()
		expect(shown.length, shown.join(' | ')).toBe(1)
		expect(shown.every((text) => text.trim() === `${EXPENSE_ACCOUNT} Raum- / Mietkosten`), shown.join(' | ')).toBe(true)
		await expect(breakdown).not.toContainText('Mitgliedsbeiträge')

		await breakdown.getByRole('button', { name: 'Filter aufheben' }).click()
		await expect(breakdown.locator('.vbh-visual-filter')).toHaveCount(0)
		await expect(breakdown).toContainText('Mitgliedsbeiträge')
		await expect(breakdown).toContainText('Raum- / Mietkosten')

		await page.reload()
		await waitForAppLoaded(page)
		await expect(visibleSection(page).locator('.vbh-visual-tiles')).toBeVisible()
		await expect(page).toHaveURL(/[?&]view=visual/)

		await visibleSection(page).getByRole('button', { name: 'Tabelle', exact: true }).click()
		await expect(table).toBeVisible()
		await expect(visibleSection(page).locator('.vbh-visual')).toHaveCount(0)
		await expect(page).not.toHaveURL(/view=visual/)
		expect(await table.innerText()).toBe(tableBefore)
	})

	test('Vergleichsbasis „Zeitanteilig“ ändert den Status ohne neue Anfrage', async ({ page }) => {
		const visualRequests = []
		page.on('request', (r) => {
			if (r.url().includes(VISUAL_ROUTE)) { visualRequests.push(r.url()) }
		})
		await openStructure(page, USERS.verwalter)
		await showVisual(page)

		const incomePill = tile(page, 'Einnahmen').locator('.vbh-visual-pill')
		const yearButton = visibleSection(page).getByRole('button', { name: 'Jahresplan', exact: true })
		const timeButton = visibleSection(page).getByRole('button', { name: 'Zeitanteilig', exact: true })
		await expect(yearButton).toHaveAttribute('aria-pressed', 'true')
		await expect(incomePill).toHaveText(/beobachten/)
		const before = visualRequests.length
		expect(before).toBeGreaterThan(0)

		await timeButton.click()
		await expect(timeButton).toHaveAttribute('aria-pressed', 'true')
		await expect(incomePill).toHaveText(/im Plan/)
		expect(visualRequests.length).toBe(before)

		// Zurück auf den Jahresplan, damit die gemerkte Wahl keine Folgetests färbt.
		await yearButton.click()
		await expect(incomePill).toHaveText(/beobachten/)
	})

	test('Klick auf ein Konto im Aufriss öffnet die gefilterte Buchungsliste; Zurück führt in die visuelle Ansicht', async ({ page, request }) => {
		const { id: period } = await currentPeriod(request)
		await openStructure(page, USERS.verwalter)
		await showVisual(page)

		// Die Vorlage führt das Konto zweimal (Abschnitt und Zusammenfassung).
		const breakdown = visibleSection(page).locator('.vbh-visual-breakdown')
		await breakdown.getByRole('button', { name: `Buchungen von ${EXPENSE_ACCOUNT} Raum- / Mietkosten zeigen` }).first().click()

		await expect(page).toHaveURL(new RegExp(`/bookings\\?.*account=${expense.id}(&|$)`))
		await expect(page).toHaveURL(new RegExp(`[?&]period=${period}(&|$)`))
		const section = visibleSection(page)
		await expect(section.locator('.vbh-filter-select .vs__selected')).toContainText(EXPENSE_ACCOUNT)
		await expect(section.getByText('Miete über Plan').first()).toBeVisible()
		// Ausgefilterte Zeilen bleiben im DOM (nur hidden) – gezählt werden sichtbare.
		await expect(section.getByText('Beiträge knapp unter Plan').filter({ visible: true })).toHaveCount(0)

		await page.goBack()
		await expect(page).toHaveURL(/\/reports\/structure\?.*view=visual/)
		await expect(visibleSection(page).locator('.vbh-visual-tiles')).toBeVisible()
	})

	test('Klick in der Liste der größten Lücken öffnet die Buchungen des Einnahmenkontos', async ({ page }) => {
		await openStructure(page, USERS.verwalter)
		await showVisual(page)

		const gaps = visibleSection(page).locator('.vbh-visual-dev')
			.filter({ has: page.getByRole('heading', { name: 'Größte Lücken (Einnahmen)' }) })
		await gaps.getByRole('button', { name: `Buchungen von ${INCOME_ACCOUNT} Mitgliedsbeiträge zeigen` }).click()

		await expect(page).toHaveURL(new RegExp(`/bookings\\?.*account=${income.id}(&|$)`))
		const section = visibleSection(page)
		await expect(section.locator('.vbh-filter-select .vs__selected')).toContainText(INCOME_ACCOUNT)
		await expect(section.getByText('Beiträge knapp unter Plan').first()).toBeVisible()
		await expect(section.getByText('Miete über Plan').filter({ visible: true })).toHaveCount(0)
	})

	test('Start-URL mit view=visual öffnet die visuelle Ansicht', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		// Die gemerkte Wahl löschen: die URL allein soll entscheiden.
		await page.evaluate(() => localStorage.removeItem('vbh.structuredReport.view'))
		await page.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/reports/structure?view=visual`)
		await waitForAppLoaded(page)
		await expect(visibleSection(page).locator('.vbh-visual-tiles')).toBeVisible()
		await expect(visibleSection(page).getByRole('button', { name: 'Visuell', exact: true })).toHaveAttribute('aria-pressed', 'true')
		await expect(visibleSection(page).locator('.vbh-structured-table')).toHaveCount(0)
	})

	test('gemerkte Wahl ohne view in der Start-URL ergänzt view=visual', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.evaluate(() => localStorage.setItem('vbh.structuredReport.view', 'visual'))
		await page.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/reports/structure`)
		await waitForAppLoaded(page)
		await expect(visibleSection(page).locator('.vbh-visual-tiles')).toBeVisible()
		await expect(page).toHaveURL(/[?&]view=visual/)
	})

	test.describe('Handybreite', () => {
		test.use({ viewport: { width: 390, height: 844 }, hasTouch: true })

		test('kein waagerechtes Scrollen, Ampel-Pillen mit Text', async ({ page }) => {
			await openApp(page, USERS.verwalter)
			const intro = page.getByRole('button', { name: 'Verstanden' })
			if (await intro.isVisible().catch(() => false)) { await intro.click() }
			await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Berichte' }).click()
			await visibleSection(page).getByRole('button', { name: 'Gliederung', exact: true }).click()
			await showVisual(page)
			await expect(visibleSection(page).locator('.vbh-visual--mobile')).toBeVisible()

			const rentRow = visibleSection(page).locator('.vbh-visual-row', { hasText: `${EXPENSE_ACCOUNT} Raum- / Mietkosten` })
			const pillText = rentRow.first().locator('.vbh-visual-pill__text')
			await expect(pillText).toBeVisible()
			await expect(pillText).toHaveText('überschritten')

			const overflow = await visibleSection(page).locator('.vbh-sectionbody').evaluate((el) => el.scrollWidth - el.clientWidth)
			expect(overflow, 'Inhaltsbereich scrollt waagerecht').toBeLessThanOrEqual(0)
			const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
			expect(pageOverflow, 'Seite scrollt waagerecht').toBeLessThanOrEqual(0)
		})
	})
})

// Plan auf dem Oberkonto 5000, gebucht auf ein Unterkonto; eine eigene Vorlage
// zeigt alle Kontoebenen. Das Unterkonto ist dann nicht „ungeplant“.
test.describe('Gliederungsbericht visuell: Unterkonto unter geplantem Oberkonto', () => {
	const CHILD_LABEL = `${EXPENSE_ACCOUNT} 99 Nebenkosten (E2E)`
	let child
	let template

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)
		child = await api.createAccount(request, { number: `${EXPENSE_ACCOUNT} 99`, name: 'Nebenkosten (E2E)', type: 'expense', parentId: expense.id })
		const { id: period, firstDay } = await currentPeriod(request)
		await api.raw(request, 'POST', '/budget', { data: { accountId: expense.id, period, amount: 1000 } })
		await api.createBooking(request, {
			date: firstDay,
			description: 'Nebenkosten auf dem Unterkonto',
			debitAccountId: child.id,
			creditAccountId: bank.id,
			amount: 300,
		})
		const created = await api.raw(request, 'POST', '/report-templates', {
			data: {
				name: 'Alle Ausgabenkonten (E2E)',
				definition: {
					columns: [{ source: 'plan', periodOffset: 0 }, { source: 'actual', periodOffset: 0 }],
					sections: [{ title: 'Ausgaben', filter: { types: ['expense'] }, levels: [{ by: 'account', depth: 'all' }] }],
				},
			},
		})
		expect(created.status()).toBe(201)
		template = await created.json()
	})

	test('Zeile ohne Ampel, nicht unter „ungeplant“ (API)', async ({ request }) => {
		const data = await api.getJson(request, `${VISUAL_ROUTE}?template=${template.id}&asOf=current`)
		const row = data.rows.find((r) => r.kind === 'account' && r.accountId === child.id)
		expect(row).toMatchObject({ plan: 0, actual: 30000 })
		expect(row.flags).toContain('covered')
		expect(row.status.year.level).toBe('none')
		expect(row.status.time.level).toBe('none')
		expect(data.distribution.year.expense.unplanned).toBe(0)
	})

	test('Hinweis „im Oberkonto geplant“, Filter „ungeplant“ leer', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.evaluate((id) => {
			localStorage.setItem('vbh.structuredReport.template', id)
			localStorage.setItem('vbh.structuredReport.view', 'visual')
		}, String(template.id))
		await page.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/reports/structure?view=visual`)
		await waitForAppLoaded(page)
		const breakdown = visibleSection(page).locator('.vbh-visual-breakdown')
		await expect(breakdown).toBeVisible()

		const childRow = breakdown.locator('.vbh-visual-row', { hasText: CHILD_LABEL })
		await expect(childRow.locator('.vbh-pill')).toHaveText('im Oberkonto geplant')
		await expect(childRow.locator('.vbh-visual-pill')).toHaveCount(0)
		// Ohne eigenen Plan kein Balken; die Zeile führt trotzdem zu den Buchungen.
		await expect(childRow.locator('.vbh-visual-bullet')).toHaveCount(0)
		await expect(childRow.getByRole('button', { name: `Buchungen von ${CHILD_LABEL} zeigen` })).toBeVisible()

		const expenseDist = visibleSection(page).locator('.vbh-visual-dist')
			.filter({ has: page.getByRole('heading', { name: 'Ampel der Ausgabenkonten' }) })
		await expenseDist.locator('.vbh-visual-dist__legend-item', { hasText: 'ungeplant' }).click()
		await expect(breakdown.locator('.vbh-visual-filter')).toContainText('Ausgaben – ungeplant')
		await expect(breakdown).toContainText('Kein Konto der Gliederung hat diese Ampelstufe.')
		await expect(breakdown).not.toContainText(CHILD_LABEL)
	})
})

test.describe('Gliederungsbericht visuell ohne Plan', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		const { firstDay } = await currentPeriod(request)
		await api.createBooking(request, {
			date: firstDay,
			description: 'Beitrag ohne Plan',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 50,
		})
	})

	test('Hinweis auf den Finanzplan, Kacheln bleiben sichtbar', async ({ page }) => {
		await openStructure(page, USERS.verwalter)
		await showVisual(page)
		await expect(visibleSection(page).getByText('Für diese Periode ist noch kein Plan erfasst')).toBeVisible()
		await expect(visibleSection(page).getByText('Die Planwerte tragen Sie im Finanzplan ein.')).toBeVisible()
		await expect(tile(page, 'Einnahmen')).toBeVisible()
		await expect(tile(page, 'Ausgaben')).toBeVisible()
		await expect(tile(page, 'Ergebnis')).toBeVisible()
	})
})
