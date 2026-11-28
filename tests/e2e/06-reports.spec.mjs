import { expect, test } from '@playwright/test'
import { api, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Das Konto-Formular schickt immer alle Felder mit; ein Teil-Update wuerde
// z. B. die IBAN mitnehmen (siehe AccountService::update()).
function kontoFelder(account) {
	return {
		number: account.number,
		name: account.name,
		type: account.type,
		category: account.category,
		isBank: account.isBank,
		parentId: account.parentId || 0,
		sphere: account.sphere || '',
		reserveKind: account.reserveKind || '',
		iban: account.iban || '',
		costCenterId: account.costCenterId || 0,
		active: account.active !== false,
	}
}

// Berichte auf Basis des Beispielvereins: die Berichts-Ansichten rendern,
// die druckfertigen Seiten und die CSV-Exporte liefern Inhalt.

test.describe('Berichte und Exporte', () => {
	let demoPeriod

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDemo(request)
		const periods = await api.listPeriods(request)
		demoPeriod = periods[periods.length - 1].id
	})

	test('die Berichts-Ansichten rendern mit Daten', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')

		// exact: true - "Auswertung" traefe sonst als Teilzeichenkette auch
		// den Nachbarknopf "Auswertungsgruppen".
		await expect(visibleSection(page).getByRole('button', { name: 'Auswertung', exact: true })).toBeVisible()
		for (const view of ['Sphären', 'Rücklagen', 'Finanzplan', 'Protokoll']) {
			await visibleSection(page).getByRole('button', { name: view, exact: true }).click()
		}
		// Das Protokoll kennt mindestens das Anlegen der Beispieldaten.
		await expect(visibleSection(page).getByText('Beispieldaten angelegt').first()).toBeVisible()
	})

	test('Kassenbericht und Kurzbericht sind druckfertige Seiten', async ({ request }) => {
		for (const report of ['kassenbericht', 'kurzbericht']) {
			const resp = await api.raw(request, 'GET', `/export/${report}?period=${demoPeriod}`, { user: USERS.revisor })
			expect(resp.status(), report).toBe(200)
			expect(await resp.text()).toContain('<html')
		}
	})

	// Issue #31: die Kopfzeile zeigte nur das erste Geldkonto nach Kontonummer.
	// Geprueft wird die ganze Kette - Kennzeichen am Konto, Zahl im Backend,
	// Chip und Summenzeile in der Oberflaeche -, weil die Rechnung allein
	// (LedgerAggregatorTest) nicht verraet, ob die Oberflaeche das richtige
	// Feld liest.
	test('die Kopfzeile summiert alle Geldkonten, abgewaehlte bleiben draussen', async ({ page, request }) => {
		const vorher = await api.getJson(request, '/journal/balances')
		expect(vorher.bankReconciliation.length, 'Beispielverein braucht mehrere Geldkonten').toBeGreaterThan(1)
		const summe = vorher.bankReconciliation.reduce((acc, b) => acc + b.balance, 0)
		expect(vorher.bankTotal.balance).toBeCloseTo(summe, 2)
		expect(vorher.bankTotal.count).toBe(vorher.bankTotal.allCount)

		await openApp(page, USERS.verwalter)
		// Beschriftung statt Kontoname heisst: die Zahl fasst mehrere Konten
		// zusammen. Vor Issue #31 stand hier der Name des ersten Geldkontos.
		await expect(page.locator('.vbh-bankchip')).toContainText('Geldbestand')
		// Betraege werden zwischen Chip und Tabelle verglichen, nicht gegen
		// einen in Node formatierten String: beide Seiten kommen dann aus
		// derselben Intl-Implementierung des Browsers.
		const chipWert = page.locator('.vbh-bankchip-value')
		// Ueber visibleSection: die Tab-Bereiche haengen alle per v-show im DOM,
		// ein ungebundenes .vbh-tablecard traefe sonst die Tabelle eines
		// ausgeblendeten Tabs. Auf der Uebersicht ist die Geldkonten-Tabelle
		// die erste.
		const geldkonten = visibleSection(page).locator('.vbh-tablecard table').first()
		await expect(geldkonten).toContainText('Summe')
		const summenZelle = geldkonten.locator('tfoot tr').first().locator('td.num').first()
		// trim(): der Zellinhalt steht im Template eingerueckt auf eigener
		// Zeile, textContent liefert die Einrueckung mit.
		const text = async (locator) => (await locator.textContent()).trim()
		expect(await text(chipWert)).toBe(await text(summenZelle))

		// Das Geldkonto mit dem groessten Bestand herausnehmen - bei einem
		// Konto mit Saldo 0 waere der Unterschied zur Gesamtsumme nicht
		// sichtbar und der Test bewiese nichts.
		const abgewaehlt = [...vorher.bankReconciliation].sort((a, b) => Math.abs(b.balance) - Math.abs(a.balance))[0]
		expect(Math.abs(abgewaehlt.balance), 'Beispielverein braucht ein Geldkonto mit Bestand').toBeGreaterThan(0)
		const [konto] = await api.accountsByNumber(request, abgewaehlt.number)
		await api.updateAccount(request, konto.id, { ...kontoFelder(konto), countInTotal: false })
		try {
			const nachher = await api.getJson(request, '/journal/balances')
			expect(nachher.bankTotal.count).toBe(vorher.bankTotal.allCount - 1)
			expect(nachher.bankTotal.balance).toBeCloseTo(summe - abgewaehlt.balance, 2)
			// Die Gesamtsumme bleibt unberuehrt - das Geld ist ja noch da.
			expect(nachher.bankTotal.allBalance).toBeCloseTo(summe, 2)

			await page.reload()
			await page.locator('.vbh').waitFor()
			await expect(geldkonten).toContainText('nicht im Geldbestand')
			await expect(geldkonten).toContainText('davon Geldbestand (Kopfzeile)')
			// Der Chip zeigt jetzt die Zeile "davon", nicht mehr die Summe.
			const davonZelle = geldkonten.locator('tfoot tr').nth(1).locator('td.num').first()
			expect(await text(chipWert)).toBe(await text(davonZelle))
			expect(await text(chipWert)).not.toBe(await text(summenZelle))
		} finally {
			await api.updateAccount(request, konto.id, { ...kontoFelder(konto), countInTotal: true })
		}
	})

	// Issue #393: der Anfangsbestand eines Kontos ohne Geldkonto-Kennzeichen
	// (hier eine Verbindlichkeit aus Vorjahren) stand als Einnahme des Jahres
	// im Ergebnis. Gegen die echte Instanz geprueft, weil die Regel in der
	// Summenabfrage steckt und kein Unit-Test sie erreicht.
	test('ein Anfangsbestand auf einem Nicht-Geldkonto ist kein Umsatz', async ({ request }) => {
		const periods = await api.listPeriods(request)
		const periode = periods.find((p) => p.id === demoPeriod)
		const balances = `/journal/balances?period=${demoPeriod}`
		const vorher = await api.getJson(request, balances)

		const konto = await api.createAccount(request, {
			number: '3559',
			name: 'Erhaltene Kautionen E2E',
			type: 'liability',
			category: 'Verbindlichkeiten',
		})
		try {
			const resp = await api.raw(request, 'POST', `/accounts/${konto.id}/opening`, {
				data: { amount: 1000, date: periode.startDate },
			})
			expect(resp.status()).toBe(200)

			const nachher = await api.getJson(request, balances)
			expect(nachher.totals.income).toBeCloseTo(vorher.totals.income, 2)
			expect(nachher.totals.expense).toBeCloseTo(vorher.totals.expense, 2)
			expect(nachher.totals.result).toBeCloseTo(vorher.totals.result, 2)
			// Der Bestand selbst bleibt am Konto sichtbar.
			const zeile = nachher.accounts.find((a) => a.accountId === konto.id)
			expect(zeile.credit).toBeCloseTo(1000, 2)
			expect(zeile.balance).toBeCloseTo(1000, 2)

			// Kassenbericht und Einnahmen-/Ausgaben-Export zeigen nur Konten mit
			// Umsatz - das Konto darf dort gar nicht erst auftauchen.
			for (const path of [`/export/kassenbericht?period=${demoPeriod}`, `/export/report?period=${demoPeriod}`]) {
				const body = await (await api.raw(request, 'GET', path, { user: USERS.revisor })).text()
				expect(body, path).not.toContain('Erhaltene Kautionen E2E')
			}
			// Die Sphaerenrechnung summiert dieselben Konten wie das Ergebnis.
			const sphaeren = await api.getJson(request, `/report/spheres?period=${demoPeriod}`)
			expect(sphaeren.totals.income).toBeCloseTo(vorher.totals.income, 2)
		} finally {
			await api.raw(request, 'POST', `/accounts/${konto.id}/opening`, { data: { amount: 0 } })
			await api.deleteAccount(request, konto.id)
		}
	})

	test('CSV-Exporte liefern Journal und Saldenliste', async ({ request }) => {
		for (const path of [`/export/journal?period=${demoPeriod}`, `/export/balances?period=${demoPeriod}`]) {
			const resp = await api.raw(request, 'GET', path, { user: USERS.revisor })
			expect(resp.status(), path).toBe(200)
			const body = await resp.text()
			expect(body.split('\n').length, path).toBeGreaterThan(2)
		}
	})
})
