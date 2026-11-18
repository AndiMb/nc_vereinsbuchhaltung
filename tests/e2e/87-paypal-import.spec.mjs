import { expect, test } from '@playwright/test'
import { join } from 'path'
import { api, BANK_ACCOUNT, fixture, FIXTURES_DIR, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// PayPal-Import (Issue #375): Der PayPal-Export hat keine IBAN; das eigene
// Konto erkennt die App an der PayPal-Adresse am Geldkonto. Die Fixture
// beispiel-paypal.csv (Adresse kasse@example.org) enthält eine Spende mit
// Gebührenzeile, eine Zahlung und die zugehörige Bankgutschrift als neue
// Umsätze sowie zwei Zeilen, die nicht übernommen werden (Memo, USD).

const FILE = join(FIXTURES_DIR, 'beispiel-paypal.csv')
const PAYPAL_EMAIL = 'kasse@example.org'

test.describe('PayPal-Import', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// Gegenkonto der Gebühr-Regel; der Standard-Kontenrahmen hat keins.
		await api.createAccount(request, { number: '6850', name: 'Nebenkosten des Geldverkehrs', type: 'expense' })
	})

	test('Geldkonto mit PayPal-Adresse über die Oberfläche anlegen', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await visibleSection(page).getByRole('button', { name: 'Konto', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Neues Konto' })
		await dialog.getByLabel('Nummer').fill('1250')
		await dialog.getByLabel('Bezeichnung').fill('PayPal')
		await dialog.getByLabel('Typ').selectOption('asset')
		// Das PayPal-Feld gibt es nur bei Geldkonten.
		await expect(dialog.getByTestId('account-paypal-email')).toHaveCount(0)
		await dialog.getByText('Geldkonto (Bank/Kasse)').click()
		await dialog.getByTestId('account-paypal-email').fill(PAYPAL_EMAIL)
		await dialog.getByRole('button', { name: 'Anlegen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const paypal = await api.accountByNumber(request, '1250')
		expect(paypal.isBank).toBe(true)
		expect(paypal.paypalEmail).toBe(PAYPAL_EMAIL)
	})

	test('Import: Vorschau, Gebühr per Regel zugeordnet, zweiter Import ohne Neues', async ({ page, request }) => {
		// Vorbedingung selbst herstellen (siehe 04-import.spec.mjs).
		const accounts = await api.accountsByNumber(request, BANK_ACCOUNT, '6850')
		let paypal = (await api.listAccounts(request)).find((a) => a.number === '1250')
		if (!paypal) {
			paypal = await api.createAccount(request, { number: '1250', name: 'PayPal', type: 'asset', isBank: true })
		}
		await api.updateAccount(request, paypal.id, { paypalEmail: PAYPAL_EMAIL })
		await api.createRule(request, { matchField: 'purpose', matchValue: 'PayPal-Gebühr', contraAccountId: accounts[1].id })

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Umsätze importieren' }).click()

		const dialog = page.getByRole('dialog')
		await dialog.locator('input[type="file"]').setInputFiles(FILE)

		// Spende, Zahlung und Gutschrift sind neu; Memo- und USD-Zeile nicht.
		await expect(dialog.getByText('4 neu')).toBeVisible()
		await expect(dialog.getByTestId('import-skipped')).toHaveText('2 übersprungen')
		// Die PayPal-Adresse ist einem Geldkonto zugeordnet: kein Hinweis.
		await expect(dialog.getByTestId('import-unresolved-accounts')).toHaveCount(0)

		// Den Dialog erst schließen, wenn der Import durch ist.
		await dialog.getByRole('button', { name: '4 Buchungen importieren' }).click()
		await expect(dialog.getByText('4 Buchungen importiert')).toBeVisible()
		await dialog.getByRole('button', { name: 'Schließen' }).last().click()

		const all = await api.listTransactions(request)
		expect(all).toHaveLength(4)
		const fee = all.find((t) => t.purpose.includes('PayPal-Gebühr'))
		expect(fee.amount).toBe(-1.6)
		expect(fee.status).toBe('assigned')

		const open = await api.listTransactions(request, { status: 'unassigned' })
		expect(open.map((t) => t.amount).sort((a, b) => a - b)).toEqual([-1250, 50, 1250])
		for (const tx of all) {
			expect(tx.ownAccount).toMatch(/^PAYPAL/)
		}

		await visibleSection(page).getByRole('button', { name: 'Zuzuordnen' }).click()
		await expect(visibleSection(page).getByText('1 von 4 Bankbuchungen zugeordnet')).toBeVisible()

		// Dieselbe Datei noch einmal: alles Dubletten.
		const again = await api.importStatement(request, fixture('beispiel-paypal.csv'), { filename: 'paypal.csv' })
		expect(again.new).toBe(0)
		expect(again.duplicate).toBe(4)
		expect(await api.listTransactions(request)).toHaveLength(4)
	})
})
