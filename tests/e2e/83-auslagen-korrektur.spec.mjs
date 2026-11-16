import { expect, test } from '@playwright/test'
import { api, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Korrektur bei der Prüfung (Issue #353): ein Verwalter ändert den Betrag eines
// Antrags mit Begründung. Ursprünglicher und neuer Betrag bleiben im Verlauf,
// das Mitglied sieht die Korrektur, und frühere Freigaben verfallen.
//
// Verwalter: admin, test1 und für die Dauer der Spec test2 (N = 2 verlangt drei).

const MEMBER = { firstName: 'Kurt', lastName: 'Korrektur', email: 'kurt.korrektur@example.org' }
const PURPOSE = 'Trikots Jugend'

test.describe.configure({ mode: 'serial' })

async function unlinkExisting(request, ncUserId) {
	const existing = (await api.listMembers(request)).find((m) => m.ncUserId === ncUserId)
	if (existing) {
		await api.unlinkMember(request, existing.id)
		await api.deleteMember(request, existing.id)
	}
}

test.describe('Auslagen: Betrag korrigieren', () => {
	let memberId
	let claimId

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.setRole(request, USERS.buchhalter, 'verwalter')
		await unlinkExisting(request, USERS.ohneRolle)
		memberId = (await api.createMember(request, MEMBER)).id
		await api.linkMember(request, memberId, USERS.ohneRolle)
		await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '2' })

		claimId = (await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: PURPOSE, amount: '12.50' })).json()).id
		// Eine Freigabe liegt schon vor – sie verfällt mit der Korrektur.
		await api.approveReimbursement(request, claimId, { user: USERS.admin })
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

	test('ein Verwalter korrigiert den Betrag mit Begründung; die Freigabe von vorher verfällt', async ({ page, request }) => {
		await openApp(page, USERS.verwalter, { booted: true })
		await switchTab(page, 'Buchungen')
		const subtab = visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' })
		await subtab.click()
		await expect(subtab).toHaveClass(/active/)
		await visibleSection(page).locator('tr.vbh-reimb-row', { hasText: PURPOSE }).click()
		const detail = page.getByRole('dialog', { name: /Auslage AUSL-\d{4}-\d{4} von Kurt Korrektur/ })
		await expect(detail).toContainText('1 von 2 Freigaben')

		await detail.getByRole('button', { name: 'Betrag korrigieren' }).click()
		const dialog = page.getByRole('dialog', { name: 'Betrag korrigieren' })
		const korrigieren = dialog.getByRole('button', { name: 'Korrigieren', exact: true })
		// Ohne Begründung und mit unverändertem Betrag geht es nicht.
		await expect(korrigieren).toBeDisabled()
		await dialog.getByLabel('Neuer Betrag').fill('9,80')
		await expect(korrigieren).toBeDisabled()
		await dialog.getByLabel('Begründung (Pflicht)').fill('Pfand ist nicht erstattungsfähig')
		await korrigieren.click()
		await expect(dialog).toBeHidden()

		await expect(detail).toContainText('9,80')
		await expect(detail).toContainText('beantragt: 12,50')
		await expect(detail).toContainText('0 von 2 Freigaben')
		await expect(detail).toContainText('hat den Betrag von 12,50 € auf 9,80 € korrigiert')
		await expect(detail).toContainText('Pfand ist nicht erstattungsfähig')

		const staff = await (await api.getReimbursement(request, claimId)).json()
		expect(staff).toMatchObject({ amountCents: 980, originalAmountCents: 1250, status: 'submitted' })
		// admin hatte vor der Korrektur freigegeben – das zählt nicht mehr, er darf erneut.
		expect(staff.progress).toMatchObject({ approved: 0, required: 2 })
	})

	test('das Mitglied sieht die Korrektur ohne den Namen des Verwalters', async ({ request }) => {
		const own = await (await api.selfReimbursement(request, claimId, { user: USERS.ohneRolle })).json()
		expect(own).toMatchObject({ amountCents: 980, originalAmountCents: 1250, canWithdraw: true })
		const event = own.history.find((e) => e.action === 'amount_corrected')
		expect(event).toMatchObject({ data: { from: 1250, to: 980 }, comment: 'Pfand ist nicht erstattungsfähig' })
		expect(JSON.stringify(event)).not.toContain(USERS.verwalter)
	})

	test('der korrigierte Antrag wird mit dem neuen Betrag freigegeben, danach ist keine Korrektur mehr möglich', async ({ request }) => {
		await api.approveReimbursement(request, claimId, { user: USERS.admin })
		const approved = await (await api.approveReimbursement(request, claimId, { user: USERS.verwalter })).json()
		expect(approved).toMatchObject({ status: 'approved', amountCents: 980 })
		// Nach der Freigabe ist keine Korrektur mehr möglich.
		const late = await api.raw(request, 'POST', `/reimbursements/${claimId}/correct`, { data: { amount: '5.00', reason: 'zu spät' } })
		expect(late.status()).toBe(400)
	})

	test('der Server verlangt Begründung und Rolle', async ({ request }) => {
		const fresh = (await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Zweiter Antrag' })).json()).id
		const noReason = await api.raw(request, 'POST', `/reimbursements/${fresh}/correct`, { data: { amount: '5.00', reason: ' ' } })
		expect(noReason.status()).toBe(400)
		const unchanged = await api.raw(request, 'POST', `/reimbursements/${fresh}/correct`, { data: { amount: '12.50', reason: 'gleich' } })
		expect(unchanged.status()).toBe(400)
		// Revisoren und Buchhalter dürfen nicht korrigieren.
		const revisor = await api.raw(request, 'POST', `/reimbursements/${fresh}/correct`, { user: USERS.revisor, data: { amount: '5.00', reason: 'Revisor' } })
		expect(revisor.status()).toBe(403)
	})
})
