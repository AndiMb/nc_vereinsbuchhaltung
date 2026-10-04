import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Der Fokus-Race in NcModal (siehe AttachmentFolderDialog/21-belegordner):
// focus-trap aktiviert sich erst nach der Öffnen-Animation und kann den Fokus
// sonst dauerhaft auf der Modal-Maske hängen lassen. Dieselbe Reparatur
// (Feld beim Öffnen sofort fokussieren) sitzt jetzt auch in den anderen
// Dialogen mit einem klaren ersten Feld - hier geprüft: sofort tippen, ohne
// vorher zu klicken.

const today = () => new Date().toISOString().slice(0, 10)

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

	test('"Mitglied aufnehmen": das Vorname-Feld ist direkt nach dem Öffnen bedienbar', async ({ page, request }) => {
		await enableMembership(request)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()
		await page.keyboard.type('Sofort')
		await expect(dialog.getByRole('textbox', { name: 'Vorname', exact: true })).toHaveValue('Sofort')

		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Mitglied')
		await dialog.getByLabel('IBAN', { exact: true }).fill('DE02120300000000202051')
		await dialog.getByLabel('Mandat unterschrieben am').fill('2026-01-15')
		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()
	})

	test('"Bankverbindung ändern" (Mandat in der Akte): das IBAN-Feld ist direkt nach dem Öffnen bedienbar', async ({ page, request }) => {
		await enableMembership(request)
		// Eindeutiger Nachname: /reset räumt die Mitglieder nicht ab, ein Wiederholungslauf fände sonst zwei gleichnamige Zeilen.
		const lastName = `Bankwechsel-${Math.random().toString(36).slice(2, 6)}`
		const member = await api.createMember(request, { firstName: 'Sofort', lastName })
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: 'DE02120300000000202051', signedAt: today() })).json()
		await api.activateMandate(request, mandate.id)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('tr', { hasText: member.displayName })
		await row.getByRole('button', { name: 'Aktionen' }).click()
		await page.getByRole('menuitem', { name: 'Mandat verwalten' }).click()
		const akte = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
		await akte.locator('section.vbh-mandate-panel').getByRole('button', { name: 'Bankverbindung ändern' }).click()

		const dialog = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await expect(dialog).toBeVisible()
		// Das Feld ist mit der bisherigen IBAN vorbelegt: ohne vorheriges Klicken tippen und prüfen,
		// dass das Zeichen im Feld ankommt – bei hängendem Fokus (Modal-Maske) ginge es ins Leere.
		await page.keyboard.type('Q')
		await expect(dialog.getByLabel('Neue IBAN')).toBeFocused()
		await expect(dialog.getByLabel('Neue IBAN')).toHaveValue(/Q/)
	})
})
