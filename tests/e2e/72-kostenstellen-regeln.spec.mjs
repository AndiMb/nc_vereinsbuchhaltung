import { expect, test } from '@playwright/test'
import { api, CAMT_STATEMENT, fixture, INCOME_ACCOUNT, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Kostenstelle an einer Importregel (Issue #331): greift die Regel beim
// Import, gehört der ganze Betrag ihrer Kostenstelle. Lässt sich die Vorgabe
// nicht anwenden (Kostenstelle inaktiv, Modul aus), wird der Umsatz trotzdem
// zugeordnet – nur ohne Kostenstelle.

async function setModule(request, enabled) {
	await api.updateSettings(request, { cost_centers_enabled: enabled ? '1' : '0' })
}

/**
 * Frischer Bestand mit eingeschaltetem Modul, einer Kostenstelle und einer
 * Regel „Verwendungszweck enthält Mitgliedsbeitrag“ mit dieser Kostenstelle.
 * Jeder Test baut sich das selbst: ein Auszug lässt sich nur einmal importieren.
 */
async function setup(request) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	await setModule(request, true)
	const income = await api.accountByNumber(request, INCOME_ACCOUNT)
	const costCenter = await (await api.raw(request, 'POST', '/booking-cost-centers', { expectOk: true, data: { code: 'JUG', name: 'Jugend' } })).json()
	const rule = await (await api.raw(request, 'POST', '/rules', {
		expectOk: true,
		data: { matchField: 'purpose', matchValue: 'Mitgliedsbeitrag', contraAccountId: income.id, costCenterId: costCenter.id },
	})).json()
	return { income, costCenter, rule }
}

/** Die Zeilen auf dem Ertragskonto aller per Regel entstandenen Buchungen. */
async function memberFeeLines(request, incomeId) {
	return (await api.listJournal(request))
		.filter((e) => e.journal.description.includes('Mitgliedsbeitrag'))
		.map((e) => e.lines.find((l) => l.accountId === incomeId))
}

test.describe('Kostenstelle an Importregeln', () => {
	test.afterAll(async ({ request }) => {
		await setModule(request, false)
	})

	test('Regel mit Kostenstelle ordnet beim Import den ganzen Betrag zu', async ({ page, request }) => {
		const { income, costCenter, rule } = await setup(request)
		expect(rule.costCenterId).toBe(costCenter.id)

		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.autoAssigned).toBe(CAMT_STATEMENT.memberFeeCount)

		const lines = await memberFeeLines(request, income.id)
		expect(lines).toHaveLength(CAMT_STATEMENT.memberFeeCount)
		for (const line of lines) {
			expect(line.allocations).toEqual([{ costCenterId: costCenter.id, amount: line.credit }])
		}

		// Im Regel-Bereich steht die Kostenstelle der Regel, im Formular das Feld.
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Regeln' }).click()
		const row = visibleSection(page).locator('tr', { hasText: 'Mitgliedsbeitrag' }).first()
		await expect(row.getByRole('cell', { name: 'JUG', exact: true })).toBeVisible()
		await row.getByRole('button', { name: 'Regel bearbeiten' }).click()
		// Das Auswahlfeld steckt im <label>; dessen Text enthält auch die Optionen.
		await expect(visibleSection(page).locator('label', { hasText: 'Kostenstelle' }).locator('select')).toHaveValue(String(costCenter.id))
	})

	test('Vorschlag einer Regel übernehmen nimmt die Kostenstelle mit', async ({ page, request }) => {
		const { income, costCenter } = await setup(request)
		// Ohne Regeln importieren: die Umsätze bleiben offen und bekommen den Vorschlag der Regel.
		const result = await (await api.raw(request, 'POST', '/import/commit', {
			expectOk: true,
			data: { content: fixture(CAMT_STATEMENT.csv), filename: 'auszug.csv', applyRules: false },
		})).json()
		expect(result.autoAssigned).toBe(0)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: /Zuzuordnen/ }).click()
		await visibleSection(page).getByRole('button', { name: /Vorschlag/ }).first().click()

		await expect.poll(async () => (await memberFeeLines(request, income.id)).length).toBe(1)
		const [line] = await memberFeeLines(request, income.id)
		expect(line.allocations).toEqual([{ costCenterId: costCenter.id, amount: line.credit }])
	})

	test('deaktivierte Kostenstelle: der Import ordnet trotzdem zu, ohne Kostenstelle', async ({ request }) => {
		const { income, costCenter } = await setup(request)
		await api.raw(request, 'PUT', `/booking-cost-centers/${costCenter.id}`, { expectOk: true, data: { code: 'JUG', name: 'Jugend', active: false } })

		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.autoAssigned).toBe(CAMT_STATEMENT.memberFeeCount)

		for (const line of await memberFeeLines(request, income.id)) {
			expect(line.allocations).toEqual([])
		}
	})

	test('ausgeschaltet: Import ohne Kostenstelle, die Vorgabe der Regel bleibt gespeichert', async ({ page, request }) => {
		const { income, costCenter, rule } = await setup(request)
		await setModule(request, false)

		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.autoAssigned).toBe(CAMT_STATEMENT.memberFeeCount)

		// Das Formular kennt das Feld jetzt nicht …
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Regeln' }).click()
		await expect(visibleSection(page).getByText('Mitgliedsbeitrag').first()).toBeVisible()
		await expect(visibleSection(page).locator('label', { hasText: 'Kostenstelle' })).toHaveCount(0)
		// … und das Speichern über das Formular schickt die Angabe nicht mit:
		// die Vorgabe der Regel bleibt stehen.
		const row = visibleSection(page).locator('tr', { hasText: 'Mitgliedsbeitrag' }).first()
		await row.getByRole('button', { name: 'Regel bearbeiten' }).click()
		await visibleSection(page).locator('label', { hasText: 'Priorität' }).locator('input').fill('1')
		const saved = page.waitForRequest((r) => r.method() === 'PUT' && r.url().includes(`/api/rules/${rule.id}`))
		await visibleSection(page).getByRole('button', { name: 'Speichern', exact: true }).click()
		expect((await saved).postDataJSON()).not.toHaveProperty('costCenterId')
		await expect(page.getByRole('status').filter({ hasText: 'Regel gespeichert.' }).first()).toBeVisible()
		const stored = (await api.getJson(request, '/rules')).find((r) => r.id === rule.id)
		expect(stored.costCenterId).toBe(costCenter.id)
		expect(stored.priority).toBe(1)

		await setModule(request, true)
		for (const line of await memberFeeLines(request, income.id)) {
			expect(line.allocations).toEqual([])
		}
	})

	test('ausgeschaltet: Vorschlag übernehmen schickt keine Kostenstelle mit', async ({ page, request }) => {
		const { income } = await setup(request)
		await setModule(request, false)
		await api.raw(request, 'POST', '/import/commit', {
			expectOk: true,
			data: { content: fixture(CAMT_STATEMENT.csv), filename: 'auszug.csv', applyRules: false },
		})

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: /Zuzuordnen/ }).click()
		const assigned = page.waitForRequest((r) => r.method() === 'POST' && /\/api\/transactions\/\d+\/assign$/.test(r.url()))
		await visibleSection(page).getByRole('button', { name: /Vorschlag/ }).first().click()
		// Genau der Request von vor der Funktion: nur das Gegenkonto.
		expect((await assigned).postDataJSON()).toEqual({ contraAccountId: income.id })
	})

	test('eine Kostenstelle, auf die eine Regel zeigt, lässt sich nicht löschen', async ({ request }) => {
		const { income, costCenter, rule } = await setup(request)

		const blocked = await api.raw(request, 'DELETE', `/booking-cost-centers/${costCenter.id}`)
		expect(blocked.status()).toBe(400)
		expect((await blocked.json()).message).toContain('Importregeln')

		// Ohne die Vorgabe an der Regel geht es.
		await api.raw(request, 'PUT', `/rules/${rule.id}`, {
			expectOk: true,
			data: { matchField: 'purpose', matchValue: 'Mitgliedsbeitrag', contraAccountId: income.id, costCenterId: null },
		})
		expect((await api.raw(request, 'DELETE', `/booking-cost-centers/${costCenter.id}`)).status()).toBe(200)
	})
})
