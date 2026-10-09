import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, tabButton, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Einzug-Unterreiter, Segment „Forderungen“ (Issue #104, Spec §3.6/§6): alle
// Forderungen mit abgeleitetem Zustand, Störfällen und Mahnstand; Erledigungs-
// vermerk (bezahlt, Erlass), Stundung und Storno ab `buchhalter`, lesend ab
// `revisor`.
//
// Der Bestand ist NICHT leer: `api.resetBook()` räumt Buchungen, Konten, offene
// Posten (Forderungen) und alles, was daran hängt (Läufe, Mahnversand; Issue
// #123), nicht aber Mitglieder, Mandate und Zuweisungen früherer Specs. Jeder
// Test legt deshalb Mitglieder mit eindeutigen Namen an (find-or-create), seedet seine Forderungen selbst
// (beforeEach räumt sie weg) und sucht in der Oberfläche gezielt nach ihnen.
// Datumsangaben sind relativ zu heute (der Server rechnet mit dem echten
// Kalendertag, es gibt keine Zeitreise).
//
// Rücklastschriften lassen sich hier nicht seeden (dafür bräuchte es einen
// Kontoauszug mit Rückgabegrund und bestätigter Zuordnung); der Klartext-Grund
// samt Rückgabecode-Sichtbarkeit steckt in den PHPUnit-Tests des Dienstes
// (ClaimOverviewServiceTest). Mahnstufe 0 lässt sich dagegen über den
// Tageslauf des Mahnwesens erzeugen – die Stufen 1 und 2 bräuchten Zeitreise.

const IBAN = 'DE02120300000000202051'

const iso = (date) => date.toISOString().slice(0, 10)
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const germanDate = (isoDate) => isoDate.split('-').reverse().join('.')

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

async function ensureMember(request, firstName, lastName) {
	const displayName = `${firstName} ${lastName}`
	const existing = (await api.listMembers(request)).find((m) => m.displayName === displayName)
	return existing ?? api.createMember(request, { firstName, lastName, email: `${firstName}.${lastName}@example.org`.toLowerCase() })
}

/** Mitglied mit aktivem Mandat (find-or-create) – seine Forderungen sind einzugsfähig. */
async function ensureMemberWithMandate(request, firstName, lastName) {
	const member = await ensureMember(request, firstName, lastName)
	const mandates = await api.mandatesByMember(request, member.id)
	if (!mandates.some((m) => m.status === 'aktiv')) {
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: IBAN, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
	}
	return member
}

async function createClaim(request, memberId, dueDate, { amount = 12.5, label = 'Testbeitrag Forderungen', type = 'beitrag' } = {}) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type, amount, label, dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

/** Freigeben und einreichen über die API – die Oberfläche dafür baut Ticket #103. */
async function releaseAndSubmit(request, dueDate) {
	const released = await api.raw(request, 'POST', '/debit-batches', { data: { dueDate } })
	expect(released.ok()).toBeTruthy()
	const batch = await released.json()
	const submitted = await api.raw(request, 'POST', `/debit-batches/${batch.id}/submit`)
	expect(submitted.ok()).toBeTruthy()
	return batch
}

/** Öffnet Beiträge → Einzug → Forderungen und liefert den Bereich des Segments. */
async function openClaims(page, user = USERS.buchhalter) {
	await openApp(page, user)
	await switchTab(page, 'Beiträge')
	await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
	await visibleSection(page).getByRole('tab', { name: 'Forderungen', exact: true }).click()
	const panel = visibleSection(page).getByRole('tabpanel', { name: 'Forderungen' })
	await expect(panel.getByRole('heading', { name: 'Forderungen', exact: true })).toBeVisible()
	await expect(panel.getByText(/\d+ von \d+ Forderungen, zusammen/)).toBeVisible()
	return panel
}

/** Die Zeile einer Forderung – nicht ihr aufgeklapptes Detail, das denselben Namen trägt. */
function rowOf(panel, text) {
	return panel.locator('tbody tr', { hasText: text }).first()
}

async function openDetails(panel, text) {
	await rowOf(panel, text).getByRole('button', { name: /^Details zur Forderung/ }).click()
	const detail = panel.locator('.vbh-claimdetail')
	await expect(detail).toBeVisible()
	return detail
}

test.describe('Einzug-Unterreiter: Segment „Forderungen“', () => {
	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('die Liste zeigt Zustand, Fälligkeit, Betrag, Art und Mitglied; Filter grenzen ein', async ({ page, request }) => {
		const early = plusDays(20)
		const late = plusDays(40)
		const one = await ensureMember(request, 'Forderungen', 'Listeeins')
		const two = await ensureMember(request, 'Forderungen', 'Listezwei')
		const three = await ensureMember(request, 'Forderungen', 'Listedrei')
		await createClaim(request, one.id, early, { amount: 12.5, label: 'Beitrag Eins' })
		await createClaim(request, two.id, late, { amount: 7, label: 'Gebühr Zwei', type: 'gebuehr' })
		const settled = await createClaim(request, three.id, early, { label: 'Beitrag Drei' })
		expect((await api.raw(request, 'POST', `/claims/${settled.id}/settle`, { data: { settlementType: 'paid', note: 'bar' } })).ok()).toBeTruthy()

		const panel = await openClaims(page)

		// Vorgabe: was noch aussteht – die erledigte Forderung fehlt.
		const first = rowOf(panel, 'Forderungen Listeeins')
		await expect(first).toContainText('Beitrag Eins')
		// Die Art steht nur bei Abweichung vom Normalfall: ein Beitrag trägt kein Etikett, eine Gebühr schon (unten).
		await expect(first.locator('.vbh-typetag')).toHaveCount(0)
		await expect(first).toContainText(germanDate(early))
		await expect(first).toContainText(/12,50\s*€/)
		await expect(first.getByText('offen', { exact: true })).toBeVisible()
		const second = rowOf(panel, 'Forderungen Listezwei')
		await expect(second.locator('.vbh-typetag', { hasText: 'Gebühr' })).toBeVisible()
		await expect(second).toContainText(/7,00\s*€/)
		await expect(rowOf(panel, 'Forderungen Listedrei')).toHaveCount(0)

		// Mitglied
		await panel.getByLabel('Mitglied', { exact: true }).fill('Listezwei')
		await expect(second).toBeVisible()
		await expect(first).toHaveCount(0)
		await panel.getByRole('button', { name: 'Filter zurücksetzen' }).click()
		await expect(first).toBeVisible()

		// Zeitraum (Fälligkeit, beide Grenzen einschließlich)
		await panel.getByLabel('Fällig von').fill(plusDays(30))
		await expect(second).toBeVisible()
		await expect(first).toHaveCount(0)
		await panel.getByLabel('Fällig von').fill('')
		await panel.getByLabel('Fällig bis').fill(plusDays(30))
		await expect(first).toBeVisible()
		await expect(second).toHaveCount(0)
		await panel.getByRole('button', { name: 'Filter zurücksetzen' }).click()

		// Zustand: erledigt zeigt die bezahlte, „Alle“ beide Sorten
		await panel.getByLabel('Zustand', { exact: true }).selectOption('erledigt')
		await expect(rowOf(panel, 'Forderungen Listedrei').getByText('erledigt (bezahlt)')).toBeVisible()
		await expect(first).toHaveCount(0)
		await panel.getByLabel('Zustand', { exact: true }).selectOption('alle')
		await expect(first).toBeVisible()
		await expect(rowOf(panel, 'Forderungen Listedrei')).toBeVisible()
	})

	test('als bezahlt markieren: Notiz, Vermerk mit Urheber, Zustand erledigt (bezahlt)', async ({ page, request }) => {
		const member = await ensureMember(request, 'Forderungen', 'Bezahlt')
		const claim = await createClaim(request, member.id, plusDays(20), { label: 'Beitrag Bezahlt' })

		const panel = await openClaims(page)
		const detail = await openDetails(panel, 'Forderungen Bezahlt')
		await detail.getByRole('button', { name: 'Als bezahlt markieren' }).click()
		await expect(detail).toContainText('Eine Buchung entsteht dadurch nicht')
		await detail.getByLabel('Notiz (optional)').fill('Bar am Vereinsabend')
		await detail.getByRole('button', { name: 'Als bezahlt vermerken' }).click()
		await expect(page.getByText('Forderung als bezahlt vermerkt.').first()).toBeVisible()

		// Aus der Vorgabeliste verschwunden, unter „erledigt“ zu finden
		await expect(rowOf(panel, 'Forderungen Bezahlt')).toHaveCount(0)
		await panel.getByLabel('Zustand', { exact: true }).selectOption('erledigt')
		const row = rowOf(panel, 'Forderungen Bezahlt')
		await expect(row.getByText('erledigt (bezahlt)')).toBeVisible()
		const done = await openDetails(panel, 'Forderungen Bezahlt')
		await expect(done.getByRole('heading', { name: 'Als bezahlt vermerkt', exact: true })).toBeVisible()
		await expect(done).toContainText(/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/)
		await expect(done).toContainText('Bar am Vereinsabend')
		// Nichts mehr zu tun an einer erledigten Forderung
		await expect(done.getByRole('button')).toHaveCount(0)

		// Der Urheber steht im Datensatz (Spec §3.6 „Datum, Urheber, Notiz“)
		const claims = await api.getJson(request, '/claims')
		const stored = claims.find((c) => c.id === claim.id)
		expect(stored.status).toBe('paid')
		expect(stored.settledBy).toBe(USERS.buchhalter)
	})

	test('Erlass verlangt eine Begründung und grenzt sich im Text vom Storno ab', async ({ page, request }) => {
		const member = await ensureMember(request, 'Forderungen', 'Erlass')
		await createClaim(request, member.id, plusDays(20), { label: 'Beitrag Erlass' })

		const panel = await openClaims(page)
		const detail = await openDetails(panel, 'Forderungen Erlass')
		await detail.getByRole('button', { name: 'Erlassen', exact: true }).click()

		const form = detail.locator('form')
		await expect(form).toContainText('Die Forderung war berechtigt, wir verzichten aber darauf')
		await expect(form).toContainText('jederzeit möglich, auch nach der Einreichung')
		await expect(form).toContainText('ist es kein Erlass, sondern ein Storno – das geht nur vor der Einreichung')
		const submit = form.getByRole('button', { name: 'Erlass vermerken' })
		await expect(submit).toBeDisabled()
		await form.getByLabel('Begründung (Pflicht)').fill('Härtefall laut Vorstandsbeschluss')
		await expect(submit).toBeEnabled()
		await submit.click()
		await expect(page.getByText('Erlass vermerkt.').first()).toBeVisible()

		await panel.getByLabel('Zustand', { exact: true }).selectOption('erledigt')
		await expect(rowOf(panel, 'Forderungen Erlass').getByText('erledigt (erlassen)')).toBeVisible()
		const done = await openDetails(panel, 'Forderungen Erlass')
		await expect(done.getByRole('heading', { name: 'Erlassen', exact: true })).toBeVisible()
		await expect(done).toContainText('Härtefall laut Vorstandsbeschluss')
	})

	test('Stundung setzen (Pflichtangaben, nur eine aktive) und aufheben', async ({ page, request }) => {
		const member = await ensureMember(request, 'Forderungen', 'Stundung')
		const claim = await createClaim(request, member.id, plusDays(20), { label: 'Beitrag Stundung' })
		const until = plusDays(60)

		const panel = await openClaims(page)
		const detail = await openDetails(panel, 'Forderungen Stundung')
		await detail.getByRole('button', { name: 'Stunden', exact: true }).click()
		const form = detail.locator('form')
		const submit = form.getByRole('button', { name: 'Stundung setzen' })
		await expect(submit).toBeDisabled()
		await form.getByLabel('Gestundet bis').fill(until)
		await expect(submit).toBeDisabled() // Datum allein reicht nicht – die Begründung ist Pflicht
		await form.getByLabel('Begründung (Pflicht)').fill('Ratenzahlung telefonisch vereinbart')
		await submit.click()
		await expect(page.getByText('Stundung gesetzt.').first()).toBeVisible()

		// Gekennzeichnet in der Liste, im Detail statt einer zweiten Stundung „aufheben“
		await expect(rowOf(panel, 'Forderungen Stundung').getByText(`gestundet bis ${germanDate(until)}`)).toBeVisible()
		await expect(detail.getByRole('button', { name: 'Stunden', exact: true })).toHaveCount(0)
		await expect(detail.getByRole('button', { name: 'Stundung aufheben' })).toBeVisible()
		await expect(detail).toContainText('Ratenzahlung telefonisch vereinbart')

		// Höchstens eine aktive Stundung je Forderung – auch der Server lehnt eine zweite ab.
		const second = await api.raw(request, 'POST', `/claims/${claim.id}/defer`, { data: { deferredUntil: plusDays(90), reason: 'Zweite Stundung' } })
		expect(second.status()).toBe(400)

		// Aufheben (mit Rückfrage), danach ist wieder eine Stundung möglich
		await detail.getByRole('button', { name: 'Stundung aufheben' }).click()
		await page.getByRole('dialog').getByRole('button', { name: 'Stundung aufheben', exact: true }).click()
		await expect(page.getByText('Stundung aufgehoben.').first()).toBeVisible()
		await expect(rowOf(panel, 'Forderungen Stundung').getByText(/gestundet bis/)).toHaveCount(0)
		await expect(detail.getByRole('button', { name: 'Stunden', exact: true })).toBeVisible()
	})

	test('Storno vor der Einreichung mit Begründung; nach der Einreichung gesperrt, der Erlass bleibt', async ({ page, request }) => {
		const cancelMember = await ensureMember(request, 'Forderungen', 'Storno')
		await createClaim(request, cancelMember.id, plusDays(20), { label: 'Beitrag Storno' })
		const submittedMember = await ensureMemberWithMandate(request, 'Forderungen', 'Eingereicht')
		const submittedClaim = await createClaim(request, submittedMember.id, plusDays(41), { label: 'Beitrag Eingereicht' })
		await releaseAndSubmit(request, plusDays(41))

		const panel = await openClaims(page)

		// Vor der Einreichung: Storno mit Pflicht-Begründung, Text trennt es vom Erlass
		const detail = await openDetails(panel, 'Forderungen Storno')
		await detail.getByRole('button', { name: 'Stornieren', exact: true }).click()
		const form = detail.locator('form')
		await expect(form).toContainText('Die Forderung hätte nie existieren dürfen')
		await expect(form).toContainText('vermerken Sie stattdessen einen Erlass')
		const submit = form.getByRole('button', { name: 'Stornieren', exact: true })
		await expect(submit).toBeDisabled()
		await form.getByLabel('Begründung (Pflicht)').fill('Doppelt angelegt')
		await submit.click()
		await expect(page.getByText('Forderung storniert.').first()).toBeVisible()
		await panel.getByLabel('Zustand', { exact: true }).selectOption('storniert')
		await expect(rowOf(panel, 'Forderungen Storno').getByText('storniert', { exact: true })).toBeVisible()
		const cancelled = await openDetails(panel, 'Forderungen Storno')
		await expect(cancelled).toContainText('Doppelt angelegt')
		await panel.getByRole('button', { name: 'Filter zurücksetzen' }).click()

		// Im eingereichten Lauf: „im Einzug“, kein Storno-Knopf, dafür der Hinweis – und der Erlass
		const row = rowOf(panel, 'Forderungen Eingereicht')
		await expect(row.getByText('im Einzug', { exact: true })).toBeVisible()
		const locked = await openDetails(panel, 'Forderungen Eingereicht')
		await expect(locked.getByRole('button', { name: 'Stornieren', exact: true })).toHaveCount(0)
		await expect(locked).toContainText('Storno ist nur vor der Einreichung möglich')
		await expect(locked.getByRole('button', { name: 'Erlassen', exact: true })).toBeVisible()

		// Der Server sagt dasselbe
		const refused = await api.raw(request, 'POST', `/claims/${submittedClaim.id}/cancel`, { data: { reason: 'Zu spät' } })
		expect(refused.status()).toBe(400)
		expect((await refused.json()).message).toContain('eingereicht')
	})

	test('Störfall: Schweregrad, Ursache in Klartext, Filter und Sprung in die Mitglieder-Akte', async ({ page, request }) => {
		// Fällig in zwei Tagen: die Vorabinfo-Frist ist verstrichen, ohne dass eine ging – Handlungsbedarf.
		const troubled = await ensureMemberWithMandate(request, 'Forderungen', 'Stoerfall')
		await createClaim(request, troubled.id, plusDays(2), { label: 'Beitrag Stoerfall' })
		// Ohne Störfall: weit genug in der Zukunft.
		const calm = await ensureMemberWithMandate(request, 'Forderungen', 'Ruhig')
		await createClaim(request, calm.id, plusDays(60), { label: 'Beitrag Ruhig' })

		const panel = await openClaims(page)
		const troubledRow = rowOf(panel, 'Forderungen Stoerfall')
		const calmRow = rowOf(panel, 'Forderungen Ruhig')
		await expect(troubledRow.getByText('Handlungsbedarf', { exact: true })).toBeVisible()
		await expect(calmRow.getByText('Handlungsbedarf', { exact: true })).toHaveCount(0)

		await panel.getByLabel('Störfall', { exact: true }).selectOption('handlungsbedarf')
		await expect(troubledRow).toBeVisible()
		await expect(calmRow).toHaveCount(0)
		await panel.getByLabel('Störfall', { exact: true }).selectOption('ohne')
		await expect(calmRow).toBeVisible()
		await expect(troubledRow).toHaveCount(0)
		await panel.getByLabel('Störfall', { exact: true }).selectOption('alle')

		const detail = await openDetails(panel, 'Forderungen Stoerfall')
		await expect(detail.getByRole('heading', { name: 'Störfälle', exact: true })).toBeVisible()
		await expect(detail).toContainText(/Vorabinfo für Forderungen Stoerfall .* konnte nicht rechtzeitig verschickt werden/)
		await expect(detail).toContainText('blockieren nichts')

		// Sprung in die Akte: derselbe Mechanismus wie im Aufgaben-Flyout
		await detail.getByRole('button', { name: 'Mitglieder-Akte öffnen' }).click()
		await expect(page.getByRole('dialog').getByRole('heading', { name: 'Mitglied: Forderungen Stoerfall' })).toBeVisible()
	})

	test('Einzelforderung anlegen: die vorhandene Erfassung aus den Beitragsgruppen', async ({ page, request }) => {
		const firstName = 'Einzelsegment'
		await ensureMember(request, firstName, 'Forderung')

		const panel = await openClaims(page)
		await panel.getByRole('button', { name: '+ Einzelforderung' }).click()
		const dialog = page.getByRole('dialog', { name: 'Manuelle Einzelforderung' })
		await expect(dialog).toBeVisible()
		const memberSelect = dialog.getByRole('combobox', { name: 'Mitglied' })
		await memberSelect.click()
		await memberSelect.pressSequentially(firstName, { delay: 20 })
		await page.locator('li.vs__dropdown-option', { hasText: firstName }).first().waitFor()
		await memberSelect.press('Enter')
		await dialog.getByLabel('Betrag (€)').fill('15')
		await dialog.getByLabel('Bezeichnung').fill('Nachzahlung im Segment')
		// Ein Doppelklick legt genau eine Forderung an (Testprotokoll 16.2).
		await dialog.getByRole('button', { name: 'Anlegen', exact: true }).dblclick()
		await expect(dialog).toBeHidden()

		const row = rowOf(panel, 'Nachzahlung im Segment')
		await expect(row).toBeVisible()
		await expect(panel.locator('tbody tr', { hasText: 'Nachzahlung im Segment' })).toHaveCount(1)
		await expect(row).toContainText('Einzelsegment Forderung')
		await expect(row).toContainText(/15,00\s*€/)
		await expect(row.getByText('offen', { exact: true })).toBeVisible()
	})

	test('Einzelforderung für mehrere Mitglieder auf einmal', async ({ page, request }) => {
		await ensureMember(request, 'Mehrfach', 'Eins')
		await ensureMember(request, 'Mehrfach', 'Zwei')

		const panel = await openClaims(page)
		await panel.getByRole('button', { name: '+ Einzelforderung' }).click()
		const dialog = page.getByRole('dialog', { name: 'Manuelle Einzelforderung' })
		await expect(dialog).toBeVisible()
		// Die Mehrfachauswahl hat keinen verlässlichen Rollennamen mehr (die gewählten Mitglieder stehen als Marken
		// im selben Feld): das Suchfeld der NcSelect ist das einzige seiner Art im Dialog.
		const memberSelect = dialog.locator('.nc-select input').first()
		for (const name of ['Mehrfach Eins', 'Mehrfach Zwei']) {
			await memberSelect.click()
			await memberSelect.pressSequentially(name, { delay: 20 })
			await page.locator('li.vs__dropdown-option', { hasText: name }).first().waitFor()
			await memberSelect.press('Enter')
		}
		await dialog.getByLabel('Betrag (€)').fill('7')
		await dialog.getByLabel('Bezeichnung').fill('Sammelforderung Mehrfach')
		// Der Knopf nennt die Zahl der Mitglieder, für die etwas entsteht.
		await dialog.getByRole('button', { name: 'Für 2 Mitglieder anlegen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const rows = panel.locator('tbody tr', { hasText: 'Sammelforderung Mehrfach' })
		await expect(rows).toHaveCount(2)
		await expect(rows.filter({ hasText: 'Mehrfach Eins' })).toHaveCount(1)
		await expect(rows.filter({ hasText: 'Mehrfach Zwei' })).toHaveCount(1)
		await expect(rows.first()).toContainText(/7,00\s*€/)
	})

	test('Revisor liest die Forderungen, ohne Aktionen und ohne Akte', async ({ page, request }) => {
		const member = await ensureMemberWithMandate(request, 'Forderungen', 'Revisor')
		const claim = await createClaim(request, member.id, plusDays(2), { label: 'Beitrag Revisor' })

		await openApp(page, USERS.revisor)
		// Der Willkommenshinweis für Kassenprüfer erscheint je Browserprofil einmal (siehe 08-roles).
		await expect(page.getByText('Willkommen als Kassenprüfer/in')).toBeVisible()
		await page.getByRole('button', { name: 'Verstanden' }).click()
		await switchTab(page, 'Beiträge')
		await expect(tabButton(page, 'Beiträge')).toBeVisible()
		await visibleSection(page).getByRole('tab', { name: 'Forderungen', exact: true }).click()
		const panel = visibleSection(page).getByRole('tabpanel', { name: 'Forderungen' })
		await expect(rowOf(panel, 'Forderungen Revisor')).toBeVisible()
		await expect(panel.getByRole('button', { name: '+ Einzelforderung' })).toHaveCount(0)

		const detail = await openDetails(panel, 'Forderungen Revisor')
		await expect(detail.getByRole('heading', { name: 'Störfälle', exact: true })).toBeVisible()
		// Keine Schreibaktion und kein Sprung in die Akte (Mitglieder: ab Buchhalter)
		await expect(detail.getByRole('button')).toHaveCount(0)
		await panel.getByRole('button', { name: 'Je Mitglied' }).click()
		await expect(panel.getByRole('button', { name: 'Akte öffnen' })).toHaveCount(0)

		// Dasselbe an der API: lesen ja, schreiben nein, ohne Rolle gar nichts
		const overview = await api.raw(request, 'GET', '/claims/overview', { user: USERS.revisor })
		expect(overview.ok()).toBeTruthy()
		for (const [path, data] of [
			[`/claims/${claim.id}/settle`, { settlementType: 'paid' }],
			[`/claims/${claim.id}/cancel`, { reason: 'x' }],
			[`/claims/${claim.id}/defer`, { deferredUntil: plusDays(30), reason: 'x' }],
			[`/claims/${claim.id}/undefer`, {}],
		]) {
			expect((await api.raw(request, 'POST', path, { user: USERS.revisor, data })).status(), path).toBe(403)
		}
		expect((await api.raw(request, 'GET', '/claims/overview', { user: USERS.ohneRolle })).status()).toBe(403)
	})
})

// Der Testserver hat keinen Mailserver (NC-Standard: SMTP auf 127.0.0.1:25, Verbindung abgelehnt): ohne
// Zustellweg meldet IMailer::send() einen Fehlschlag, und das Mahnwesen vermerkt dann keine Stufe. Für diese
// Gruppe gilt deshalb der NC-Mail-Modus „null“ (Mails werden angenommen und verworfen) – danach wieder entfernt,
// config.php gehört nicht zum Datenbank-Snapshot (siehe 28-contribution-prenotification).
test.describe('Segment „Forderungen“: Mahnstand', () => {
	test.beforeAll(async () => {
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
	})

	test.afterAll(async () => {
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('die Zahlungsaufforderung geht raus, die nächste Stufe ist datiert; eine Stundung pausiert sie', async ({ page, request }) => {
		test.setTimeout(90000)
		// Ohne Mandat nie per Lastschrift: die Zahlungsaufforderung geht 14 Tage vor der Fälligkeit raus.
		const dueDate = plusDays(3)
		const member = await ensureMember(request, 'Forderungen', 'Mahnung')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Mahnung' })

		const panel = await openClaims(page)
		const row = rowOf(panel, 'Forderungen Mahnung')
		await expect(row).toContainText(`Zahlungsaufforderung ab ${germanDate(plusDays(3 - 14))}`)
		await expect(row.getByText('Zahlungsaufforderung', { exact: true })).toHaveCount(0)

		// Der tägliche Mahnlauf (wie in 28 per occ angestoßen, nicht in Echtzeit abgewartet)
		const container = getContainer()
		const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
		const job = JSON.parse(stdout).find((j) => (j.class || '').includes('DunningLadderJob'))
		expect(job, 'DunningLadderJob ist registriert').toBeTruthy()
		await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })

		await panel.getByRole('button', { name: 'Forderungen aktualisieren' }).click()
		await expect(row.getByText('Zahlungsaufforderung', { exact: true })).toBeVisible()
		await expect(row).toContainText(`versandt am ${germanDate(plusDays(0))}`)
		await expect(row).toContainText(`Zahlungserinnerung ab ${germanDate(plusDays(14))}`)

		// Das Detail zeigt die Mahnreihe: erreichte Stufe mit Versandzeitpunkt, die nächste mit Fälligkeit
		const detail = await openDetails(panel, 'Forderungen Mahnung')
		const steps = detail.locator('.vbh-dunstep')
		await expect(steps.filter({ hasText: 'Zahlungsaufforderung' })).toContainText('versandt am')
		await expect(steps.filter({ hasText: 'Zahlungserinnerung' })).toContainText(`fällig ab ${germanDate(plusDays(14))}`)
		await expect(steps.filter({ hasText: 'Mahnung' })).toContainText('noch nicht erreicht')
		await expect(steps.filter({ hasText: 'An Vorstand eskaliert' })).toContainText('noch nicht erreicht')

		// Je Mitglied gebündelt
		await panel.getByRole('button', { name: 'Je Mitglied' }).click()
		const memberRow = panel.locator('tbody tr', { hasText: 'Forderungen Mahnung' })
		await expect(memberRow).toContainText('Zahlungsaufforderung')
		await expect(memberRow).toContainText(`Zahlungserinnerung ab ${germanDate(plusDays(14))}`)
		await panel.getByRole('button', { name: 'Je Forderung' }).click()

		// Eine Stundung pausiert die Mahnuhr: frühestens am Tag nach ihrem Ende
		const until = plusDays(30)
		// Das Detail steht noch offen (der Wechsel der Darstellung klappt nichts zu).
		const open = panel.locator('.vbh-claimdetail')
		await expect(open).toBeVisible()
		await open.getByRole('button', { name: 'Stunden', exact: true }).click()
		await open.getByLabel('Gestundet bis').fill(until)
		await open.getByLabel('Begründung (Pflicht)').fill('Mahnuhr pausieren')
		await open.getByRole('button', { name: 'Stundung setzen' }).click()
		await expect(page.getByText('Stundung gesetzt.').first()).toBeVisible()
		await expect(rowOf(panel, 'Forderungen Mahnung')).toContainText(`Zahlungserinnerung ab ${germanDate(plusDays(31))}`)
		await expect(rowOf(panel, 'Forderungen Mahnung').getByText(`gestundet bis ${germanDate(until)}`)).toBeVisible()
	})
})

test.describe('Segment „Forderungen“ auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('die Forderungen stehen als Karten, nichts läuft seitlich aus dem Bild', async ({ page, request }) => {
		const member = await ensureMember(request, 'Forderungen', 'Handy')
		await createClaim(request, member.id, plusDays(20), { label: 'Beitrag Handy' })

		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
		await visibleSection(page).getByRole('tab', { name: 'Forderungen', exact: true }).click()

		const panel = visibleSection(page).getByRole('tabpanel', { name: 'Forderungen' })
		const card = panel.locator('.vbh-mcard', { hasText: 'Forderungen Handy' })
		await expect(card).toBeVisible()
		await expect(card).toContainText('Beitrag Handy')
		await card.getByRole('button', { name: 'Details anzeigen' }).click()
		await expect(card.getByRole('button', { name: 'Als bezahlt markieren' })).toBeVisible()

		// Der Abschnitt scrollt nicht seitlich: Karten statt Tabelle, nichts ragt über den Rand.
		const overflow = await visibleSection(page).locator('.vbh-sectionbody').evaluate((el) => el.scrollWidth - el.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)
	})
})
