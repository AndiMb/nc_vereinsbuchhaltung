import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, neuAnlegen, openApp, pickNcSelectOption, selectPeriod, skipBookingTour, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Kostenstellen an der Buchungszeile (Issue #7): ein Betrag auf einem Konto
// lässt sich auf mehrere Kostenstellen verteilen ("1.000 € Ausrüstung, davon
// 600 € Senioren und 400 € A-Junioren"). Die Funktion ist standardmäßig aus;
// ausgeschaltet darf nichts von ihr zu sehen sein, und eine Bearbeitung darf
// gespeicherte Zuordnungen nicht verlieren.

const EXPENSE_ACCOUNT = '5200' // Veranstaltungskosten im Standard-Kontenrahmen
const YEAR = '2031'
const TRIKOTS = 'Trikots Saison'

/** Schalter über die Einstellungs-API, wie es die Einstellungsseite tut. */
async function setModule(request, enabled) {
	await api.updateSettings(request, { cost_centers_enabled: enabled ? '1' : '0' })
}

/** Legt SEN und AJU an, falls sie fehlen; verlangt das eingeschaltete Modul. */
async function ensureCostCenters(request) {
	const existing = await api.getJson(request, '/booking-cost-centers')
	const out = {}
	for (const [code, name] of [['SEN', 'Senioren'], ['AJU', 'A-Junioren']]) {
		const found = existing.find((cc) => cc.code === code)
		out[code] = found ?? await (await api.raw(request, 'POST', '/booking-cost-centers', { data: { code, name }, expectOk: true })).json()
	}
	return out
}

/** Die Zeile der Trikot-Buchung auf dem Aufwandskonto, mit ihrer Verteilung in Cent. */
async function trikotAllocations(request, description = TRIKOTS) {
	const expense = await api.accountByNumber(request, EXPENSE_ACCOUNT)
	const entry = (await api.listJournal(request)).find((e) => e.journal.description === description)
	if (!entry) { return null }
	const line = entry.lines.find((l) => l.accountId === expense.id)
	return { entry, line, cents: (line.allocations ?? []).map((a) => [a.costCenterId, Math.round(a.amount * 100)]) }
}

/** Die Trikot-Buchung per API, falls ein früherer Test sie nicht (mehr) angelegt hat. */
async function ensureTrikotBooking(request) {
	const cc = await ensureCostCenters(request)
	if (!await trikotAllocations(request)) {
		const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)
		await api.raw(request, 'POST', '/journal', {
			expectOk: true,
			data: {
				date: `${YEAR}-05-10`,
				description: TRIKOTS,
				lines: [
					{ accountId: expense.id, debit: 1000, credit: 0, allocations: [{ costCenterId: cc.SEN.id, amount: 600 }, { costCenterId: cc.AJU.id, amount: 400 }] },
					{ accountId: bank.id, debit: 0, credit: 1000 },
				],
			},
		})
	}
	return cc
}

test.describe('Kostenstellen an der Buchungszeile', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await setModule(request, false)
	})

	test.afterAll(async ({ request }) => {
		// Der Schalter ist eine Einstellung und überlebt resetBook(): andere
		// Specs erwarten die Vorgabe "aus".
		await setModule(request, false)
	})

	test('ausgeschaltet ist von der Funktion nichts zu sehen', async ({ page, request }) => {
		const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)
		await api.createBooking(request, { date: `${YEAR}-02-01`, description: 'Hallenmiete', debitAccountId: expense.id, creditAccountId: bank.id, amount: 80 })

		// Die Journal-Antwort trägt kein neues Feld.
		const entry = (await api.listJournal(request)).find((e) => e.journal.description === 'Hallenmiete')
		for (const line of entry.lines) {
			expect(line).not.toHaveProperty('allocations')
		}
		// Der Bericht existiert nicht.
		expect((await api.raw(request, 'GET', '/report/cost-center-allocations')).status()).toBe(404)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await expect(visibleSection(page).getByRole('button', { name: 'Auswertungsgruppen', exact: true })).toBeVisible()
		await expect(visibleSection(page).getByRole('button', { name: 'Kostenstellen', exact: true })).toHaveCount(0)

		await neuAnlegen(page)
		const dialog = page.getByRole('dialog')
		await dialog.getByRole('button', { name: 'Ausgabe' }).click()
		await skipBookingTour(dialog)
		await dialog.getByLabel('Betrag (€)', { exact: true }).fill('10')
		await pickNcSelectOption(dialog, '– Kategorie wählen –', 'Veranstaltungskosten')
		await expect(dialog.locator('.vbh-allocblock')).toHaveCount(0)
	})

	test('Kostenstellen im Verwalten-Dialog anlegen', async ({ page, request }) => {
		await setModule(request, true)
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Kostenstellen', exact: true }).click()
		await visibleSection(page).getByRole('button', { name: 'Kostenstellen verwalten', exact: true }).click()

		const dialog = page.getByRole('dialog')
		for (const [code, name] of [['SEN', 'Senioren'], ['AJU', 'A-Junioren']]) {
			// exact: 'z.B. SEN' ist sonst auch Anfang von 'z.B. Senioren'.
			await dialog.getByPlaceholder('z.B. SEN', { exact: true }).fill(code)
			await dialog.getByPlaceholder('z.B. Senioren', { exact: true }).fill(name)
			await dialog.getByRole('button', { name: 'Anlegen', exact: true }).click()
			await expect(dialog.getByLabel(`Kostenstelle ${code} aktiv`)).toBeChecked()
		}

		const list = await api.getJson(request, '/booking-cost-centers')
		expect(list.map((cc) => cc.code).sort()).toEqual(['AJU', 'SEN'])
	})

	test('Trikot-Beispiel: 1.000 auf ein Konto, 600 und 400 auf zwei Kostenstellen', async ({ page, request }) => {
		await setModule(request, true)
		const cc = await ensureCostCenters(request)

		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const dialog = page.getByRole('dialog')
		await dialog.getByRole('button', { name: 'Ausgabe' }).click()
		await skipBookingTour(dialog)
		await dialog.getByLabel('Betrag (€)', { exact: true }).fill('1000')
		await pickNcSelectOption(dialog, '– Kategorie wählen –', 'Veranstaltungskosten')
		await dialog.locator('input[type="date"]').fill(`${YEAR}-05-10`)
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill(TRIKOTS)

		// Das Feld erscheint mit der Kategorie; leer lassen wäre der Normalfall.
		const block = dialog.locator('.vbh-allocblock')
		await block.getByLabel('Kostenstelle', { exact: true }).selectOption({ label: 'SEN · Senioren' })
		await block.getByRole('button', { name: 'Auf mehrere verteilen…' }).click()
		await block.getByLabel('Betrag für Kostenstelle 1').fill('600')
		await block.getByLabel('Kostenstelle 2', { exact: true }).selectOption({ label: 'AJU · A-Junioren' })
		await block.getByLabel('Betrag für Kostenstelle 2').fill('400')
		await block.getByLabel('Betrag für Kostenstelle 2').blur()
		await expect(block.getByRole('status')).toHaveText('✓ vollständig verteilt')

		await dialog.getByRole('button', { name: 'Buchen', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		// Eine Buchung, eine Zeile auf dem Aufwandskonto, zwei Kostenstellen.
		await expect.poll(async () => (await trikotAllocations(request))?.cents).toEqual([[cc.SEN.id, 60000], [cc.AJU.id, 40000]])
		const { entry } = await trikotAllocations(request)
		expect(entry.lines).toHaveLength(2)

		// In der Liste trägt die Buchung das Etikett.
		await switchTab(page, 'Buchungen')
		await selectPeriod(page, YEAR)
		const row = visibleSection(page).locator('tr', { hasText: TRIKOTS }).first()
		await expect(row.locator('.vbh-allocbadge')).toHaveText('SEN +1', { timeout: 15000 })
	})

	test('Bericht zeigt beide Kostenstellen und stimmt mit den Kontensalden überein', async ({ page, request }) => {
		await setModule(request, true)
		await ensureTrikotBooking(request)
		// Eine Buchung ohne Kostenstelle, damit es einen Rest gibt.
		if (!(await api.listJournal(request)).some((e) => e.journal.description === 'Schiedsrichter')) {
			const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)
			await api.createBooking(request, { date: `${YEAR}-03-01`, description: 'Schiedsrichter', debitAccountId: expense.id, creditAccountId: bank.id, amount: 45 })
		}

		const report = await api.getJson(request, '/report/cost-center-allocations')
		const byCode = Object.fromEntries(report.centers.map((c) => [c.code, c]))
		expect(byCode.SEN.expenseCents).toBe(60000)
		expect(byCode.AJU.expenseCents).toBe(40000)
		expect(byCode.SEN.expense[0].number).toBe(EXPENSE_ACCOUNT)
		expect(report.reconciled).toBe(true)
		expect(report.unassigned.expense.find((r) => r.number === EXPENSE_ACCOUNT)?.amountCents).toBeGreaterThanOrEqual(4500)
		// Die Teile ergeben das Ganze: Kostenstellen plus Rest gleich Gesamtsumme.
		const parts = [...report.centers, report.unassigned].reduce((sum, block) => sum + block.resultCents, 0)
		expect(parts).toBe(report.total.resultCents)
		expect(report.total.resultCents).toBe(report.ledgerResultCents)

		await openApp(page, USERS.verwalter)
		await selectPeriod(page, YEAR)
		await switchTab(page, 'Berichte')
		await visibleSection(page).getByRole('button', { name: 'Kostenstellen', exact: true }).click()
		const section = visibleSection(page)
		await expect(section.getByRole('heading', { name: 'SEN – Senioren' })).toBeVisible()
		await expect(section.getByRole('heading', { name: 'AJU – A-Junioren' })).toBeVisible()
		await expect(section.getByRole('heading', { name: 'Ohne Kostenstelle' })).toBeVisible()
		await expect(section.getByText('✓ Das Ergebnis entspricht dem Ergebnis laut Kontensalden.')).toBeVisible()

		// Druckseite und CSV liefern dieselben Zahlen.
		const print = await api.raw(request, 'GET', '/export/cost-center-allocations')
		expect(print.status()).toBe(200)
		expect(await print.text()).toContain('Senioren')
		const csv = await (await api.raw(request, 'GET', '/export/cost-center-allocations-csv')).text()
		expect(csv).toContain('"SEN Senioren";"Ausgabe";"5200";"Veranstaltungskosten";"600,00"')
		const journalCsv = await (await api.raw(request, 'GET', '/export/journal')).text()
		expect(journalCsv.split(/\r?\n/)[0]).toContain('"Kostenstellen"')
		expect(journalCsv).toContain('"SEN 600,00 | AJU 400,00"')
	})

	test('ausschalten, bearbeiten, einschalten: die Verteilung ist noch da', async ({ page, request }) => {
		await setModule(request, true)
		const cc = await ensureTrikotBooking(request)
		await setModule(request, false)

		// Ausgeschaltet: Journal und CSV wie vor der Funktion.
		const hidden = await trikotAllocations(request)
		expect(hidden.line).not.toHaveProperty('allocations')
		const journalCsv = await (await api.raw(request, 'GET', '/export/journal')).text()
		expect(journalCsv.split(/\r?\n/)[0]).not.toContain('Kostenstellen')

		// Über die Oberfläche bearbeiten – der Dialog kennt die Kostenstellen jetzt nicht.
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await selectPeriod(page, YEAR)
		const row = visibleSection(page).locator('tr', { hasText: TRIKOTS }).first()
		await expect(row.locator('.vbh-allocbadge')).toHaveCount(0)
		await row.getByRole('button', { name: 'Bearbeiten', exact: true }).click()
		const dialog = page.getByRole('dialog')
		await expect(dialog.locator('.vbh-allocblock')).toHaveCount(0)
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill(TRIKOTS + ' 2031')
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		await setModule(request, true)
		const after = await trikotAllocations(request, TRIKOTS + ' 2031')
		expect(after.cents).toEqual([[cc.SEN.id, 60000], [cc.AJU.id, 40000]])

		// Für die folgenden Tests zurück zum alten Text.
		await api.raw(request, 'PUT', `/journal/${after.entry.journal.id}`, {
			expectOk: true,
			data: {
				date: after.entry.journal.date,
				description: TRIKOTS,
				updatedAt: after.entry.journal.updatedAt,
				lines: after.entry.lines.map((l) => ({ accountId: l.accountId, debit: l.debit, credit: l.credit })),
			},
		})
		expect((await trikotAllocations(request)).cents).toEqual([[cc.SEN.id, 60000], [cc.AJU.id, 40000]])
	})

	test('verwendete Kostenstelle: deaktivieren ja, löschen und neu zuordnen nein', async ({ request }) => {
		await setModule(request, true)
		const cc = await ensureTrikotBooking(request)
		const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)

		const del = await api.raw(request, 'DELETE', `/booking-cost-centers/${cc.SEN.id}`)
		expect(del.status()).toBe(400)

		await api.raw(request, 'PUT', `/booking-cost-centers/${cc.AJU.id}`, { expectOk: true, data: { code: 'AJU', name: 'A-Junioren', active: false } })
		try {
			// Die bestehende Zuordnung bleibt beim erneuten Speichern gültig …
			const { entry } = await trikotAllocations(request)
			const resave = await api.raw(request, 'PUT', `/journal/${entry.journal.id}`, {
				data: {
					date: entry.journal.date,
					description: TRIKOTS,
					updatedAt: entry.journal.updatedAt,
					lines: entry.lines.map((l) => ({ accountId: l.accountId, debit: l.debit, credit: l.credit, allocations: l.allocations })),
				},
			})
			expect(resave.status()).toBe(200)
			// … eine neue Zuordnung zur inaktiven Kostenstelle nicht.
			const fresh = await api.raw(request, 'POST', '/journal', {
				data: {
					date: `${YEAR}-06-01`,
					description: 'Bälle',
					lines: [
						{ accountId: expense.id, debit: 50, credit: 0, allocations: [{ costCenterId: cc.AJU.id, amount: 50 }] },
						{ accountId: bank.id, debit: 0, credit: 50 },
					],
				},
			})
			expect(fresh.status()).toBe(400)
			// Mehr verteilen, als die Zeile hergibt, geht auch nicht.
			const over = await api.raw(request, 'POST', '/journal', {
				data: {
					date: `${YEAR}-06-01`,
					description: 'Bälle',
					lines: [
						{ accountId: expense.id, debit: 50, credit: 0, allocations: [{ costCenterId: cc.SEN.id, amount: 50.01 }] },
						{ accountId: bank.id, debit: 0, credit: 50 },
					],
				},
			})
			expect(over.status()).toBe(400)
		} finally {
			await api.raw(request, 'PUT', `/booking-cost-centers/${cc.AJU.id}`, { data: { code: 'AJU', name: 'A-Junioren', active: true } })
		}
	})
})
