import { test, expect } from '@playwright/test'
import { api, openApp, pickNcSelectOption, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Beitragsgruppen, Zuweisungen & manuelle Einzelforderungen (Issue #68):
// Gruppen-CRUD, eine Zuweisung samt Inline-Mitgliedsanlage und Prorata-
// Vorschau, sowie eine manuelle Einzelforderung mit Erledigungsvermerk.
//
// Reiter „Beiträge" braucht `membership_enabled` UND mindestens die Rolle
// Buchhalter (siehe ContributionsTab-Dokblock) - revisor sieht ihn gar
// nicht, geprüft wird das hier nur über die API (masked Offene-Posten-Sicht).

const GROUP_NAME = 'Testgruppe Basisbeitrag'
const GROUP_NAME_RENAMED = 'Testgruppe Basisbeitrag (angepasst)'
const NEW_MEMBER_FIRST = 'Zuweisungs'
const NEW_MEMBER_LAST = 'Testperson'
const CLAIM_MEMBER = 'Petra Einzelforderung'

async function enableMembership(request) {
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
	})
}

/** Mitglied per API sicherstellen (idempotent - siehe ensureMemberWithFee in 11-contributions.spec.mjs). */
async function ensureMember(request, firstName, lastName) {
	const members = await api.listMembers(request)
	const displayName = `${firstName} ${lastName}`
	let member = members.find((m) => m.displayName === displayName)
	if (!member) {
		member = await api.createMember(request, { firstName, lastName })
	}
	return member
}

test.describe('Beitragsgruppen, Zuweisungen & Forderungen', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Beitragsgruppe anlegen, bearbeiten und löschen', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Beitragsgruppen' }).click()

		await visibleSection(page).getByRole('button', { name: '+ Beitragsgruppe' }).click()
		let dialog = page.getByRole('dialog', { name: 'Neue Beitragsgruppe' })
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('Name').fill(GROUP_NAME)
		await dialog.getByLabel('Untergrenze (€/Monat)').fill('5')
		await dialog.getByLabel('Standardbeitrag (€/Monat)').fill('8')
		await dialog.getByRole('button', { name: 'Anlegen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const row = visibleSection(page).locator('tr', { hasText: GROUP_NAME })
		await expect(row).toBeVisible()
		await expect(row).toContainText('5,00') // Untergrenze
		await expect(row).toContainText('8,00') // Standardbeitrag

		await row.getByRole('button', { name: 'Bearbeiten' }).click()
		dialog = page.getByRole('dialog', { name: 'Beitragsgruppe bearbeiten' })
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('Name').fill(GROUP_NAME_RENAMED)
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(dialog).toBeHidden()
		await expect(visibleSection(page).locator('tr', { hasText: GROUP_NAME_RENAMED })).toBeVisible()

		const renamedRow = visibleSection(page).locator('tr', { hasText: GROUP_NAME_RENAMED })
		await renamedRow.getByRole('button', { name: 'Löschen', exact: true }).click()
		const confirmDialog = page.getByRole('dialog', { name: 'Beitragsgruppe löschen' })
		await expect(confirmDialog).toBeVisible()
		await confirmDialog.getByRole('button', { name: 'Löschen', exact: true }).click()
		await expect(visibleSection(page).locator('tr', { hasText: GROUP_NAME_RENAMED })).toBeHidden()
	})

	test('Zuweisung anlegen mit neu erfasstem Mitglied und Prorata-Vorschau', async ({ page, request }) => {
		const groups = await api.getJson(request, '/contribution-groups')
		let group = groups.find((g) => g.name === GROUP_NAME)
		if (!group) {
			group = await (await api.raw(request, 'POST', '/contribution-groups', {
				expectOk: true,
				data: { name: GROUP_NAME, minMonthlyAmount: 5, defaultMonthlyAmount: 8, allowedIntervals: [1, 12], defaultInterval: 12, isActive: true },
			})).json()
		}

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Beitragsgruppen' }).click()

		await visibleSection(page).getByRole('button', { name: '+ Zuweisung' }).click()
		const dialog = page.getByRole('dialog', { name: 'Neue Zuweisung' })
		await expect(dialog).toBeVisible()

		await dialog.getByRole('button', { name: '+ neues Mitglied' }).click()
		await dialog.getByPlaceholder('Vorname').fill(NEW_MEMBER_FIRST)
		await dialog.getByPlaceholder('Nachname').fill(NEW_MEMBER_LAST)
		await dialog.getByRole('button', { name: 'Mitglied anlegen', exact: true }).click()

		await dialog.getByLabel('Beitragsgruppe').selectOption({ label: group.name })
		// Standardbeitrag der Gruppe wird beim Wechsel automatisch vorbelegt
		// (AssignmentDialog watch:selectedGroup) - 8,00 € aus obigem Setup.
		// Kein exakter String-Vergleich: AmountInput formatiert mit einem
		// schmalen geschützten Leerzeichen vor dem €-Zeichen (Intl.NumberFormat).
		await expect(dialog.getByLabel('Monatsbeitrag (€)')).toHaveValue(/^8,00\s*€$/)

		await dialog.getByRole('button', { name: 'Vorschau', exact: true }).click()
		await expect(dialog.getByText('Erste Periode:')).toBeVisible()

		await dialog.getByRole('button', { name: 'Anlegen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const row = visibleSection(page).locator('tr', { hasText: `${NEW_MEMBER_FIRST} ${NEW_MEMBER_LAST}` })
		await expect(row).toBeVisible()
		await expect(row).toContainText(group.name)
		await expect(row).toContainText('8,00')
	})

	test('Manuelle Einzelforderung anlegen und als bezahlt markieren', async ({ page, request }) => {
		const [firstName, lastName] = CLAIM_MEMBER.split(' ')
		await ensureMember(request, firstName, lastName)

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Beitragsgruppen' }).click()

		await visibleSection(page).getByRole('button', { name: '+ Einzelforderung' }).click()
		const dialog = page.getByRole('dialog', { name: 'Manuelle Einzelforderung' })
		await expect(dialog).toBeVisible()

		await pickNcSelectOption(dialog, 'Mitglied wählen …', firstName)
		await dialog.getByLabel('Betrag (€)').fill('15')
		await dialog.getByLabel('Bezeichnung').fill('Nachzahlung Sommerfest')
		await dialog.getByRole('button', { name: 'Anlegen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const row = visibleSection(page).locator('tr', { hasText: 'Nachzahlung Sommerfest' })
		await expect(row).toBeVisible()
		await expect(row).toContainText('15,00')
		await expect(row).toContainText('offen')

		await row.getByRole('button', { name: 'Als bezahlt markieren' }).click()
		await expect(row).toContainText('erledigt')
		await expect(row.getByRole('button', { name: 'Als bezahlt markieren' })).toBeHidden()
	})

	test('Offene-Posten-Sicht: revisor liest Forderungen ohne Mitglieds-Kontaktdaten', async ({ request }) => {
		// Eigenständig von der UI-Forderung aus dem vorigen Test: legt die
		// Forderung, die geprüft wird, selbst per API an, statt sich auf einen
		// Seiteneffekt eines UI-Tests zu verlassen (sonst würde ein Fehlschlag
		// dort diesen Test hier scheinbar unabhängig mit fehlschlagen lassen).
		const [firstName, lastName] = CLAIM_MEMBER.split(' ')
		const member = await ensureMember(request, firstName, lastName)
		await api.raw(request, 'POST', '/claims', {
			expectOk: true,
			data: { memberId: member.id, type: 'beitrag', amount: 7, label: 'API-Testforderung', dueDate: '2026-12-01' },
		})

		const claims = await api.getJson(request, '/claims', { user: USERS.revisor })
		expect(claims.length).toBeGreaterThan(0)
		for (const claim of claims) {
			expect(claim).not.toHaveProperty('email')
			expect(claim).not.toHaveProperty('internalNote')
		}

		// Schreibender Zugriff bleibt Buchhalter vorbehalten.
		const forbidden = await api.raw(request, 'POST', '/claims', {
			user: USERS.revisor,
			data: { memberId: claims[0].memberId, type: 'gebuehr', amount: 5, label: 'Sollte scheitern', dueDate: '2026-12-01' },
		})
		expect(forbidden.status()).toBe(403)
	})
})
