import { expect, test } from '@playwright/test'
import { api, openApp, switchTab, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Reisekostenabrechnung (Issue #353, Stufe 1): eine Art der Auslage mit Ziel,
// Zeitraum und Positionen (Fahrtkosten, Parkgebühren). Erstattet werden die
// eingetragenen tatsächlichen Kosten; die Summe rechnet der Server nach. Belege
// sind freiwillig – für die Fahrt mit dem eigenen Auto gibt es keinen.

const MEMBER = { firstName: 'Rita', lastName: 'Reise', email: 'rita.reise@example.org' }
const IBAN = 'DE89 3704 0044 0532 0130 00'

test.describe.configure({ mode: 'serial' })

/** Ein Tag vor `days` Tagen als JJJJ-MM-TT (die Reise darf nicht in der Zukunft beginnen). */
function daysAgo(days) {
	const d = new Date(Date.now() - days * 86400000)
	return d.toISOString().slice(0, 10)
}

const DAY = daysAgo(5)

function travel(overrides = {}) {
	return {
		destination: 'Bezirksmeisterschaft Fulda',
		start: `${DAY}T08:00`,
		end: `${DAY}T18:30`,
		items: [
			{ type: 'travel', transport: 'public', route: 'Hbf – Fulda und zurück', distanceKm: null, amountCents: 3980 },
			{ type: 'parking', note: null, amountCents: 500 },
		],
		...overrides,
	}
}

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

function submitTravel(request, payload, extra = {}) {
	return api.raw(request, 'POST', '/self/reimbursements', {
		user: USERS.ohneRolle,
		expectOk: false,
		multipart: {
			kind: 'travel',
			travel: JSON.stringify(payload),
			purpose: 'Fahrt zur Bezirksmeisterschaft',
			iban: IBAN.replace(/ /g, ''),
			declaration: '1',
			...extra,
		},
	})
}

test.describe('Reisekosten abrechnen', () => {
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

	test('ein Mitglied rechnet eine Autofahrt mit Parkgebühr ohne Beleg ab', async ({ page, request }) => {
		const section = await openOwnArea(page)
		await section.getByRole('button', { name: 'Reisekosten abrechnen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Reisekosten abrechnen' })
		// Rechnungsangaben gibt es hier nicht – die Reiseangaben ersetzen sie.
		await expect(dialog.getByLabel('Rechnungsnummer')).toHaveCount(0)
		await expect(dialog).toContainText('Noch nötig: Reiseziel, Beginn und Ende')

		await dialog.getByLabel('Reiseziel').fill('Landesturnier Kassel')
		await dialog.getByLabel('Beginn', { exact: true }).fill(`${DAY}T07:30`)
		await dialog.getByLabel('Ende', { exact: true }).fill(`${DAY}T19:15`)

		const fahrt = dialog.getByRole('group', { name: 'Fahrtkosten, Position 1' })
		await fahrt.getByLabel('Verkehrsmittel').selectOption('car')
		await fahrt.getByLabel('Strecke').fill('Vereinsheim – Kassel und zurück')
		await fahrt.getByLabel('Entfernung (km)').fill('184')
		await fahrt.getByLabel('Betrag (€)').fill('55,20')

		await dialog.getByRole('button', { name: 'Parkgebühren' }).click()
		const parken = dialog.getByRole('group', { name: 'Parkgebühren, Position 2' })
		await parken.getByLabel('Ort / Hinweis').fill('Parkhaus Messe')
		await parken.getByLabel('Betrag (€)').fill('8')
		await expect(dialog).toContainText('Summe: 63,20')

		await dialog.getByLabel('Zweck / Vereinsbezug').fill('Fahrt zum Landesturnier')
		await dialog.getByLabel('IBAN für die Erstattung').fill(IBAN)
		await dialog.getByText('Ich versichere, dass die Auslagen im Auftrag des Vereins entstanden sind').click()
		// Kein Beleg nötig.
		await expect(dialog.locator('.vbh-reimb-missing')).toHaveCount(0)
		await dialog.getByRole('button', { name: 'Einreichen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const row = section.locator('tr.vbh-reimb-row', { hasText: 'Fahrt zum Landesturnier' })
		await expect(row).toContainText('Reisekosten')
		await expect(row).toContainText('63,20')

		const own = await selfData(request)
		const claim = own.items.find((i) => i.purpose === 'Fahrt zum Landesturnier')
		expect(claim).toMatchObject({ kind: 'travel', amountCents: 6320, expenseDate: DAY, invoiceNumber: null })
		expect(claim.travel).toMatchObject({
			destination: 'Landesturnier Kassel',
			start: `${DAY}T07:30`,
			items: [
				{ type: 'travel', transport: 'car', route: 'Vereinsheim – Kassel und zurück', distanceKm: 184, amountCents: 5520 },
				{ type: 'parking', note: 'Parkhaus Messe', amountCents: 800 },
			],
		})

		await row.click()
		const detail = page.getByRole('dialog', { name: /Reisekosten vom/ })
		await expect(detail).toContainText('Landesturnier Kassel')
		await expect(detail).toContainText('Eigenes Auto · Vereinsheim – Kassel und zurück · 184 km')
		await expect(detail).toContainText('Keine Belege eingereicht.')
	})

	test('die Verwaltung sieht die Positionen; eine Korrektur lässt sie stehen', async ({ page, request }) => {
		const claimId = (await selfData(request)).items.find((i) => i.kind === 'travel').id

		await openApp(page, USERS.verwalter, { booted: true })
		await switchTab(page, 'Buchungen')
		const subtab = visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' })
		await subtab.click()
		const row = visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Fahrt zum Landesturnier' })
		await expect(row).toContainText('Reisekosten')
		await row.click()
		const detail = page.getByRole('dialog', { name: /Reisekosten AUSL-\d{4}-\d{4} von Rita Reise/ })
		await expect(detail.getByRole('region', { name: 'Reiseangaben' })).toContainText('Parkhaus Messe')
		await expect(detail).not.toContainText('Rechnungsdatum')

		await detail.getByRole('button', { name: 'Betrag korrigieren' }).click()
		const dialog = page.getByRole('dialog', { name: 'Betrag korrigieren' })
		await dialog.getByLabel('Neuer Betrag').fill('55,20')
		await dialog.getByLabel('Begründung (Pflicht)').fill('Parken im Vereinsbus inklusive')
		await dialog.getByRole('button', { name: 'Korrigieren', exact: true }).click()
		await expect(dialog).toBeHidden()

		await expect(detail).toContainText('beantragt: 63,20')
		await expect(detail).toContainText('Die Positionen stehen so, wie sie eingereicht wurden')
		const staff = await (await api.getReimbursement(request, claimId)).json()
		expect(staff.amountCents).toBe(5520)
		expect(staff.travel.items[1].amountCents).toBe(800)
	})

	test('ein Reisekosten-Entwurf behält Art und Positionen', async ({ page, request }) => {
		const section = await openOwnArea(page)
		await section.getByRole('button', { name: 'Reisekosten abrechnen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Reisekosten abrechnen' })
		await dialog.getByLabel('Reiseziel').fill('Trainerlehrgang Hannover')
		await dialog.getByRole('group', { name: 'Fahrtkosten, Position 1' }).getByLabel('Betrag (€)').fill('42')
		await dialog.getByRole('button', { name: 'Als Entwurf speichern' }).click()
		await expect(dialog).toBeHidden()

		const drafts = section.getByRole('region', { name: 'Entwürfe' })
		await expect(drafts).toContainText('Reisekosten')
		await expect(drafts).toContainText('42,00')
		const draft = (await selfData(request)).drafts[0]
		expect(draft).toMatchObject({ kind: 'travel', amountCents: 4200 })
		expect(draft.travel.destination).toBe('Trainerlehrgang Hannover')

		await drafts.getByRole('button').first().click()
		const edit = page.getByRole('dialog', { name: 'Entwurf bearbeiten' })
		await expect(edit.getByLabel('Reiseziel')).toHaveValue('Trainerlehrgang Hannover')
		await expect(edit.getByRole('group', { name: 'Fahrtkosten, Position 1' }).getByLabel('Betrag (€)')).toHaveValue(/42/)
		await edit.getByRole('button', { name: 'Entwurf löschen' }).click()
		await edit.getByRole('button', { name: 'Entwurf wirklich löschen' }).click()
		await expect(edit).toBeHidden()
	})

	test('der Server prüft die Reiseangaben selbst', async ({ request }) => {
		const ok = await submitTravel(request, travel())
		expect(ok.status()).toBe(201)
		// Der Betrag kommt aus den Positionen, nicht aus dem Formularfeld.
		expect((await ok.json()).amountCents).toBe(4480)
		const extra = await submitTravel(request, travel(), { amount: '999.00' })
		expect((await extra.json()).amountCents).toBe(4480)

		const reversed = await submitTravel(request, travel({ end: `${DAY}T06:00` }))
		expect(reversed.status()).toBe(400)
		expect(JSON.stringify(await reversed.json())).toContain('vor ihrem Beginn')

		const noItems = await submitTravel(request, travel({ items: [] }))
		expect(noItems.status()).toBe(400)

		const hotel = await submitTravel(request, travel({ items: [{ type: 'hotel', amountCents: 9000 }] }))
		expect(hotel.status()).toBe(400)

		const broken = await api.raw(request, 'POST', '/self/reimbursements', {
			user: USERS.ohneRolle,
			expectOk: false,
			multipart: { kind: 'travel', travel: '{kaputt', purpose: 'x', iban: IBAN.replace(/ /g, ''), declaration: '1' },
		})
		expect(broken.status()).toBe(400)

		// Eine gewöhnliche Auslage braucht weiterhin einen Beleg.
		const noReceipt = await api.raw(request, 'POST', '/self/reimbursements', {
			user: USERS.ohneRolle,
			expectOk: false,
			multipart: { amount: '12.50', expenseDate: DAY, purpose: 'Bälle', iban: IBAN.replace(/ /g, ''), declaration: '1' },
		})
		expect(noReceipt.status()).toBe(400)
	})
})
