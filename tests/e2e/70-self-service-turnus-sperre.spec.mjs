import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, E2E_TIME_ZONE, openApp, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Turnuswechsel und Sperrfenster im Self-Service („Mein Beitrag“):
//
// 1. Eine Zuweisung, die erst künftig beginnt, lässt sich ändern. Bisher lehnte der
//    Self-Service das ab – mit dem Hinweis auf eine Vorabinfo, die es gar nicht gab:
//    die Änderung „wirkt ab“ dem Beginn der Zuweisung, und alles nach heute galt als gesperrt.
// 2. Ein Turnuswechsel, der Monate ohne Beitrag ließe, wird mit Erklärung abgelehnt. Die
//    Forderungserzeugung setzt im Raster des neuen Turnus fort; endet die Berechnung nicht an
//    einer Grenze dieses Rasters, blieb die Zeit bis dorthin ohne Forderung (monatlich →
//    jährlich im Februar: März bis Dezember kostenlos).
// 3. Die Sperre nach der Vorabinfo richtet sich nach der spätesten Vorabinfo. Eine frühere
//    Forderung ohne Vorabinfo (hier: schon als bezahlt vermerkt) hob sie bisher auf.
//
// Zeitreisen gibt es im E2E nicht. Die Fälle sind deshalb so gebaut, dass sie an jedem
// Kalendertag gelten; wo das Datum das Ergebnis bestimmt (Fall 2 im letzten Monat des
// Beitragsjahres), rechnet der Test die Erwartung aus dem Stand des Servers.

const IBAN = 'DE02120300000000202051'
const GROUP = { name: 'Turnus-Sperre E2E', min: 5, def: 10, intervals: [1, 3, 12], defaultInterval: 12 }
const LOCK_EXPLANATION = 'Für die laufende Periode wurde bereits eine Vorabinfo verschickt – Betrag und Turnus stehen bis zum Einzug fest.'
const GAP_EXPLANATION = 'Dieser Turnuswechsel ist im Moment nicht möglich'

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
/** JJJJ-MM-TT in der Vereinszone – der Server rechnet „heute“ dort (#263). */
const clubDate = (date) => new Intl.DateTimeFormat('sv-SE', { timeZone: E2E_TIME_ZONE }).format(date)
const today = () => clubDate(new Date())
const plusDays = (days) => clubDate(new Date(Date.now() + days * 86400000))
const german = (isoDate) => isoDate.split('-').reverse().join('.')
const nextDay = (isoDate) => new Date(Date.parse(`${isoDate}T00:00:00Z`) + 86400000).toISOString().slice(0, 10)
const daysUntil = (isoDate) => Math.round((Date.parse(`${isoDate}T00:00:00Z`) - Date.parse(`${today()}T00:00:00Z`)) / 86400000)
const unique = (base) => `${base}-${Math.random().toString(36).slice(2, 6)}`

async function unlinkAccount(request, uid) {
	for (const linked of (await api.listMembers(request)).filter((m) => m.ncUserId === uid)) {
		await api.unlinkMember(request, linked.id)
	}
}

/** Ein Mitglied, dem das Testkonto ab jetzt (allein) gehört. */
async function linkedMember(request, firstName) {
	const surname = unique('Turnus')
	const member = await api.createMember(request, { firstName, lastName: surname, email: `${firstName}.${surname}@example.org`.toLowerCase() })
	await unlinkAccount(request, USERS.ohneRolle)
	await api.linkMember(request, member.id, USERS.ohneRolle)
	return member
}

async function activeMandate(request, member) {
	const mandate = await (await api.createMandate(request, { memberId: member.id, iban: IBAN, signedAt: today() })).json()
	await api.activateMandate(request, mandate.id)
}

async function createAssignment(request, data) {
	const resp = await api.raw(request, 'POST', '/assignments', { data: { monthlyAmount: 10, validFrom: today(), ...data } })
	expect(resp.status(), 'Zuweisung anlegen').toBe(201)
	return resp.json()
}

async function runCycleJob() {
	const container = getContainer()
	const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
	const job = JSON.parse(stdout).find((j) => (j.class || '').includes('ContributionDueCycleJob'))
	expect(job, 'ContributionDueCycleJob ist registriert').toBeTruthy()
	await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })
}

/** Die Forderungen einer Zuweisung, nach Periodenbeginn aufsteigend. */
async function claimsOf(request, assignmentId) {
	return (await api.getJson(request, '/claims'))
		.filter((claim) => claim.assignmentId === assignmentId)
		.sort((a, b) => String(a.periodStart).localeCompare(String(b.periodStart)))
}

async function readLeadDays(request) {
	const { warningLeadDays, prenotificationLeadDays } = await api.getJson(request, '/due-date-schedule')
	return { warningLeadDays, prenotificationLeadDays }
}

async function writeLeadDays(request, values) {
	const resp = await api.raw(request, 'POST', '/due-date-schedule/lead-days', { data: values })
	expect(resp.ok(), 'Vorlaufzeiten setzen').toBeTruthy()
}

async function ownAssignment(request, id) {
	return (await api.selfAssignments(request, { user: USERS.ohneRolle })).find((a) => a.id === id)
}

/** Öffnet „Mein Beitrag“ als Mitglied und liefert die Karte der Zuweisung. */
async function openCard(page) {
	await openApp(page, USERS.ohneRolle)
	await tabButton(page, 'Mein Bereich').click()
	const section = visibleSection(page)
	await expect(section.getByRole('heading', { name: 'Meine Stammdaten', exact: true })).toBeVisible()
	const card = section.locator('.vbh-selfservice-assignment', { hasText: GROUP.name })
	await expect(card).toBeVisible()
	return card
}

/** Der Fehler-Toast steht doppelt im DOM (Toast und Ansage für Bildschirmleser); der exakte Text trifft nur den Toast. */
const toast = (page, text) => page.getByText(text, { exact: true })

test.describe('Self-Service: Turnuswechsel und Sperrfenster', () => {
	let group
	let leadBefore

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		// Ohne Mailweg vermerkt der Tageslauf keine Vorabinfo (siehe 28): Mails annehmen und verwerfen.
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
		await sleep(4000) // opcache: der erste Web-Request sähe sonst noch den alten Mail-Modus

		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)
		await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
		await api.updateSettings(request, {
			membership_enabled: '1',
			self_service_enabled: '1',
			club_name: 'Testverein e.V.',
			sepa_creditor_id: 'DE98ZZZ09999999999',
			sepa_debtor_account_id: bank.id,
			fiscal_year_start_month: 1,
		})
		leadBefore = await readLeadDays(request)
		const resp = await api.raw(request, 'POST', '/contribution-groups', {
			data: { name: GROUP.name, minMonthlyAmount: GROUP.min, defaultMonthlyAmount: GROUP.def, allowedIntervals: GROUP.intervals, defaultInterval: GROUP.defaultInterval, isActive: true },
		})
		expect(resp.status(), 'Beitragsgruppe anlegen').toBe(201)
		group = await resp.json()
	})

	test.afterAll(async ({ request }) => {
		if (leadBefore) {
			await writeLeadDays(request, leadBefore)
		}
		await unlinkAccount(request, USERS.ohneRolle)
		await api.updateSettings(request, { self_service_enabled: '0', membership_enabled: '0' })
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	test('eine Zuweisung, die erst künftig beginnt, lässt sich ändern: Vorschau „Wirkt ab“ ihrem Beginn, Speichern geht', async ({ page, request }) => {
		const member = await linkedMember(request, 'Frieda')
		await activeMandate(request, member)
		const validFrom = plusDays(40)
		const assignment = await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 12, paymentMethod: 'direct_debit', validFrom })

		// Der Server lehnt nicht ab und nennt den Beginn der Zuweisung, nicht „heute“.
		const preview = await (await api.selfPreviewAssignment(request, assignment.id, { monthlyAmount: 10, intervalMonths: 1, paymentMethod: 'direct_debit', user: USERS.ohneRolle })).json()
		expect(preview.effectiveFrom).toBe(validFrom)

		const card = await openCard(page)
		const save = card.getByRole('button', { name: 'Speichern' })
		await card.getByLabel('Turnus').selectOption('1')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(card.getByText(new RegExp(`Wirkt ab ${german(validFrom).replace(/\./g, '\\.')} · erster Einzug am`))).toBeVisible()
		await expect(page.getByText(LOCK_EXPLANATION, { exact: false })).toHaveCount(0)
		await expect(save).toBeEnabled()

		await card.getByLabel('Monatsbeitrag (€)').fill('12')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(save).toBeEnabled()
		await save.click()
		await expect(toast(page, 'Beitrag geändert.')).toBeVisible()
		expect(await ownAssignment(request, assignment.id)).toMatchObject({ intervalMonths: 1, monthlyAmount: 12, validFrom })
	})

	test('ein Turnuswechsel, der Monate ohne Beitrag ließe, wird mit Erklärung abgelehnt und nicht gespeichert', async ({ page, request }) => {
		test.setTimeout(120000)
		const member = await linkedMember(request, 'Moritz')
		// Überweiser: der Fall hängt nicht an Mandat oder Vorabinfo – gerade dort griff bisher gar keine Schranke.
		const assignment = await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 1, paymentMethod: 'ueberweisung' })

		// Vor dem ersten Tageslauf ist noch nichts berechnet: der Wechsel lässt keine Lücke, die erste Periode rechnet anteilig.
		const asMember = { user: USERS.ohneRolle, expectOk: false }
		expect((await api.selfPreviewAssignment(request, assignment.id, { intervalMonths: 12, ...asMember })).status()).toBe(200)

		await runCycleJob()
		const claims = await claimsOf(request, assignment.id)
		expect(claims.length, 'Der Tageslauf hat für die Zuweisung keine Forderung erzeugt').toBeGreaterThan(0)
		const billedUntil = claims[claims.length - 1].periodEnd

		const yearly = await api.selfPreviewAssignment(request, assignment.id, { intervalMonths: 12, ...asMember })
		if (billedUntil.endsWith('-12-31')) {
			// Im letzten Monat des Beitragsjahres schließt der Jahresturnus lückenlos an: nichts abzulehnen.
			expect(yearly.status()).toBe(200)
			return
		}
		expect(yearly.status()).toBe(400)
		const message = (await yearly.json()).message
		expect(message).toContain(GAP_EXPLANATION)
		expect(message).toContain(`bis ${german(billedUntil)} berechnet`)
		expect(message).toContain(`erst am 01.01.${Number(billedUntil.slice(0, 4)) + 1}`)

		// In der Oberfläche: die Erklärung als Meldung, keine Vorschau, Speichern bleibt gesperrt.
		const card = await openCard(page)
		await card.getByLabel('Turnus').selectOption('12')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(toast(page, message)).toBeVisible()
		await expect(card.getByText(/Wirkt ab/)).toHaveCount(0)
		await expect(card.getByRole('button', { name: 'Speichern' })).toBeDisabled()

		// Auch das Speichern selbst lehnt der Server ab; die Verwaltung bekommt dieselbe Antwort.
		expect((await api.selfUpdateAssignment(request, assignment.id, { intervalMonths: 12, ...asMember })).status()).toBe(400)
		const staff = await api.raw(request, 'PUT', `/assignments/${assignment.id}`, { data: { intervalMonths: 12 } })
		expect(staff.status()).toBe(400)
		expect((await staff.json()).message).toContain(GAP_EXPLANATION)
		expect(await ownAssignment(request, assignment.id)).toMatchObject({ intervalMonths: 1 })
	})

	test('der Wechsel auf einen kürzeren Turnus bleibt möglich: er schließt an jede berechnete Periode an', async ({ request }) => {
		test.setTimeout(120000)
		const member = await linkedMember(request, 'Jule')
		const assignment = await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 12, paymentMethod: 'ueberweisung' })
		await runCycleJob()
		expect((await claimsOf(request, assignment.id)).length, 'Der Tageslauf hat für die Zuweisung keine Forderung erzeugt').toBeGreaterThan(0)

		await api.selfUpdateAssignment(request, assignment.id, { intervalMonths: 1, user: USERS.ohneRolle })
		expect(await ownAssignment(request, assignment.id)).toMatchObject({ intervalMonths: 1 })
	})

	test('eine frühere Forderung ohne Vorabinfo hebt die Sperre nicht auf: maßgeblich ist die späteste Vorabinfo', async ({ request }) => {
		test.setTimeout(180000)
		const member = await linkedMember(request, 'Selma')
		await activeMandate(request, member)
		const assignment = await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 1, paymentMethod: 'direct_debit' })

		// Vorabinfo-Vorlauf 1 Tag: die Forderung entsteht, ihre Vorabinfo ist noch nicht fällig.
		await writeLeadDays(request, { prenotificationLeadDays: 1 })
		await runCycleJob()
		let claims = await claimsOf(request, assignment.id)
		expect(claims.length, 'Der Tageslauf hat für die Zuweisung keine Forderung erzeugt').toBeGreaterThan(0)
		const first = claims[0]
		expect(first.prenotifiedAt).toBeNull()

		// Die erste Forderung ist schon bezahlt: für sie geht nie eine Vorabinfo raus.
		const settled = await api.raw(request, 'POST', `/claims/${first.id}/settle`, { data: { settlementType: 'paid' } })
		expect(settled.ok(), 'Forderung als bezahlt vermerken').toBeTruthy()

		// Ein weites Vorwarnfenster lässt die Folgeperioden entstehen, ein weiter Vorabinfo-Vorlauf verschickt ihre Vorabinfo.
		await writeLeadDays(request, { warningLeadDays: 75 })
		await runCycleJob()
		claims = await claimsOf(request, assignment.id)
		expect(claims.length, 'Das Vorwarnfenster hat keine Folgeperiode erzeugt').toBeGreaterThan(1)
		const farthest = Math.max(...claims.map((claim) => daysUntil(claim.dueDate)))
		await writeLeadDays(request, { prenotificationLeadDays: Math.min(365, Math.max(1, farthest + 1)) })
		await runCycleJob()

		claims = await claimsOf(request, assignment.id)
		const announced = claims.filter((claim) => claim.prenotifiedAt)
		expect(claims[0].prenotifiedAt, 'Die bezahlte erste Forderung bekommt keine Vorabinfo').toBeNull()
		expect(announced.length, 'Der Tageslauf hat keine Vorabinfo verschickt (Mailweg, Mandat und Adresse prüfen)').toBeGreaterThan(0)
		const firstFreeDay = nextDay(announced[announced.length - 1].periodEnd)

		// Betrag ändern: abgelehnt, mit dem Tag nach der spätesten vorabinformierten Periode.
		const preview = await api.selfPreviewAssignment(request, assignment.id, { monthlyAmount: 11, user: USERS.ohneRolle, expectOk: false })
		expect(preview.status()).toBe(400)
		expect((await preview.json()).message).toBe(`${LOCK_EXPLANATION} Möglich wäre diese Änderung erst ab ${german(firstFreeDay)}.`)
		expect((await api.selfUpdateAssignment(request, assignment.id, { monthlyAmount: 11, user: USERS.ohneRolle, expectOk: false })).status()).toBe(400)
		expect(await ownAssignment(request, assignment.id)).toMatchObject({ monthlyAmount: 10, intervalMonths: 1 })
	})
})
