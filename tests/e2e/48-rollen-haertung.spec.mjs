import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Rollen-Härtung des Beitragsmoduls (Issue #119, Spec §3.9): jede Methode trägt
// ihre Rolle ausdrücklich, und was zur Einstellung gehört, ist `verwalter`-Sache.
// Geprüft wird hier die Linie, die ein Strukturtest in PHPUnit nicht zeigen kann:
// dass die echte Middleware sie an der echten API durchsetzt und die Oberfläche
// nichts anbietet, was der Server ablehnt.
//
// - Vorlaufzeiten (Vorwarnfenster, Vorabinfo-Vorlauf): nur Verwalter. Der Buchhalter
//   bekommt an der API eine 403 und sieht die Felder in der Oberfläche gesperrt.
// - Terminverschiebung (Standard-Einzugstag, Überschreibung einer Periode) bleibt
//   Buchhalter-Sache.
// - Zuweisungen sind Personenakte (Begründung der individuellen Untergrenze):
//   auch Lesen erst ab Buchhalter.
// - Das Amendment eines Mandats trägt die alte IBAN; der Revisor sieht sie maskiert.
//
// Der Terminplan und die Einstellung `membership_enabled` stehen in der App-Config,
// die `api.resetBook()` nicht räumt: jeder Test liest vorher, was er ändert, und
// stellt es im `finally` wieder her. Mitglieder und Mandate früherer Specs bleiben
// ebenfalls stehen – die Tests legen ihre eigenen, eindeutig benannten an.

const OLD_IBAN = 'DE02120300000000202051'
const NEW_IBAN = 'DE89370400440532013000'
const OLD_IBAN_MASKED = /^DE02•+2051$/

const WARNING_LABEL = 'Vorwarnfenster (Tage vor Einzug)'
const PRENOTIFICATION_LABEL = 'Vorabinfo-Vorlauf (Tage vor Einzug)'
const ADMIN_ONLY_HINT = 'Nur Verwalter können die Vorlaufzeiten ändern.'

const today = () => new Date().toISOString().slice(0, 10)

/** Vorwarnfenster und Vorabinfo-Vorlauf, wie der Server sie gerade führt. */
async function readLeadDays(request) {
	const { warningLeadDays, prenotificationLeadDays } = await api.getJson(request, '/due-date-schedule')
	return { warningLeadDays, prenotificationLeadDays }
}

/** Stellt die Vorlaufzeiten als Verwalter her (der Test selbst darf sie als Buchhalter nicht ändern). */
async function writeLeadDays(request, values) {
	const resp = await api.raw(request, 'POST', '/due-date-schedule/lead-days', { data: values })
	expect(resp.ok()).toBeTruthy()
}

/** Öffnet Beiträge → Einzug und klappt den Terminplan auf; liefert dessen Bereich. */
async function openSchedule(page, user) {
	await openApp(page, user)
	await switchTab(page, 'Beiträge')
	const section = visibleSection(page)
	await section.locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
	await expect(section.getByRole('tab', { name: 'Zeitstrahl & Läufe' })).toBeVisible()
	await section.getByRole('button', { name: 'Terminplan', exact: true }).click()
	const schedule = section.locator('#vbh-einzug-schedule')
	await expect(schedule.getByRole('heading', { name: 'Terminplan' })).toBeVisible()
	return schedule
}

test.describe('Rollen-Härtung: Vorlaufzeiten nur für Verwalter', () => {
	let membershipWasEnabled

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		membershipWasEnabled = (await api.getSettings(request)).membership_enabled === true
		// Ohne das Beitragsmodul gibt es den Reiter „Beiträge“ nur, wenn schon Mitglieder bestehen.
		await api.updateSettings(request, { membership_enabled: '1' })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { membership_enabled: membershipWasEnabled ? '1' : '0' })
	})

	test('API: Vorlaufzeiten ändern prallt für Buchhalter, Revisor und ohne Rolle mit 403 ab, der Verwalter darf', async ({ request }) => {
		const before = await readLeadDays(request)
		const changed = { warningLeadDays: before.warningLeadDays + 3, prenotificationLeadDays: before.prenotificationLeadDays + 3 }

		try {
			for (const user of [USERS.buchhalter, USERS.revisor, USERS.ohneRolle]) {
				const resp = await api.raw(request, 'POST', '/due-date-schedule/lead-days', { user, data: changed })
				expect(resp.status(), `als ${user}`).toBe(403)
			}
			// Der abgewiesene Aufruf hat nichts geschrieben.
			expect(await readLeadDays(request)).toEqual(before)

			const resp = await api.raw(request, 'POST', '/due-date-schedule/lead-days', { user: USERS.verwalter, data: changed })
			expect(resp.status()).toBe(200)
			expect(await resp.json()).toMatchObject(changed)
			expect(await readLeadDays(request)).toEqual(changed)
		} finally {
			await writeLeadDays(request, before)
		}
	})

	test('API: lesen ab Revisor, die Terminverschiebung bleibt Buchhalter-Sache', async ({ request }) => {
		const schedule = await api.getJson(request, '/due-date-schedule', { user: USERS.revisor })
		expect(schedule.schedule['12']).toBeDefined()
		expect((await api.raw(request, 'GET', '/due-date-schedule', { user: USERS.ohneRolle })).status()).toBe(403)

		const previousDefault = schedule.schedule['12'].defaultOffsetDays
		const previousOverride = schedule.schedule['12'].overrides?.[0] ?? null
		try {
			// Der Revisor ändert nichts am Terminplan …
			for (const [path, data] of [
				['/due-date-schedule/12/default-day', { offsetDays: previousDefault + 1 }],
				['/due-date-schedule/12/overrides/0', { offsetDays: 5 }],
			]) {
				expect((await api.raw(request, 'POST', path, { user: USERS.revisor, data })).status(), path).toBe(403)
			}
			// … der Buchhalter verschiebt den Termin einer Periode und setzt den Standard-Einzugstag (hier: unverändert).
			const override = await api.raw(request, 'POST', '/due-date-schedule/12/overrides/0', { user: USERS.buchhalter, data: { offsetDays: 5 } })
			expect(override.status()).toBe(200)
			expect((await override.json()).overrides['0']).toBe(5)
			const standard = await api.raw(request, 'POST', '/due-date-schedule/12/default-day', { user: USERS.buchhalter, data: { offsetDays: previousDefault } })
			expect(standard.status()).toBe(200)
		} finally {
			await api.raw(request, 'POST', '/due-date-schedule/12/overrides/0', { data: { offsetDays: previousOverride } })
			await api.raw(request, 'POST', '/due-date-schedule/12/default-day', { data: { offsetDays: previousDefault } })
		}
	})

	test('Oberfläche: der Buchhalter sieht die Vorlaufzeiten im Einzug-Reiter gesperrt, die Terminverschiebung bleibt bedienbar', async ({ page, request }) => {
		const before = await readLeadDays(request)
		const schedule = await openSchedule(page, USERS.buchhalter)

		await expect(schedule.getByLabel(WARNING_LABEL)).toBeDisabled()
		await expect(schedule.getByLabel(WARNING_LABEL)).toHaveValue(String(before.warningLeadDays))
		await expect(schedule.getByLabel(PRENOTIFICATION_LABEL)).toBeDisabled()
		await expect(schedule.getByLabel(PRENOTIFICATION_LABEL)).toHaveValue(String(before.prenotificationLeadDays))
		await expect(schedule.getByText(ADMIN_ONLY_HINT)).toBeVisible()
		// Der Speichern-Knopf hinter den beiden Feldern ist der letzte der Karte.
		await expect(schedule.getByRole('button', { name: 'Speichern' }).last()).toBeDisabled()

		// Terminverschiebung: Standard-Einzugstag und Überschreibung bleiben offen.
		await expect(schedule.locator('tbody tr').first().locator('input[type="number"]')).toBeEnabled()
		await expect(schedule.locator('tbody tr').first().getByRole('button', { name: 'Speichern' })).toBeEnabled()
		await expect(schedule.getByLabel('Periodenindex')).toBeEnabled()
		await expect(schedule.getByRole('button', { name: '+ Überschreibung' })).toBeEnabled()

		// Nichts wurde nebenbei geschrieben.
		expect(await readLeadDays(request)).toEqual(before)
	})

	test('Oberfläche: im Panel Beitragsgruppen sind die Vorlaufzeiten für den Buchhalter ebenso gesperrt', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const section = visibleSection(page)
		await section.locator('.vbh-subtabs').getByRole('button', { name: 'Beitragsgruppen', exact: true }).click()

		await expect(section.getByRole('heading', { name: 'Terminplan' })).toBeVisible()
		await expect(section.getByLabel(WARNING_LABEL)).toBeDisabled()
		await expect(section.getByLabel(PRENOTIFICATION_LABEL)).toBeDisabled()
		await expect(section.getByText(ADMIN_ONLY_HINT)).toBeVisible()
	})

	test('Oberfläche: der Verwalter ändert die Vorlaufzeiten', async ({ page, request }) => {
		const before = await readLeadDays(request)
		const warning = before.warningLeadDays + 7

		try {
			const schedule = await openSchedule(page, USERS.verwalter)

			await expect(schedule.getByLabel(WARNING_LABEL)).toBeEnabled()
			await expect(schedule.getByLabel(PRENOTIFICATION_LABEL)).toBeEnabled()
			await expect(schedule.getByText(ADMIN_ONLY_HINT)).toHaveCount(0)

			await schedule.getByLabel(WARNING_LABEL).fill(String(warning))
			await schedule.getByRole('button', { name: 'Speichern' }).last().click()

			await expect.poll(async () => (await readLeadDays(request)).warningLeadDays).toBe(warning)
			expect((await readLeadDays(request)).prenotificationLeadDays).toBe(before.prenotificationLeadDays)
		} finally {
			await writeLeadDays(request, before)
		}
	})
})

test.describe('Rollen-Härtung: Personenakte und IBAN', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
	})

	test('Zuweisungen sind Personenakte: Revisor und ohne Rolle lesen sie nicht, Buchhalter schon', async ({ request }) => {
		for (const path of ['/assignments', '/assignments/1/events']) {
			for (const user of [USERS.revisor, USERS.ohneRolle]) {
				expect((await api.raw(request, 'GET', path, { user })).status(), `${path} als ${user}`).toBe(403)
			}
			expect((await api.raw(request, 'GET', path, { user: USERS.buchhalter })).status(), `${path} als Buchhalter`).toBe(200)
		}
	})

	test('Beitragsgruppen: der Revisor liest das Regelwerk, ändern darf er es nicht', async ({ request }) => {
		expect((await api.raw(request, 'GET', '/contribution-groups', { user: USERS.revisor })).status()).toBe(200)

		const group = { name: 'Revisor darf das nicht', minMonthlyAmount: 1, defaultMonthlyAmount: 2, allowedIntervals: [1], defaultInterval: 1, isActive: true }
		expect((await api.raw(request, 'POST', '/contribution-groups', { user: USERS.revisor, data: group })).status()).toBe(403)
		expect((await api.raw(request, 'PUT', '/contribution-groups/1', { user: USERS.revisor, data: group })).status()).toBe(403)
		expect((await api.raw(request, 'DELETE', '/contribution-groups/1', { user: USERS.revisor })).status()).toBe(403)
		expect((await api.raw(request, 'GET', '/contribution-groups', { user: USERS.ohneRolle })).status()).toBe(403)
	})

	test('Mandat: die alte IBAN eines Amendments sieht der Revisor nur maskiert, der Buchhalter im Klartext', async ({ request }) => {
		const unique = Math.random().toString(36).slice(2, 8)
		const member = await api.createMember(request, { firstName: 'Rolle', lastName: `Härtung-${unique}`, email: `rolle.haertung-${unique}@example.org` })
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: OLD_IBAN, signedAt: today() })).json()
		await api.activateMandate(request, mandate.id)
		// Das Mitglied zieht um: neue IBAN am selben Mandat, die alte steht im Amendment.
		const amend = await api.raw(request, 'POST', `/mandates/${mandate.id}/amend-bank-details`, { data: { iban: NEW_IBAN } })
		expect(amend.ok()).toBeTruthy()

		const asRevisor = await api.raw(request, 'GET', `/mandates/${mandate.id}`, { user: USERS.revisor })
		expect(asRevisor.status()).toBe(200)
		const revisorText = await asRevisor.text()
		const revisorView = JSON.parse(revisorText)
		expect(revisorView.amendments).toHaveLength(1)
		expect(revisorView.amendments[0].oldIban).toMatch(OLD_IBAN_MASKED)
		expect(revisorView.iban).toMatch(/^DE89•+3000$/)
		// Weder die alte noch die neue IBAN steht irgendwo in der Antwort im Klartext.
		expect(revisorText).not.toContain(OLD_IBAN)
		expect(revisorText).not.toContain(NEW_IBAN)

		const asBookkeeper = await api.getJson(request, `/mandates/${mandate.id}`, { user: USERS.buchhalter })
		expect(asBookkeeper.amendments[0].oldIban).toBe(OLD_IBAN)
		expect(asBookkeeper.iban).toBe(NEW_IBAN)
	})
})
