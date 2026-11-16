import { expect, test } from '@playwright/test'
import { api, BELEG_PNG, openApp, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Entwürfe von Auslagen (Issue #356): ein Mitglied speichert einen
// unvollständigen Antrag, ergänzt ihn später und reicht ihn ein. Bis dahin
// sieht ihn niemand außer dem Mitglied – weder die Freigabeliste noch die
// Zähler. Eingereicht wird über denselben Weg wie ein neuer Antrag.

const MEMBER = { firstName: 'Erika', lastName: 'Entwurf', email: 'erika.entwurf@example.org' }
const IBAN = 'DE89 3704 0044 0532 0130 00'

test.describe.configure({ mode: 'serial' })

async function unlinkExisting(request, ncUserId) {
	const existing = (await api.listMembers(request)).find((m) => m.ncUserId === ncUserId)
	if (existing) {
		await api.unlinkMember(request, existing.id)
		await api.deleteMember(request, existing.id)
	}
}

async function selfData(request) {
	return (await api.selfReimbursements(request, { user: USERS.ohneRolle })).json()
}

async function openOwnArea(page) {
	await openApp(page, USERS.ohneRolle)
	await tabButton(page, 'Mein Bereich').click()
	const section = visibleSection(page)
	await expect(section.getByRole('heading', { name: 'Meine Auslagen' })).toBeVisible()
	return section
}

test.describe('Auslagen: Entwürfe', () => {
	let memberId

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.setRole(request, USERS.buchhalter, 'verwalter')
		await unlinkExisting(request, USERS.ohneRolle)
		memberId = (await api.createMember(request, MEMBER)).id
		await api.linkMember(request, memberId, USERS.ohneRolle)
		await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '2' })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { reimbursement_enabled: '0', reimbursement_required_approvals: '2' }, { expectOk: false })
		await api.setRole(request, USERS.buchhalter, 'buchhalter')
		if (memberId) {
			await api.unlinkMember(request, memberId, { expectOk: false })
			await api.deleteMember(request, memberId, { expectOk: false })
		}
		await api.resetBook(request)
	})

	test('ein unvollständiger Antrag lässt sich als Entwurf speichern, den nur das Mitglied sieht', async ({ page, request }) => {
		const section = await openOwnArea(page)
		await section.getByRole('button', { name: 'Auslage einreichen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Auslage einreichen' })
		// Ein leeres Formular lohnt keinen Entwurf.
		await expect(dialog.getByRole('button', { name: 'Als Entwurf speichern' })).toBeDisabled()

		await dialog.getByLabel('Zweck / Vereinsbezug').fill('Fahrt zum Turnier')
		await dialog.locator('input[type="file"]:not([capture])').setInputFiles({ name: 'tankbeleg.png', mimeType: 'image/png', buffer: BELEG_PNG })
		// Betrag, IBAN und Erklärung fehlen: einreichen geht nicht, speichern schon.
		await expect(dialog.getByRole('button', { name: 'Einreichen', exact: true })).toBeDisabled()
		await dialog.getByRole('button', { name: 'Als Entwurf speichern' }).click()
		await expect(dialog).toBeHidden()

		const drafts = section.getByRole('region', { name: 'Entwürfe' })
		await expect(drafts).toContainText('Fahrt zum Turnier')
		await expect(drafts).toContainText('ohne Betrag')
		await expect(drafts).toContainText('1 Beleg')
		await expect(section.locator('tr.vbh-reimb-row')).toHaveCount(0)

		const own = await selfData(request)
		expect(own.items).toHaveLength(0)
		expect(own.drafts).toHaveLength(1)
		expect(own.drafts[0]).toMatchObject({ purpose: 'Fahrt zum Turnier', amountCents: null })
		expect(own.drafts[0].files).toHaveLength(1)

		// Die Verwaltung sieht nichts davon – weder in der Liste noch in den Zählern.
		const staff = await (await api.listReimbursements(request)).json()
		expect(Array.isArray(staff) ? staff : staff.items).toHaveLength(0)
		const summary = await (await api.raw(request, 'GET', '/reimbursements/summary')).json()
		expect(JSON.stringify(summary)).not.toContain('Fahrt zum Turnier')
	})

	test('der Entwurf lässt sich später ergänzen und einreichen', async ({ page, request }) => {
		const section = await openOwnArea(page)
		await section.getByRole('button', { name: /Fahrt zum Turnier/ }).click()
		const dialog = page.getByRole('dialog', { name: 'Entwurf bearbeiten' })
		await expect(dialog.getByLabel('Zweck / Vereinsbezug')).toHaveValue('Fahrt zum Turnier')
		await expect(dialog.getByRole('link', { name: 'tankbeleg.png' })).toBeVisible()
		// Die Erklärung ist nie gespeichert: sie wird beim Einreichen bestätigt.
		await expect(dialog).toContainText('Erklärung')

		// Zwischenspeichern mit Betrag – der Entwurf bleibt einer.
		await dialog.getByLabel('Betrag (€)', { exact: true }).fill('23,40')
		await dialog.getByRole('button', { name: 'Als Entwurf speichern' }).click()
		await expect(dialog).toBeHidden()
		await expect(section.getByRole('region', { name: 'Entwürfe' })).toContainText('23,40')
		expect((await selfData(request)).drafts[0].amountCents).toBe(2340)

		await section.getByRole('button', { name: /Fahrt zum Turnier/ }).click()
		await expect(dialog).toBeVisible()
		await expect(dialog.getByLabel('Betrag (€)', { exact: true })).toHaveValue(/23,40/)
		await dialog.getByLabel('IBAN für die Erstattung').fill(IBAN)
		await dialog.getByText('Ich versichere, dass die Auslagen im Auftrag des Vereins entstanden sind').click()
		await dialog.getByRole('button', { name: 'Einreichen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const row = section.locator('tr.vbh-reimb-row', { hasText: 'Fahrt zum Turnier' })
		await expect(row).toContainText('23,40')
		await expect(row).toContainText('Eingereicht')
		await expect(section.getByRole('region', { name: 'Entwürfe' })).toHaveCount(0)

		const own = await selfData(request)
		expect(own.drafts).toHaveLength(0)
		expect(own.items).toHaveLength(1)
		// Der Beleg aus dem Entwurf ist jetzt der Beleg des Antrags – bei der Verwaltung angekommen.
		const staff = await (await api.getReimbursement(request, own.items[0].id)).json()
		expect(staff).toMatchObject({ purpose: 'Fahrt zum Turnier', amountCents: 2340, status: 'submitted' })
		expect(staff.files.map((f) => f.fileName)).toEqual(['tankbeleg.png'])
	})

	test('ein Entwurf lässt sich löschen, erst der zweite Klick löscht', async ({ page, request }) => {
		const created = await api.raw(request, 'POST', '/self/reimbursement-drafts', {
			user: USERS.ohneRolle,
			multipart: { purpose: 'Doch nicht' },
		})
		expect(created.status()).toBe(201)

		const section = await openOwnArea(page)
		await section.getByRole('button', { name: /Doch nicht/ }).click()
		const dialog = page.getByRole('dialog', { name: 'Entwurf bearbeiten' })
		await dialog.getByRole('button', { name: 'Entwurf löschen' }).click()
		await expect(dialog).toBeVisible()
		await dialog.getByRole('button', { name: 'Entwurf wirklich löschen' }).click()
		await expect(dialog).toBeHidden()
		await expect(section.getByRole('region', { name: 'Entwürfe' })).toHaveCount(0)
		expect((await selfData(request)).drafts).toHaveLength(0)
	})

	test('fremde Entwürfe gibt es nicht: andere Nutzer erreichen sie nicht', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/self/reimbursement-drafts', {
			user: USERS.ohneRolle,
			multipart: { purpose: 'Privat', 'files[]': { name: 'beleg.png', mimeType: 'image/png', buffer: BELEG_PNG } },
		})).json()
		// admin ist mit keinem Mitglied verknüpft: der Self-Service-Kanal ist für ihn zu.
		const foreign = await api.raw(request, 'GET', `/self/reimbursement-drafts/${draft.id}/files/${draft.files[0].id}/view`)
		expect(foreign.ok()).toBe(false)
		const del = await api.raw(request, 'DELETE', `/self/reimbursement-drafts/${draft.id}`)
		expect(del.ok()).toBe(false)
		// Das Mitglied selbst kommt an seinen Beleg.
		const own = await api.raw(request, 'GET', `/self/reimbursement-drafts/${draft.id}/files/${draft.files[0].id}/view`, { user: USERS.ohneRolle })
		expect(own.status()).toBe(200)
		expect((await selfData(request)).drafts).toHaveLength(1)
	})
})
