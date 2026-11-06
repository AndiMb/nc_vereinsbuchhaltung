import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, openApp, openSettingsPage, pickNcSelectOption, switchTab, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Auslagennummer, Überweisung vormerken und den Kontoumsatz der Auslage zuordnen
// (docs/reimbursement-design.md §7): das Mitglied sieht die Nummer, die Suche findet
// sie, das Muster ist einstellbar, die Vormerkung und die Auszahlung lassen sich
// zurücknehmen, und ein Umsatz wird per Chip oder von Hand einer Auslage zugeordnet.
//
// Verwalter sind admin, test1 und - für die Dauer der Spec - test2 (N = 2 verlangt
// mindestens drei). Eingereicht wird als test4, verknüpft mit „Erika Musterfrau".

const MEMBER = { firstName: 'Erika', lastName: 'Musterfrau', email: 'erika.zuordnung@example.org' }
const MEMBER_IBAN = 'DE89370400440532013000'
// Eine fremde IBAN: der Umsatz trägt dann weder Nummer noch bekannte IBAN und lässt sich nur von Hand zuordnen.
const OTHER_IBAN = 'DE02120300000000202051'
const TODAY = new Date().toISOString().slice(0, 10)

test.describe.configure({ mode: 'serial' })

/** Die Auslage mit diesem Zweck, frisch vom Server. */
async function claim(request, purpose) {
	return (await (await api.listReimbursements(request)).json()).items.find((i) => i.purpose === purpose)
}

/** Reiter „Buchungen → Auslagen" in der Verwaltungssicht. */
async function openAuslagenSicht(page, user) {
	await openApp(page, user, { booted: true })
	await switchTab(page, 'Buchungen')
	const subtab = visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' })
	await subtab.click()
	await expect(subtab).toHaveClass(/active/)
	await visibleSection(page).locator('.vbh-reimb-staff .vbh-chip', { hasText: /^Alle\s*\d/ }).click()
}

async function openAntrag(page, purpose) {
	await visibleSection(page).locator('tr.vbh-reimb-row', { hasText: purpose }).click()
	const dialog = page.getByRole('dialog', { name: /Auslage AUSL-\d{4}-\d{4} von Erika Musterfrau/ })
	await expect(dialog).toBeVisible()
	return dialog
}

/** Reiter „Buchungen → Zuzuordnen“. */
async function openZuzuordnen(page, user) {
	await openApp(page, user, { booted: true })
	await switchTab(page, 'Buchungen')
	// Der Reiter trägt die Zahl der offenen Umsätze im Namen („Zuzuordnen 1“).
	await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: /^Zuzuordnen/ }).click()
}

test.describe('Auslagen: Nummer, Vormerken, Kontoumsatz zuordnen', () => {
	let memberId
	let expense
	const claims = {}

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await api.resetBook(request)
		const accounts = await api.seedDefaultAccounts(request)
		expense = accounts.find((a) => a.type === 'expense')
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)
		await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })

		await api.setRole(request, USERS.buchhalter, 'verwalter')
		await api.unlinkMemberByNcUser(request, USERS.ohneRolle)
		memberId = (await api.createMember(request, MEMBER)).id
		await api.linkMember(request, memberId, USERS.ohneRolle)
		await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '2' })
		// Das Zurücksetzen des Bestands lässt den Nummernkreis stehen (wie bei den Rechnungen): hier von vorn zählen.
		await api.saveReimbursementNumberRange(request, { prefixPattern: 'AUSL-{JJJJ}-{NNNN}', resetMode: 'yearly', nextNo: 1 })

		// Drei freigegebene Auslagen mit verschiedenen Beträgen.
		for (const [purpose, amount] of [['Material Sommerfest', '12.50'], ['Porto Mitgliederbrief', '30.00'], ['Fahrtkosten Turnier', '7.77']]) {
			const submitted = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose, amount })).json()
			await api.approveReimbursement(request, submitted.id, { user: USERS.admin })
			await api.approveReimbursement(request, submitted.id, { user: USERS.verwalter })
			claims[purpose] = submitted.id
		}
	})

	test.afterAll(async ({ request }) => {
		await api.saveReimbursementNumberRange(request, { prefixPattern: 'AUSL-{JJJJ}-{NNNN}', resetMode: 'yearly' }, { expectOk: false })
		await api.updateSettings(request, { reimbursement_enabled: '0', reimbursement_required_approvals: '2' }, { expectOk: false })
		await api.setRole(request, USERS.buchhalter, 'buchhalter')
		if (memberId) {
			await api.unlinkMember(request, memberId, { expectOk: false })
			await api.deleteMember(request, memberId, { expectOk: false })
		}
		await api.resetBook(request)
	})

	// --- Nummer, Suche, Muster ---------------------------------------------------------

	test('das Mitglied sieht die Auslagennummer in „Mein Bereich"', async ({ page }) => {
		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Bereich').click()
		const row = visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Material Sommerfest' })
		await expect(row).toContainText(/AUSL-\d{4}-0001/)

		await row.click()
		const dialog = page.getByRole('dialog', { name: /Auslage vom/ })
		await expect(dialog).toContainText('Auslagennummer')
		await expect(dialog).toContainText(/AUSL-\d{4}-0001/)
	})

	test('die Suche in der Verwaltung findet Auslagen über „AUSL-" und die ganze Nummer', async ({ page, request }) => {
		const second = (await claim(request, 'Porto Mitgliederbrief')).number
		await openAuslagenSicht(page, USERS.admin)
		const search = visibleSection(page).locator('.vbh-reimb-search')
		const rows = visibleSection(page).locator('tr.vbh-reimb-row')

		await search.fill('AUSL-')
		await expect(rows).toHaveCount(3)
		await search.fill(second)
		await expect(rows).toHaveCount(1)
		await expect(rows.first()).toContainText('Porto Mitgliederbrief')
		await search.fill('gibt-es-nicht')
		await expect(rows).toHaveCount(0)
	})

	test('das Muster der Auslagennummer lässt sich in den Einstellungen ändern', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const card = page.locator('#settings-section_auslagen .vbh-card', { hasText: 'Auslagennummern' })
		await card.getByLabel('Muster').fill('EXP-{JJ}-{NNN}')
		await expect(card).toContainText(/Vorschau: EXP-\d{2}-\d{3}/)
		await card.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert' }).first()).toBeVisible()
		expect((await api.reimbursementNumberRange(request)).prefixPattern).toBe('EXP-{JJ}-{NNN}')

		// Neue Anträge folgen dem Muster, vergebene Nummern bleiben.
		const fresh = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Neues Muster' })).json()
		expect(fresh.number).toMatch(/^EXP-\d{2}-\d{3}$/)
		expect((await claim(request, 'Material Sommerfest')).number).toMatch(/^AUSL-\d{4}-0001$/)
		await api.selfWithdrawReimbursement(request, fresh.id, { user: USERS.ohneRolle })

		// Ein ungültiges Muster nennt der Server beim Namen.
		const invalid = await api.saveReimbursementNumberRange(request, { prefixPattern: 'OHNE-ZAEHLER', resetMode: 'yearly' }, { expectOk: false })
		expect(invalid.status()).toBe(400)

		await api.saveReimbursementNumberRange(request, { prefixPattern: 'AUSL-{JJJJ}-{NNNN}', resetMode: 'yearly' })
	})

	// --- Vormerken -------------------------------------------------------------------

	test('„Überweisung abgeschlossen" merkt vor, „Vormerkung zurücknehmen" macht es rückgängig', async ({ page, request }) => {
		await openAuslagenSicht(page, USERS.admin)
		const dialog = await openAntrag(page, 'Material Sommerfest')

		await dialog.getByRole('button', { name: 'Überweisung vorbereiten' }).click()
		const transfer = page.getByRole('dialog', { name: 'Überweisung vorbereiten' })
		await expect(transfer.getByRole('img', { name: 'Girocode der Überweisung' })).toBeVisible()
		await transfer.getByRole('button', { name: 'Überweisung abgeschlossen' }).click()
		await expect(dialog).toContainText('Die Überweisung ist angewiesen')
		expect((await claim(request, 'Material Sommerfest')).transferredAt).not.toBeNull()

		await dialog.getByRole('button', { name: 'Vormerkung zurücknehmen' }).click()
		await expect(dialog).not.toContainText('Die Überweisung ist angewiesen')
		await expect(dialog.getByRole('button', { name: 'Vormerkung zurücknehmen' })).toHaveCount(0)
		const after = await (await api.getReimbursement(request, claims['Material Sommerfest'])).json()
		expect(after.transferredAt).toBeNull()
		expect(after.status).toBe('approved')
		expect(after.history.map((e) => e.action)).toEqual(['submitted', 'approved', 'approved', 'transferred', 'transfer_revoked'])
	})

	// --- Kontoumsatz zuordnen: Chip --------------------------------------------------------

	test('der Chip „✓ Auslage" ordnet einen Umsatz mit Nummer im Verwendungszweck zu und bucht ihn', async ({ page, request }) => {
		const material = await claim(request, 'Material Sommerfest')
		await api.markReimbursementTransferred(request, material.id)
		await api.importCamtStatement(request, [{ bookingDate: TODAY, direction: 'DBIT', amountCents: 1250, counterparty: 'Erika Musterfrau', counterpartyIban: MEMBER_IBAN, purpose: `${material.number} - Material Sommerfest` }])

		await openZuzuordnen(page, USERS.admin)
		const row = visibleSection(page).locator('tr', { hasText: material.number })
		await row.getByRole('button', { name: `✓ Auslage ${material.number}` }).click()

		const dialog = page.getByRole('dialog', { name: 'Umsatz einer Auslage zuordnen' })
		await expect(dialog.getByRole('radio', { name: new RegExp(material.number) })).toBeChecked()
		await pickNcSelectOption(dialog, 'Konto wählen', expense.name)
		await dialog.getByRole('button', { name: 'Zuordnen und buchen' }).click()
		await expect(dialog).toBeHidden()

		const paid = await (await api.getReimbursement(request, material.id)).json()
		expect(paid.status).toBe('paid')
		expect(paid.journalId).toBeTruthy()
		expect((await api.listTransactions(request, { status: 'unassigned' })).some((t) => t.purpose?.includes(material.number))).toBe(false)
		const openItem = (await api.getJson(request, '/open-items')).find((i) => i.reimbursementId === material.id)
		expect(openItem).toMatchObject({ status: 'paid', paidJournalId: paid.journalId })
	})

	// --- Kontoumsatz zuordnen: von Hand ---------------------------------------------------------

	test('„Einer Auslage zuordnen…" ordnet einen Umsatz ohne Nummer von Hand zu; ein anderer Betrag ist gesperrt', async ({ page, request }) => {
		await api.importCamtStatement(request, [{ bookingDate: TODAY, direction: 'DBIT', amountCents: 3000, counterparty: 'Erika M.', counterpartyIban: OTHER_IBAN, purpose: 'Erstattung Porto' }])

		await openZuzuordnen(page, USERS.admin)
		const row = visibleSection(page).locator('tr', { hasText: 'Erstattung Porto' })
		// Weder Nummer noch bekannte IBAN: kein Chip, nur der Weg von Hand.
		await expect(row.getByRole('button', { name: /^✓ Auslage/ })).toHaveCount(0)
		await row.getByRole('button', { name: 'Einer Auslage zuordnen…' }).click()

		const dialog = page.getByRole('dialog', { name: 'Umsatz einer Auslage zuordnen' })
		const wrong = dialog.locator('label.vbh-reimb-pick-row', { hasText: 'Fahrtkosten Turnier' })
		await expect(wrong).toContainText('Der Betrag des Umsatzes passt nicht')
		await expect(wrong.getByRole('radio')).toBeDisabled()
		await dialog.locator('label.vbh-reimb-pick-row', { hasText: 'Porto Mitgliederbrief' }).getByRole('radio').check()
		await pickNcSelectOption(dialog, 'Konto wählen', expense.name)
		await dialog.getByRole('button', { name: 'Zuordnen und buchen' }).click()
		await expect(dialog).toBeHidden()

		expect((await claim(request, 'Porto Mitgliederbrief')).status).toBe('paid')
	})

	// --- Zahlung zurücknehmen --------------------------------------------------------------------

	test('„Zahlung zurücknehmen" gibt den Umsatz wieder frei und stellt Antrag, Posten und Beleg her', async ({ page, request }) => {
		const material = await claim(request, 'Material Sommerfest')
		await openAuslagenSicht(page, USERS.admin)
		const dialog = await openAntrag(page, 'Material Sommerfest')
		await expect(dialog.locator('.vbh-typetag').first()).toHaveText('Ausgezahlt')

		await dialog.getByRole('button', { name: 'Zahlung zurücknehmen' }).click()
		await page.getByRole('dialog').getByRole('button', { name: 'Zurücknehmen', exact: true }).click()
		await expect(dialog.locator('.vbh-typetag').first()).toHaveText('Freigegeben')

		const back = await (await api.getReimbursement(request, material.id)).json()
		expect(back).toMatchObject({ status: 'approved', journalId: null, paidAt: null })
		expect(back.history.at(-1).action).toBe('payment_reverted')
		const openItem = (await api.getJson(request, '/open-items')).find((i) => i.reimbursementId === material.id)
		expect(openItem).toMatchObject({ status: 'open', paidJournalId: null })
		// Der Umsatz steht wieder unter „Zuzuordnen“, und der Beleg liegt wieder beim Antrag.
		expect((await api.listTransactions(request, { status: 'unassigned' })).some((t) => t.purpose?.includes(material.number))).toBe(true)
		const receipt = await api.raw(request, 'GET', `/reimbursements/${material.id}/receipts/${back.files[0].id}/view`)
		expect(receipt.status()).toBe(200)
	})

	test('löscht jemand die Buchung einer ausgezahlten Auslage, ist sie wieder freigegeben', async ({ request }) => {
		const porto = await claim(request, 'Porto Mitgliederbrief')
		expect(porto.status).toBe('paid')

		await api.deleteBooking(request, porto.journalId)

		const back = await (await api.getReimbursement(request, porto.id)).json()
		expect(back).toMatchObject({ status: 'approved', journalId: null })
		expect(back.history.at(-1).action).toBe('payment_reverted')
		const receipt = await api.raw(request, 'GET', `/reimbursements/${porto.id}/receipts/${back.files[0].id}/view`)
		expect(receipt.status()).toBe(200)
	})
})
