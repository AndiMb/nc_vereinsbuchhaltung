import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, openApp, selectPeriod, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Budget je Kostenstelle (Issue #335): je Kostenstelle und Geschäftsjahr ein
// Planwert für die Einnahmen und einer für die Ausgaben. Der
// Kostenstellen-Bericht stellt Plan, Ist und Abweichung nebeneinander.

const EXPENSE_ACCOUNT = '5200' // Veranstaltungskosten im Standard-Kontenrahmen
const TODAY = new Date().toISOString().slice(0, 10)

async function setModule(request, enabled) {
	await api.updateSettings(request, { cost_centers_enabled: enabled ? '1' : '0' })
}

/**
 * Frischer Bestand, Modul ein, Kostenstellen SEN und AJU, eine Ausgabe von
 * 195,00 im laufenden Geschäftsjahr, ganz bei SEN.
 */
async function setup(request) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	await setModule(request, true)
	const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, EXPENSE_ACCOUNT)
	const post = async (path, data) => (await api.raw(request, 'POST', path, { expectOk: true, data })).json()
	const sen = await post('/booking-cost-centers', { code: 'SEN', name: 'Senioren' })
	const aju = await post('/booking-cost-centers', { code: 'AJU', name: 'A-Junioren' })
	await post('/journal', {
		date: TODAY,
		description: 'Trikots Senioren',
		lines: [
			{ accountId: expense.id, debit: 195, credit: 0, allocations: [{ costCenterId: sen.id, amount: 195 }] },
			{ accountId: bank.id, debit: 0, credit: 195 },
		],
	})
	const period = await api.periodIdForDate(request, TODAY)
	return { sen, aju, period }
}

const setBudget = (request, data, options = {}) => api.raw(request, 'PUT', '/cost-center-budgets', { data, ...options })

async function openReport(page, user = USERS.verwalter) {
	await openApp(page, user)
	await switchTab(page, 'Berichte')
	await visibleSection(page).getByRole('button', { name: 'Kostenstellen', exact: true }).click()
}

test.describe('Budget je Kostenstelle', () => {
	test.afterAll(async ({ request }) => {
		await setModule(request, false)
	})

	test('Dialog setzt Planwerte; der Bericht zeigt Plan, Ist und Abweichung – auch ohne Buchungen', async ({ page, request }) => {
		const ctx = await setup(request)
		await openReport(page)
		const section = visibleSection(page)
		// Ohne Plan sieht der Bericht aus wie bisher.
		await expect(section.locator('.vbh-ccreport-plan')).toHaveCount(0)

		await section.getByRole('button', { name: 'Budget planen', exact: true }).click()
		const dialog = page.getByRole('dialog', { name: /^Budget planen/ })
		const save = () => page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes('/api/cost-center-budgets') && r.ok())
		for (const [label, value] of [['Plan Ausgaben SEN', '180'], ['Plan Einnahmen SEN', '250'], ['Plan Ausgaben AJU', '90']]) {
			const saved = save()
			const field = dialog.getByLabel(label, { exact: true })
			await field.fill(value)
			await field.blur()
			await saved
		}
		await dialog.getByRole('button', { name: 'Fertig', exact: true }).click()
		await expect(dialog).toBeHidden()

		expect(await api.getJson(request, `/cost-center-budgets?period=${ctx.period}`)).toEqual(expect.arrayContaining([
			{ costCenterId: ctx.sen.id, incomeCents: 25000, expenseCents: 18000 },
			{ costCenterId: ctx.aju.id, incomeCents: 0, expenseCents: 9000 },
		]))

		// SEN: Plan 180,00, Ist 195,00, Abweichung 15,00 bei den Ausgaben.
		const sen = section.locator('.vbh-ccreport-block', { hasText: 'SEN – Senioren' })
		const senExpense = sen.locator('.vbh-ccreport-plan tr', { hasText: 'Ausgaben' })
		await expect(senExpense).toContainText('180,00')
		await expect(senExpense).toContainText('195,00')
		await expect(senExpense).toContainText('15,00')
		// Ergebnis: Plan 70,00, Ist -195,00, Abweichung -265,00.
		await expect(sen.locator('.vbh-ccreport-plan tr', { hasText: 'Ergebnis' })).toContainText('-265,00')

		// AJU hat einen Plan, aber keine Buchung – und erscheint trotzdem.
		const aju = section.locator('.vbh-ccreport-block', { hasText: 'AJU – A-Junioren' })
		await expect(aju.locator('.vbh-ccreport-plan tr', { hasText: 'Ausgaben' })).toContainText('90,00')

		// Die Ist-Summen und die Abstimmung bleiben unberührt.
		const report = await api.getJson(request, `/report/cost-center-allocations?period=${ctx.period}`)
		expect(report.planned).toBe(true)
		expect(report.total.expenseCents).toBe(19500)
		expect(report.reconciled).toBe(true)
	})

	test('„Alle Zeiträume“: kein Plan und kein Knopf', async ({ page, request }) => {
		const ctx = await setup(request)
		await setBudget(request, { period: ctx.period, costCenterId: ctx.sen.id, income: 0, expense: 180 }, { expectOk: true })
		await openReport(page)
		const section = visibleSection(page)
		await expect(section.locator('.vbh-ccreport-plan')).toHaveCount(1)

		await selectPeriod(page, 'Alle Zeiträume')
		await expect(section.locator('.vbh-ccreport-block', { hasText: 'SEN – Senioren' })).toBeVisible()
		await expect(section.locator('.vbh-ccreport-plan')).toHaveCount(0)
		await expect(section.getByRole('button', { name: 'Budget planen', exact: true })).toHaveCount(0)
		expect((await api.getJson(request, '/report/cost-center-allocations')).planned).toBe(false)
	})

	test('Druckseite und CSV nennen die Planwerte', async ({ request }) => {
		const ctx = await setup(request)
		await setBudget(request, { period: ctx.period, costCenterId: ctx.sen.id, income: 250, expense: 180 }, { expectOk: true })

		const html = await (await api.raw(request, 'GET', `/export/cost-center-allocations?period=${ctx.period}`, { expectOk: true })).text()
		for (const needle of ['Plan', 'Abweichung', '250,00', '180,00', '195,00']) {
			expect(html).toContain(needle)
		}
		const csv = await (await api.raw(request, 'GET', `/export/cost-center-allocations-csv?period=${ctx.period}`, { expectOk: true })).text()
		expect(csv).toMatch(/"SEN Senioren";"Plan Einnahmen";.*250,00/)
		expect(csv).toMatch(/"SEN Senioren";"Plan Ausgaben";.*180,00/)
	})

	test('Regeln der Schnittstelle: Rollen, ungültige Werte, 0 löscht, Löschen der Kostenstelle räumt auf', async ({ request }) => {
		const ctx = await setup(request)
		const data = { period: ctx.period, costCenterId: ctx.aju.id, income: 100, expense: 50 }

		// Lesen ab Revisor, schreiben ab Buchhalter.
		expect((await setBudget(request, data, { user: USERS.revisor })).status()).toBe(403)
		expect((await setBudget(request, data, { user: USERS.buchhalter })).status()).toBe(200)
		expect((await api.raw(request, 'GET', `/cost-center-budgets?period=${ctx.period}`, { user: USERS.revisor })).status()).toBe(200)

		expect((await setBudget(request, { ...data, expense: -1 })).status()).toBe(400)
		expect((await setBudget(request, { ...data, costCenterId: 999999 })).status()).toBe(404)
		expect((await setBudget(request, { ...data, period: 999999 })).status()).toBe(404)

		// Beide Werte 0: kein Plan mehr.
		await setBudget(request, { ...data, income: 0, expense: 0 }, { expectOk: true })
		expect(await api.getJson(request, `/cost-center-budgets?period=${ctx.period}`)).toEqual([])

		// Ein Plan hält die Kostenstelle nicht fest; mit ihr gehen ihre Planwerte.
		await setBudget(request, data, { expectOk: true })
		await api.raw(request, 'DELETE', `/booking-cost-centers/${ctx.aju.id}`, { expectOk: true })
		expect(await api.getJson(request, `/cost-center-budgets?period=${ctx.period}`)).toEqual([])
	})

	test('ausgeschaltet: kein Knopf, Schreiben abgelehnt; nach dem Einschalten ist der Plan noch da', async ({ page, request }) => {
		const ctx = await setup(request)
		const data = { period: ctx.period, costCenterId: ctx.sen.id, income: 250, expense: 180 }
		await setBudget(request, data, { expectOk: true })
		await setModule(request, false)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Berichte')
		await expect(visibleSection(page).getByRole('button', { name: 'Budget planen', exact: true })).toHaveCount(0)
		await expect(visibleSection(page).getByRole('button', { name: 'Kostenstellen', exact: true })).toHaveCount(0)
		const rejected = await setBudget(request, { ...data, expense: 999 })
		expect(rejected.status()).toBe(400)
		expect((await rejected.json()).message).toContain('ausgeschaltet')

		await setModule(request, true)
		expect(await api.getJson(request, `/cost-center-budgets?period=${ctx.period}`)).toEqual([{ costCenterId: ctx.sen.id, incomeCents: 25000, expenseCents: 18000 }])
	})
})
