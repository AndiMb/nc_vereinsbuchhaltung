import { expect, test } from '@playwright/test'
import { api, CAMT_STATEMENT, fixture, INCOME_ACCOUNT, openApp, pickNcSelectOption, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Auto-Zuordnungsregeln: eine Regel auf den Verwendungszweck ordnet
// wiederkehrende Umsätze schon beim Import zu.

test.describe('Zuordnungsregeln', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('Regel greift beim Import und ordnet automatisch zu', async ({ page, request }) => {
		const income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.createRule(request, {
			matchField: 'purpose',
			matchValue: 'Mitgliedsbeitrag',
			contraAccountId: income.id,
		})

		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.new).toBe(CAMT_STATEMENT.txCount)
		expect(result.autoAssigned).toBe(CAMT_STATEMENT.memberFeeCount)

		// Die automatisch zugeordneten Umsätze stehen als Buchungen im Journal.
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await expect(visibleSection(page).getByText('Mitgliedsbeitrag 2026 Max Mustermann').first()).toBeVisible()
	})

	test('Regel ist im Regel-Bereich sichtbar', async ({ page, request }) => {
		// Eigene Vorbedingung statt auf den vorigen Test zu vertrauen: Playwright
		// startet den Worker nach jedem fehlgeschlagenen Test neu und laesst
		// beforeAll() erneut laufen (siehe tests/e2e/README.md) - ein Reset mitten
		// in der Datei wuerde sonst die vom vorigen Test angelegte Regel wieder
		// wegraeumen.
		const income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.createRule(request, {
			matchField: 'purpose',
			matchValue: 'Mitgliedsbeitrag',
			contraAccountId: income.id,
		})

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Regeln' }).click()
		await expect(visibleSection(page).getByText('Mitgliedsbeitrag').first()).toBeVisible()
	})
})

// Feste Anteile (Issue #163): eine Regel verteilt den Umsatz auf mehrere
// Konten, der Rest geht aufs Gegenkonto. Jeder Test baut seinen Bestand
// selbst auf - bei einem Retry laeuft nur der fehlgeschlagene Test erneut.
test.describe('Zuordnungsregeln mit festen Anteilen', () => {
	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	async function splitRule(request, amounts) {
		const [rest, a, b] = await api.accountsByNumber(request, INCOME_ACCOUNT, '4100', '4200')
		await api.createRule(request, {
			matchField: 'purpose',
			matchValue: 'Mitgliedsbeitrag',
			contraAccountId: rest.id,
			parts: [{ accountId: a.id, amount: amounts[0] }, { accountId: b.id, amount: amounts[1] }],
		})
		return { rest, a, b }
	}

	test('Aufteilungsregel verteilt Mitgliedsbeiträge beim Import', async ({ page, request }) => {
		const { rest, a, b } = await splitRule(request, [10, 5])

		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.autoAssigned).toBe(CAMT_STATEMENT.memberFeeCount)
		expect(result.ruleFailed).toBe(0)

		const entry = (await api.listJournal(request)).find((e) => e.journal.description.includes('Max Mustermann'))
		expect(entry).toBeTruthy()
		const credit = (id) => entry.lines.filter((l) => l.accountId === id).reduce((sum, l) => sum + l.creditCents, 0)
		expect(credit(a.id)).toBe(1000)
		expect(credit(b.id)).toBe(500)
		expect(credit(rest.id)).toBe(4500)

		// Die Regeltabelle zeigt die Aufteilung statt nur des Gegenkontos.
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Regeln' }).click()
		await expect(visibleSection(page).getByText('Rest → 4000').first()).toBeVisible()
	})

	test('Zu kleiner Umsatz bleibt unzugeordnet', async ({ request }) => {
		await splitRule(request, [50, 20])

		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.autoAssigned).toBe(0)
		expect(result.ruleFailed).toBe(CAMT_STATEMENT.memberFeeCount)
	})

	test('Regel mit Anteil im Formular anlegen; der Vorschlag öffnet die vorbelegte Aufteilung', async ({ page, request }) => {
		// Erst importieren, dann die Regel anlegen: so bleiben die Umsätze offen
		// und die Regel wirkt nur als Vorschlag.
		await api.importStatement(request, fixture(CAMT_STATEMENT.csv))

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Regeln' }).click()
		const card = visibleSection(page).locator('.vbh-card').first()
		await card.locator('select').first().selectOption('purpose')
		await card.getByPlaceholder('z. B. Stadtwerke').fill('Mitgliedsbeitrag')
		await pickNcSelectOption(card.locator('.vbh-form').first(), '– Konto wählen –', INCOME_ACCOUNT)
		await card.getByRole('button', { name: '+ Anteil hinzufügen' }).click()
		await expect(card.locator('label', { hasText: 'Restkonto' })).toBeVisible()
		// Ein neuer Anteil hat noch kein Konto (nicht etwa die erste Gruppenüberschrift).
		await expect(card.locator('.vbh-rule-parts').getByPlaceholder('– Konto wählen –')).toBeVisible()
		await pickNcSelectOption(card.locator('.vbh-rule-parts'), '– Konto wählen –', '4100')
		await card.getByLabel('Betrag Anteil 1').fill('10')
		await card.getByRole('button', { name: 'Hinzufügen', exact: true }).click()
		await expect(visibleSection(page).getByText('Rest → 4000').first()).toBeVisible()

		await visibleSection(page).getByRole('button', { name: 'Zuzuordnen' }).click()
		await visibleSection(page).getByRole('button', { name: /Vorschlag: Aufteilung: 4100 · 4000/ }).first().click()
		const dialog = page.getByRole('dialog', { name: 'Umsatz aufteilen' })
		await expect(dialog.getByText('✓ geht auf')).toBeVisible()
		await dialog.getByRole('button', { name: 'Zuordnen' }).click()
		await expect(dialog).toBeHidden()

		const entry = (await api.listJournal(request)).find((e) => e.journal.description.includes('Mitgliedsbeitrag'))
		expect(entry.lines.map((l) => l.creditCents).filter((c) => c > 0).sort((x, y) => x - y)).toEqual([1000, 5000])
	})

	test('Konto eines Anteils löschen entfernt die Regel', async ({ request }) => {
		const [rest, a] = await api.accountsByNumber(request, INCOME_ACCOUNT, '4100')
		const extra = await api.createAccount(request, { number: '4950', name: 'Abteilung Test', type: 'income' })
		await api.createRule(request, {
			matchField: 'purpose',
			matchValue: 'Mitgliedsbeitrag',
			contraAccountId: rest.id,
			parts: [{ accountId: a.id, amount: 10 }, { accountId: extra.id, amount: 5 }],
		})
		expect(await api.listRules(request)).toHaveLength(1)

		await api.deleteAccount(request, extra.id)

		expect(await api.listRules(request)).toEqual([])
	})
})
