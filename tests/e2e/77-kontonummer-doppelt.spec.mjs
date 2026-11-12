import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, INCOME_ACCOUNT, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Eine Kontonummer gibt es je Bestand nur einmal (Issue #327). Früher kam der
// Verstoß gegen den eindeutigen Index als HTTP 500 an; jetzt als lesbare
// Meldung, beim Anlegen wie beim Ändern.

const MESSAGE = `Die Kontonummer ${BANK_ACCOUNT} ist bereits vergeben.`

test.describe('Kontonummer doppelt vergeben', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('Schnittstelle: Anlegen und Ändern auf eine vergebene Nummer antworten mit 400 und Meldung', async ({ request }) => {
		const created = await api.raw(request, 'POST', '/accounts', { data: { number: BANK_ACCOUNT, name: 'Doppelt', type: 'asset' } })
		expect(created.status()).toBe(400)
		expect((await created.json()).message).toBe(MESSAGE)

		const income = await api.accountByNumber(request, INCOME_ACCOUNT)
		const changed = await api.raw(request, 'PUT', `/accounts/${income.id}`, { data: { number: BANK_ACCOUNT, parentId: 0 } })
		expect(changed.status()).toBe(400)
		expect((await changed.json()).message).toBe(MESSAGE)

		// Die eigene Nummer mitzuschicken ist kein Konflikt - das Formular tut es immer.
		const same = await api.raw(request, 'PUT', `/accounts/${income.id}`, { data: { number: INCOME_ACCOUNT, name: income.name, parentId: 0 } })
		expect(same.status()).toBe(200)
		expect((await api.accountByNumber(request, INCOME_ACCOUNT)).id).toBe(income.id)
	})

	test('Oberfläche: der Dialog „Neues Konto“ zeigt die Meldung und bleibt offen', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await visibleSection(page).getByRole('button', { name: 'Konto', exact: true }).click()
		const dialog = page.getByRole('dialog', { name: 'Neues Konto' })
		await dialog.getByLabel('Nummer').fill(BANK_ACCOUNT)
		await dialog.getByLabel('Bezeichnung').fill('Doppelt')
		await dialog.getByRole('button', { name: 'Anlegen', exact: true }).click()

		await expect(page.getByText(MESSAGE)).toBeVisible()
		await expect(dialog).toBeVisible()
	})
})
