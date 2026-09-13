import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Der Fokus-Race in NcModal (siehe AttachmentFolderDialog/21-belegordner):
// focus-trap aktiviert sich erst nach der Öffnen-Animation und kann den Fokus
// sonst dauerhaft auf der Modal-Maske hängen lassen. Dieselbe Reparatur
// (Feld beim Öffnen sofort fokussieren) sitzt jetzt auch in den anderen
// Dialogen mit einem klaren ersten Feld - hier geprüft: sofort tippen, ohne
// vorher zu klicken.

const MEMBER = 'Sofort Mitglied'
const MEMBER_BANK = 'Sofort Bankwechsel'

async function enableMembership(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

test.describe('Sofort-Fokus beim Öffnen von NcModal-Dialogen', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('"Neues Konto": das Nummernfeld ist direkt nach dem Öffnen bedienbar', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await visibleSection(page).getByRole('button', { name: 'Konto', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Neues Konto' })
		await expect(dialog).toBeVisible()
		await page.keyboard.type('9876')
		await expect(dialog.getByLabel('Nummer')).toHaveValue('9876')
	})

	test('"Mitglied aufnehmen": das Namensfeld ist direkt nach dem Öffnen bedienbar', async ({ page, request }) => {
		await enableMembership(request)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()
		await page.keyboard.type(MEMBER)
		await expect(dialog.getByRole('textbox', { name: 'Name' })).toHaveValue(MEMBER)

		await dialog.getByLabel('IBAN', { exact: true }).fill('DE02120300000000202051')
		await dialog.getByLabel('Mandat unterschrieben am').fill('2026-01-15')
		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()
	})

	test('"Bankverbindung wechseln": das IBAN-Feld ist direkt nach dem Öffnen bedienbar', async ({ page, request }) => {
		await enableMembership(request)
		const mandate = await (await api.raw(request, 'POST', '/sepa/mandates', {
			expectOk: true,
			data: {
				memberUid: null,
				memberLabel: MEMBER_BANK,
				iban: 'DE02120300000000202051',
				bic: null,
				mandateType: 'RCUR',
				signedDate: '2026-01-15',
			},
		})).json()
		expect(mandate.status).toBe('active')

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('tr', { hasText: MEMBER_BANK })
		await row.getByRole('button', { name: 'Aktionen' }).click()
		await page.getByRole('menuitem', { name: 'Bankverbindung wechseln' }).click()

		const dialog = page.getByRole('dialog', { name: 'Bankverbindung wechseln' })
		await expect(dialog).toBeVisible()
		await page.keyboard.type('DE33100000000000009911')
		await expect(dialog.getByLabel('Neue IBAN')).toHaveValue('DE33100000000000009911')
	})
})
