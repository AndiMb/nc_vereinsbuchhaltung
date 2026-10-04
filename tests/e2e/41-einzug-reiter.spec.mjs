import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, tabButton, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Einzug-Unterreiter: Zeitstrahl, Geisterkarte und Läufe (Issue #102, Spec
// §3.5/§6 Variante A). Er löst im Beiträge-Reiter das Alt-Einzug-Panel ab und
// ist ab `revisor` lesend sichtbar (Spec §3.9, IBAN maskiert).
//
// Der Bestand ist NICHT leer: `api.resetBook()` räumt nur Buchungen, Konten und
// offene Posten (Forderungen), nicht aber Mitglieder, Mandate, Zuweisungen und
// bereits freigegebene Läufe früherer Specs. Die Tests legen deshalb ihre
// eigenen, eindeutig benannten Mitglieder an (find-or-create), seeden ihre
// Forderungen im Test selbst (beforeEach räumt sie weg) und suchen in der
// Oberfläche gezielt nach ihrem Termin/Namen, statt auf „der nächste Termin“
// zu zählen. Datumsangaben sind relativ zu heute (der Server rechnet mit dem
// echten Kalendertag, es gibt keine Zeitreise).
//
// Ein Marker auf dem Zeitstrahl wird per dispatchEvent('click') geklickt, nicht
// per Mausklick auf seine Mitte: liegt ein Termin nur ein paar Tage neben einem
// anderen, überdecken sich die Marker um wenige Pixel, und ein Mausklick träfe
// den falschen. Genau dafür gibt es die Knöpfe „Früherer/Späterer Termin“.

const GROUP_NAME = 'Testgruppe Einzug-Reiter'
const IBAN = 'DE02120300000000202051'
const IBAN_MASKED = /^DE02•+2051$/

const iso = (date) => date.toISOString().slice(0, 10)
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const germanDate = (isoDate) => isoDate.split('-').reverse().join('.')
const currentYear = () => new Date().getUTCFullYear()

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

/** Mitglied mit aktivem Mandat (find-or-create). */
async function ensureMemberWithMandate(request, firstName, lastName, iban = IBAN) {
	const member = await ensureMember(request, firstName, lastName)
	const mandates = await api.mandatesByMember(request, member.id)
	if (!mandates.some((m) => m.status === 'aktiv')) {
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
	}
	return member
}

async function createClaim(request, memberId, dueDate, { amount = 12.5, label = 'Testbeitrag Einzug-Reiter' } = {}) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type: 'beitrag', amount, label, dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

async function ensureGroup(request) {
	const groups = await api.getJson(request, '/contribution-groups')
	return groups.find((g) => g.name === GROUP_NAME) ?? (await api.raw(request, 'POST', '/contribution-groups', {
		data: { name: GROUP_NAME, minMonthlyAmount: 5, defaultMonthlyAmount: 10, allowedIntervals: [1, 3, 12], defaultInterval: 12, isActive: true },
	})).json()
}

/** Eine Zuweisung ab heute (find-or-create je Mitglied). */
async function ensureAssignment(request, member, group, { intervalMonths, paymentMethod = 'direct_debit' }) {
	const existing = await api.getJson(request, `/assignments?memberId=${member.id}`)
	if (existing.some((a) => a.groupId === group.id && a.active)) { return }
	const resp = await api.raw(request, 'POST', '/assignments', {
		data: { memberId: member.id, groupId: group.id, intervalMonths, monthlyAmount: 10, paymentMethod, validFrom: plusDays(0) },
	})
	expect(resp.ok()).toBeTruthy()
}

/** Freigeben und (optional) einreichen über die API – die Oberfläche dafür baut Ticket #103. */
async function releaseRun(request, dueDate, { submit = false } = {}) {
	const released = await api.raw(request, 'POST', '/debit-batches', { data: { dueDate } })
	expect(released.ok()).toBeTruthy()
	const batch = await released.json()
	if (submit) {
		const submitted = await api.raw(request, 'POST', `/debit-batches/${batch.id}/submit`)
		expect(submitted.ok()).toBeTruthy()
	}
	return batch
}

/** Öffnet Beiträge → Einzug (als Buchhalter). */
async function openEinzug(page) {
	await openApp(page, USERS.buchhalter)
	await switchTab(page, 'Beiträge')
	await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
	await expect(visibleSection(page).getByRole('tab', { name: 'Zeitstrahl & Läufe' })).toBeVisible()
}

/** Fällt der Termin ins Folgejahr (Ende Dezember), erst dorthin blättern. */
async function showYearOf(page, dueDate) {
	if (Number(dueDate.slice(0, 4)) > currentYear()) {
		await visibleSection(page).getByRole('button', { name: 'Nächstes Beitragsjahr' }).click()
	}
}

function marker(page, dueDate) {
	return visibleSection(page).getByRole('button', { name: new RegExp(`^Einzugstermin ${germanDate(dueDate).replaceAll('.', '\\.')}`) })
}

function ghostCard(page, dueDate) {
	return visibleSection(page).getByRole('region', { name: `Vorschau des Einzugs am ${germanDate(dueDate)}` })
}

test.describe('Einzug-Unterreiter: Zeitstrahl, Geisterkarte und Läufe', () => {
	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('Zeitstrahl zeigt HEUTE-Marker, Termin und Meilensteine; die Geisterkarte die Vorschau', async ({ page, request }) => {
		const dueDate = plusDays(10)
		const member = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Geisterkarte')
		await createClaim(request, member.id, dueDate)

		await openEinzug(page)
		const section = visibleSection(page)
		await expect(section.getByRole('heading', { name: /^Beitragsjahr / })).toBeVisible()
		await expect(section.locator('.vbh-tl-today')).toContainText('HEUTE')

		await showYearOf(page, dueDate)
		await marker(page, dueDate).dispatchEvent('click')
		await expect(marker(page, dueDate)).toHaveAttribute('aria-pressed', 'true')

		// Meilensteine des gewählten Termins (Spec §3.5 Zeitachse)
		for (const label of ['Vorwarnung', 'Vorabinfo', 'Freigabe-Vorlauf', 'Einzug']) {
			await expect(section.locator('.vbh-tl-milestone-item', { hasText: label })).toBeVisible()
		}

		// Geisterkarte: gekennzeichnet als Vorschau, mit Forderung und Summe
		const ghost = ghostCard(page, dueDate)
		await expect(ghost).toBeVisible()
		await expect(ghost.getByText('Vorschau', { exact: true })).toBeVisible()
		await expect(ghost).toContainText('Vor der Freigabe gibt es keinen Lauf')
		await expect(ghost).toContainText('Zeitstrahl Geisterkarte')
		await expect(ghost).toContainText(/12,50\s*€/)
	})

	test('eine gerissene Vorlauffrist erscheint nur als Hinweis, nichts wird gesperrt', async ({ page, request }) => {
		// Fällig in zwei Tagen: der Freigabe-Vorlauf (5 Tage) ist schon verstrichen.
		const dueDate = plusDays(2)
		const member = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Vorlauffrist')
		await createClaim(request, member.id, dueDate)

		await openEinzug(page)
		await showYearOf(page, dueDate)
		await marker(page, dueDate).dispatchEvent('click')

		const ghost = ghostCard(page, dueDate)
		await expect(ghost).toContainText(/Freigabe überfällig seit \d{2}\.\d{2}\.\d{4}/)
		await expect(ghost).toContainText('Das blockiert nichts')
	})

	test('Störfälle erscheinen in zwei Schweregraden', async ({ page, request }) => {
		const dueDate = plusDays(10)
		const group = await ensureGroup(request)
		// Handlungsbedarf: Zuweisung mit Lastschrift, aber ohne Mandat.
		const withoutMandate = await ensureMember(request, 'Zeitstrahl', 'Ohnemandat')
		await ensureAssignment(request, withoutMandate, group, { intervalMonths: 12 })
		// Hinweis: manuelle Forderung ohne einzugsfähiges Mandat (kommt nicht in den Lauf).
		await createClaim(request, withoutMandate.id, dueDate, { label: 'Manuelle Gebühr ohne Mandat' })

		await openEinzug(page)
		await showYearOf(page, dueDate)
		await marker(page, dueDate).dispatchEvent('click')

		const ghost = ghostCard(page, dueDate)
		await expect(ghost.getByRole('heading', { name: 'Störfälle zu diesem Termin' })).toBeVisible()
		const action = ghost.locator('li', { hasText: 'Zeitstrahl Ohnemandat hat eine Zuweisung mit Lastschrift' })
		await expect(action.getByText('Handlungsbedarf', { exact: true })).toBeVisible()
		const hint = ghost.locator('li', { hasText: 'kein einzugsfähiges Mandat und kommt nicht in den Lauf' })
		await expect(hint.getByText('Hinweis', { exact: true })).toBeVisible()
		await expect(ghost).toContainText('blockieren nichts')
	})

	test('der Terminplan ist aus dem Reiter erreichbar, eine Änderung rechnet den Zeitstrahl neu', async ({ page, request }) => {
		const group = await ensureGroup(request)
		const member = await ensureMember(request, 'Zeitstrahl', 'Monatlich')
		await ensureAssignment(request, member, group, { intervalMonths: 1 })

		try {
			await openEinzug(page)
			const section = visibleSection(page)
			// Monatsturnus, Standard-Einzugstag 0: die Termine liegen auf dem Ersten.
			await expect(marker(page, `${currentYear()}-01-01`)).toBeVisible()
			await expect(marker(page, `${currentYear()}-12-01`)).toBeVisible()
			await expect(marker(page, `${currentYear()}-01-15`)).toHaveCount(0)

			await section.getByRole('button', { name: 'Terminplan', exact: true }).click()
			const schedule = section.locator('#vbh-einzug-schedule')
			await expect(schedule.getByRole('heading', { name: 'Terminplan' })).toBeVisible()
			const monthlyRow = schedule.locator('tbody tr').first()
			await monthlyRow.locator('input[type="number"]').fill('14')
			await monthlyRow.getByRole('button', { name: 'Speichern' }).click()

			// Ohne neues Laden der Seite: die Termine rücken auf den 15.
			// (Der erste Januar bleibt als Termin anderer Turnusse im Bestand, deshalb kein Test auf sein Verschwinden.)
			await expect(marker(page, `${currentYear()}-01-15`)).toBeVisible()
			await expect(marker(page, `${currentYear()}-12-15`)).toBeVisible()
		} finally {
			// Die Einstellung gehört zur App-Config, die resetBook() nicht räumt.
			await api.raw(request, 'POST', '/due-date-schedule/1/default-day', { data: { offsetDays: 0 } })
		}
	})

	test('Läufe mit Status, Summe und Posten; die IBAN ist maskiert, die Forderung im Klartext', async ({ page, request }) => {
		const dueDate = plusDays(10)
		const submittedMember = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Eingereicht')
		await createClaim(request, submittedMember.id, dueDate, { label: 'Beitrag Lauf eingereicht' })
		await releaseRun(request, dueDate, { submit: true })

		const discardedMember = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Verworfen', 'DE44500105175407324931')
		const discardDate = plusDays(11)
		await createClaim(request, discardedMember.id, discardDate, { label: 'Beitrag Lauf verworfen' })
		const discarded = await releaseRun(request, discardDate)
		const discardResp = await api.raw(request, 'POST', `/debit-batches/${discarded.id}/discard`, { data: { reason: 'Falsches Datum gewählt' } })
		expect(discardResp.ok()).toBeTruthy()

		await openEinzug(page)
		const section = visibleSection(page)

		// Eingereichter Lauf: Zeile, Detail mit maskierter IBAN und abgeleitetem Forderungszustand
		const submittedRow = section.getByRole('row', { name: new RegExp(`^${germanDate(dueDate).replaceAll('.', '\\.')} eingereicht`) }).first()
		await expect(submittedRow).toBeVisible()
		await expect(submittedRow).toContainText(/12,50\s*€/)
		await submittedRow.getByRole('button', { name: /Details/ }).click()
		const detail = section.locator('.vbh-run-detailcell')
		await expect(detail).toContainText('Zeitstrahl Eingereicht')
		await expect(detail.getByText(IBAN_MASKED)).toBeVisible()
		await expect(detail.getByText(IBAN)).toHaveCount(0)
		// Der Termin liegt in der Zukunft: noch nicht eingezogen, aber im Einzug.
		await expect(detail.getByText('im Einzug', { exact: true })).toBeVisible()
		await expect(detail).toContainText('Kennung der Datei')

		// Das Datum steht auf dem Zeitstrahl als eingereicht (ein Lauf, nichts mehr freizugeben).
		await showYearOf(page, dueDate)
		await expect(marker(page, dueDate)).toHaveAttribute('aria-label', /Lauf eingereicht/)

		// Verworfener Lauf: Status, Begründung, Forderung wieder offen; die Historie bleibt sichtbar
		// (Der Name des Knopfes ist sein aria-label und bleibt gleich, der Zustand steht in aria-expanded.)
		await submittedRow.getByRole('button', { name: /Details/ }).click()
		const discardedRow = section.getByRole('row', { name: new RegExp(`^${germanDate(discardDate).replaceAll('.', '\\.')} verworfen`) }).first()
		await discardedRow.getByRole('button', { name: /Details/ }).click()
		await expect(section.locator('.vbh-run-detailcell')).toContainText('Falsches Datum gewählt')
		await expect(section.locator('.vbh-run-detailcell').getByText('offen', { exact: true })).toBeVisible()
	})

	test('Revisor sieht nur den Einzug-Unterreiter, lesend und mit maskierter IBAN', async ({ page, request }) => {
		const dueDate = plusDays(10)
		const member = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Revisor')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag für den Revisor' })
		await releaseRun(request, dueDate, { submit: true })

		await openApp(page, USERS.revisor)
		// Der Willkommenshinweis für Kassenprüfer erscheint je Browserprofil einmal (siehe 08-roles).
		await expect(page.getByText('Willkommen als Kassenprüfer/in')).toBeVisible()
		await page.getByRole('button', { name: 'Verstanden' }).click()
		// Der Beiträge-Reiter ist ab Revisor sichtbar …
		await expect(tabButton(page, 'Beiträge')).toBeVisible()
		await switchTab(page, 'Beiträge')

		const section = visibleSection(page)
		// … zeigt aber nur den Einzug (Mitglieder: Personenakte, ab Buchhalter; Beitragsgruppen ebenso).
		await expect(section.locator('.vbh-subtabs button')).toHaveCount(1)
		await expect(section.locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true })).toBeVisible()
		await expect(section.getByRole('heading', { name: /^Beitragsjahr / })).toBeVisible()
		await expect(section.getByRole('tab', { name: 'Zeitstrahl & Läufe' })).toBeVisible()

		// Keine Schreib-Werkzeuge: der Terminplan ist eine Einstellung der Buchhaltung.
		await expect(section.getByRole('button', { name: 'Terminplan', exact: true })).toHaveCount(0)

		const row = section.getByRole('row', { name: new RegExp(`^${germanDate(dueDate).replaceAll('.', '\\.')} eingereicht`) }).first()
		await row.getByRole('button', { name: /Details/ }).click()
		const detail = section.locator('.vbh-run-detailcell')
		await expect(detail.getByText(IBAN_MASKED)).toBeVisible()
		await expect(detail.getByText(IBAN)).toHaveCount(0)
		// Die Rücklastschrift-Codes (und alles Schreibende) stehen hier nicht.
		await expect(detail.getByRole('button')).toHaveCount(0)

		// Dasselbe an der API: lesen ja, schreiben nein, ohne Rolle gar nichts.
		const timeline = await api.raw(request, 'GET', '/debit-batches/timeline', { user: USERS.revisor })
		expect(timeline.ok()).toBeTruthy()
		const forbidden = await api.raw(request, 'GET', '/debit-batches/timeline', { user: USERS.ohneRolle })
		expect(forbidden.status()).toBe(403)
	})
})

test.describe('Einzug-Unterreiter auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('die Termine stehen als Liste mit HEUTE-Marke, nichts läuft seitlich aus dem Bild', async ({ page, request }) => {
		const dueDate = plusDays(10)
		const member = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Handy')
		await createClaim(request, member.id, dueDate)

		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()

		const section = visibleSection(page)
		await expect(section.locator('.vbh-tl-list')).toBeVisible()
		await expect(section.locator('.vbh-tl-list-today')).toContainText('HEUTE')

		await showYearOf(page, dueDate)
		await section.locator('.vbh-tl-row', { hasText: germanDate(dueDate) }).click()
		const ghost = ghostCard(page, dueDate)
		await expect(ghost).toBeVisible()
		await expect(ghost).toContainText('Zeitstrahl Handy')

		// Der Abschnitt scrollt nicht seitlich: die Liste ersetzt den Strahl, nichts ragt über den Rand.
		const overflow = await section.locator('.vbh-sectionbody').evaluate((el) => el.scrollWidth - el.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)
	})
})
