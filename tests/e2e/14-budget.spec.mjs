import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Finanzplan: Planwert setzen, im Berichts-Tab wiederfinden und einen
// eingefrorenen Planstand (Snapshot) anlegen und abrufen.

test.describe('Finanzplan', () => {
	// Der laufende Zeitraum: die Oberfläche wählt ihn beim Laden vor, also
	// muss der Planwert genau dort liegen, damit ihn der erste Test sieht.
	// Bis 0.32.0 stand hier die feste Jahreszahl 2026.
	let period

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		period = await api.periodIdForDate(request, new Date().toISOString().slice(0, 10))
		const income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.raw(request, 'POST', '/budget', {
			data: { accountId: income.id, period, amount: 500, note: 'Planwert für Beiträge' },
		})
	})

	test('gesetzter Planwert erscheint im Finanzplan', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Finanzplan' }).click()
		await expect(visibleSection(page).getByText('500,00').first()).toBeVisible()
	})

	test('Planstand einfrieren und wieder abrufen (API)', async ({ request }) => {
		const created = await api.raw(request, 'POST', '/budget/snapshots', {
			data: { period, label: 'Stand Mitgliederversammlung' },
		})
		expect(created.status()).toBe(200)
		const snapshot = await created.json()

		const list = await api.getJson(request, `/budget/snapshots?period=${period}`)
		expect(list.some((s) => s.id === snapshot.id)).toBe(true)

		const detail = await api.raw(request, 'GET', `/budget/snapshots/${snapshot.id}`)
		expect(detail.status()).toBe(200)
	})

	// Issue #34: die Planwerte standen als nackte Zahl ("20000") neben den
	// formatierten Spalten "Ist" und "Differenz". Geprueft wird die ganze
	// Kette: eingetippt wird wie ein Mensch tippt, angezeigt wird formatiert,
	// und beim Neuladen ist der Wert auch wirklich im Backend angekommen.
	test('Planwerte werden formatiert angezeigt und eingelesen', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Finanzplan', exact: true }).click()

		// Der Planwert aus dem beforeAll steht formatiert da, wie die Spalten
		// "Ist" und "Differenz" daneben.
		const feld = visibleSection(page).locator('.vbh-planinput').first()
		await expect(feld).toHaveValue(/^500,00\s*€$/)

		// Beim Bearbeiten gibt das Feld den nackten Wert frei - niemand soll
		// gegen eine mitlaufende Maske antippen muessen.
		await feld.focus()
		await expect(feld).toHaveValue('500')

		// Tausenderpunkt: eingetippt "20000", angezeigt "20.000,00 €".
		await feld.fill('20000')
		await feld.blur()
		await expect(feld).toHaveValue(/^20\.000,00\s*€$/)

		// Deutsche Schreibweise mit Punkt UND Komma wird richtig gelesen.
		await feld.fill('50.000,50')
		await feld.blur()
		await expect(feld).toHaveValue(/^50\.000,50\s*€$/)

		// Unlesbares springt auf den letzten gueltigen Wert zurueck, statt
		// den Planwert stillschweigend auf 0 zu setzen.
		await feld.fill('keine Zahl')
		await feld.blur()
		await expect(feld).toHaveValue(/^50\.000,50\s*€$/)

		// Gespeichert wird auch wirklich.
		await page.reload()
		await page.locator('.vbh').waitFor()
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Finanzplan', exact: true }).click()
		await expect(visibleSection(page).locator('.vbh-planinput').first()).toHaveValue(/^50\.000,50\s*€$/)
	})

	// Ein "&" im Kontonamen stand in den Beschriftungen des Finanzplans als
	// "&amp;" ("Notiz zu 5930 Anschaffung &amp; Wartung Technik"):
	// @nextcloud/l10n kodierte den Platzhalterwert als HTML, und Vue escapte
	// im Template ein zweites Mal. Geprueft werden beide Stellen, an denen
	// der Kontoname ueber t() in eine Beschriftung laeuft.
	test('Sonderzeichen im Kontonamen bleiben in den Beschriftungen lesbar', async ({ page, request }) => {
		const name = 'Anschaffung & Wartung Technik'
		// Idempotent: nach einem Worker-Neustart laeuft beforeAll erneut, ohne
		// Neustart waere das Konto aus einem frueheren Durchlauf noch da.
		const vorhanden = (await api.listAccounts(request)).some((a) => a.number === '5930')
		if (!vorhanden) {
			await api.createAccount(request, { number: '5930', name, type: 'expense', category: 'Ausgaben' })
		}

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Finanzplan', exact: true }).click()

		// Nur sichtbare Zeilen: die Saldenliste der (per v-show versteckten)
		// Auswertung fuehrt dasselbe Konto ohne Planwert-Feld und kaeme sonst
		// im DOM zuerst.
		const zeile = visibleSection(page).locator('tr:visible', { hasText: name }).first()
		await expect(zeile.locator('.vbh-planinput')).toHaveAttribute('aria-label', `Planwert für 5930 ${name}`)

		await zeile.getByRole('button', { name: 'Notiz zur Planzahl hinzufügen' }).click()
		const label = visibleSection(page).locator('.vbh-note-row .vbh-note-label').first()
		await expect(label).toContainText(`Notiz zu 5930 ${name}`)
		await expect(label).not.toContainText('&amp;')
	})

	// Planbaum: geplant wird auf einem Oberkonto, gebucht auf Unterkonten.
	// Je Zweig gibt es nur eine Planungsebene; das Unterkonto zeigt statt des
	// Eingabefelds, wo der Plan liegt, und der Server lehnt einen zweiten
	// Plan im selben Zweig ab.
	test.describe('Planbaum', () => {
		let parent
		let child

		test.beforeAll(async ({ request }) => {
			const accounts = await api.listAccounts(request)
			parent = accounts.find((a) => a.number === '6100')
				|| await api.createAccount(request, { number: '6100', name: 'Verwaltung gesamt', type: 'expense', category: 'Ausgaben' })
			child = accounts.find((a) => a.number === '6100 01')
				|| await api.createAccount(request, { number: '6100 01', name: 'Porto', type: 'expense', category: 'Ausgaben', parentId: parent.id })
			await api.raw(request, 'POST', '/budget', { data: { accountId: child.id, period, amount: 0 } })
			await api.raw(request, 'POST', '/budget', { data: { accountId: parent.id, period, amount: 1200 } })
		})

		test('Plan auf dem Oberkonto sperrt das Unterkonto (API)', async ({ request }) => {
			const blocked = await api.raw(request, 'POST', '/budget', { data: { accountId: child.id, period, amount: 300 } })
			expect(blocked.status()).toBe(409)
			expect((await blocked.json()).blockingAccountId).toBe(parent.id)

			// 0 geht immer – so lassen sich alte Doppelplanungen bereinigen.
			const zero = await api.raw(request, 'POST', '/budget', { data: { accountId: child.id, period, amount: 0, note: 'Teil von 6100' } })
			expect(zero.status()).toBe(200)

			const budget = await api.getJson(request, `/budget?period=${period}`)
			const p = budget.rows.find((r) => r.accountId === parent.id)
			const c = budget.rows.find((r) => r.accountId === child.id)
			expect(p.planSum).toBe(1200)
			expect(p.hasChildren).toBe(true)
			expect(c.treeParentId).toBe(parent.id)
			expect(c.planLockedBy.accountId).toBe(parent.id)
			expect(c.diff).toBeNull()
		})

		test('Unterkonten auf- und zuklappen, gesperrtes Planfeld', async ({ page }) => {
			await openApp(page, USERS.verwalter)
			await switchTab(page, 'Berichte')
			await visibleSection(page).getByRole('button', { name: 'Finanzplan', exact: true }).click()

			const toggle = visibleSection(page).getByRole('button', { name: 'Unterkonten von 6100 Verwaltung gesamt aufklappen' })
			const kind = visibleSection(page).locator('tr:visible', { hasText: 'Porto' })
			// Startzustand: bis zur Planungsebene (6100) – das Unterkonto ist zu.
			await expect(kind).toHaveCount(0)
			await toggle.click()
			await expect(kind).toHaveCount(1)
			await expect(kind.locator('.vbh-planinput')).toHaveCount(0)
			await expect(kind.locator('.vbh-budgettree-locked').first()).toHaveAttribute('title', 'Plan liegt auf 6100 Verwaltung gesamt')

			await visibleSection(page).getByRole('button', { name: 'Unterkonten von 6100 Verwaltung gesamt einklappen' }).click()
			await expect(kind).toHaveCount(0)
		})
	})
})
