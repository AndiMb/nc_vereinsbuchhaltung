import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Der Terminplan als Entwurf mit Live-Vorschau: „Einzug [14] Tage [nach] Beginn
// jeder Periode“, darunter die Perioden mit echtem Datum, abweichende Termine als
// Datum statt Periodenindex, Verstöße schon vor dem Speichern. Die Vorschau
// rechnet der Server (POST …/preview) mit denselben Guards wie beim Speichern
// (PUT …/{turnus}).
//
// Terminplan und Beitragsjahr-Beginn stehen in der App-Config, die
// `api.resetBook()` nicht räumt: der Test merkt sich den vierteljährlichen
// Terminplan und stellt ihn im `finally` wieder her. Alle Daten liest er aus der
// Vorschau des Servers, statt sie aus einem angenommenen Beitragsjahr zu rechnen.

const GROUP_NAME = 'Terminplan E2E'
const today = () => new Date().toISOString().slice(0, 10)
const suffix = () => `${Date.now() % 100000}`

/** „JJJJ-MM-TT“ plus Tage. */
function addDays(iso, days) {
	const [y, m, d] = iso.split('-').map(Number)
	return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10)
}

/** „JJJJ-MM-TT“ als „TT.MM.JJJJ“, wie die Oberfläche es zeigt. */
function german(iso) {
	const [y, m, d] = iso.split('-')
	return `${d}.${m}.${y}`
}

async function preview(request, draft, { user = 'admin' } = {}) {
	return api.raw(request, 'POST', '/due-date-schedule/3/preview', { user, data: draft })
}

async function saveQuarterly(request, defaultOffsetDays, overrides, { user = 'admin' } = {}) {
	return api.raw(request, 'PUT', '/due-date-schedule/3', { user, data: { defaultOffsetDays, overrides } })
}

/** Ein Mitglied mit vierteljährlicher, beitragsfreier Zuweisung: damit zählt der Turnus als genutzt. */
async function ensureQuarterlyMember(request) {
	const groups = await api.getJson(request, '/contribution-groups')
	let group = groups.find((g) => g.name === GROUP_NAME)
	if (!group) {
		group = await (await api.raw(request, 'POST', '/contribution-groups', {
			expectOk: true,
			data: { name: GROUP_NAME, minMonthlyAmount: 0, defaultMonthlyAmount: 0, allowedIntervals: [3], defaultInterval: 3, isActive: true },
		})).json()
	}
	const member = await api.createMember(request, { firstName: 'Quartal', lastName: `Terminplan-${suffix()}`, email: `quartal-${suffix()}@example.org` })
	await api.raw(request, 'POST', '/assignments', {
		expectOk: true,
		data: { memberId: member.id, groupId: group.id, intervalMonths: 3, monthlyAmount: 0, paymentMethod: 'ueberweisung', validFrom: today() },
	})
}

test.describe('Terminplan: Einzugstag als Satz, Termine als Datum', () => {
	let previous

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.updateSettings(request, { membership_enabled: '1' })
		await ensureQuarterlyMember(request)
		previous = (await api.getJson(request, '/due-date-schedule')).schedule['3']
	})

	test.afterEach(async ({ request }) => {
		const restored = await saveQuarterly(request, previous.defaultOffsetDays, { ...previous.overrides })
		expect(restored.ok()).toBeTruthy()
	})

	test('API: die Vorschau rechnet ohne zu speichern, gespeichert wird der Turnus in einem Schritt', async ({ request }) => {
		expect((await saveQuarterly(request, 0, {})).ok()).toBeTruthy()

		// Ab Revisor: nur rechnen.
		const resp = await preview(request, { defaultOffsetDays: 14, overrides: { 1: 31 } }, { user: USERS.revisor })
		expect(resp.status()).toBe(200)
		const draft = await resp.json()
		expect(draft.periods).toHaveLength(4)
		expect(draft.periods[0].dueDate).toBe(addDays(draft.periods[0].start, 14))
		expect(draft.periods[1]).toMatchObject({ overridden: true, offsetDays: 31, dueDate: addDays(draft.periods[1].start, 31) })
		expect(draft.violations).toEqual([])
		expect((await api.getJson(request, '/due-date-schedule')).schedule['3']).toMatchObject({ defaultOffsetDays: 0 })

		// Ein Verstoß kommt als Daten zurück, nicht als Fehler.
		const conflict = await (await preview(request, { defaultOffsetDays: 0, overrides: { 1: 100 } })).json()
		expect(conflict.violations).toContainEqual(expect.objectContaining({ kind: 'overtakes', periodIndex: 2, previousPeriodIndex: 1 }))

		// Speichern ist Terminverschiebung: Revisor nein, Buchhalter ja, Verstoß abgelehnt.
		expect((await saveQuarterly(request, 14, { 1: 31 }, { user: USERS.revisor })).status()).toBe(403)
		expect((await saveQuarterly(request, 0, { 1: 100 }, { user: USERS.buchhalter })).status()).toBe(400)
		expect((await saveQuarterly(request, 14, { 1: 31 }, { user: USERS.buchhalter })).status()).toBe(200)
		expect((await api.getJson(request, '/due-date-schedule')).schedule['3']).toMatchObject({ defaultOffsetDays: 14, overrides: { 1: 31 } })
	})

	test('Oberfläche: Einzugstag eingeben, Termin einer Periode ändern, Konflikt sehen, speichern', async ({ page, request }) => {
		expect((await saveQuarterly(request, 0, {})).ok()).toBeTruthy()
		const { periods } = await (await preview(request, { defaultOffsetDays: 14, overrides: {} })).json()

		await openApp(page, USERS.admin)
		await switchTab(page, 'Beiträge')
		const section = visibleSection(page)
		await section.locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
		await section.getByRole('button', { name: 'Terminplan', exact: true }).click()
		const dialog = page.getByRole('dialog', { name: 'Terminplan' })
		const quarterly = dialog.getByTestId('schedule-interval-3')
		await expect(quarterly).toBeVisible()
		await expect(quarterly).toContainText('Mitglied')

		// Der Einzugstag als Satz; die Termine rechnen beim Tippen mit.
		await quarterly.getByLabel('Tage ab Periodenbeginn').fill('14')
		await expect(quarterly.getByTestId('offset-meaning-3')).toHaveText(/am 15\. des ersten Periodenmonats/)
		const rows = quarterly.locator('tbody tr')
		await expect(rows.nth(0)).toContainText(german(periods[0].dueDate))
		await expect(quarterly.getByText('Änderungen noch nicht gespeichert.')).toBeVisible()

		// Ein abweichender Termin wird als Datum gewählt.
		const custom = addDays(periods[1].start, 20)
		await rows.nth(1).getByRole('button', { name: 'Termin ändern' }).click()
		await rows.nth(1).locator('input[type="date"]').fill(custom)
		await rows.nth(1).getByRole('button', { name: 'Übernehmen' }).click()
		await expect(rows.nth(1)).toContainText(german(custom))
		await expect(rows.nth(1)).toContainText('Abweichend')

		// Liegt er hinter dem Termin der nächsten Periode, sagt die Ansicht das vor dem Speichern.
		await rows.nth(1).getByRole('button', { name: 'Termin ändern' }).click()
		await rows.nth(1).locator('input[type="date"]').fill(addDays(periods[2].start, 20))
		await rows.nth(1).getByRole('button', { name: 'Übernehmen' }).click()
		await expect(quarterly.getByRole('alert')).toContainText('3. Quartal: Der Einzug am')
		await expect(quarterly.getByRole('button', { name: 'Speichern' })).toBeDisabled()

		await rows.nth(1).getByRole('button', { name: 'Zurücksetzen' }).click()
		await expect(quarterly.getByRole('alert')).toHaveCount(0)

		// Die Fristen stehen an einem echten Termin.
		await expect(dialog.getByTestId('lead-days-summary')).toContainText('Forderungen entstehen')

		await quarterly.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByText('Terminplan für vierteljährlich gespeichert.', { exact: true })).toBeVisible()
		await expect(quarterly.getByText('Änderungen noch nicht gespeichert.')).toHaveCount(0)
		expect((await api.getJson(request, '/due-date-schedule')).schedule['3']).toMatchObject({ defaultOffsetDays: 14, overrides: {} })

		// Turnusse ohne Mitglieder klappen direkt unter dem Knopf auf, im Blick statt darüber.
		await dialog.getByRole('button', { name: /^Turnusse ohne Mitglieder:/ }).click()
		await expect(dialog.getByTestId('schedule-interval-2')).toBeInViewport()

		// Schließen mit ungespeichertem Entwurf fragt erst nach.
		await quarterly.getByLabel('Tage ab Periodenbeginn').fill('20')
		await dialog.locator('.vbh-modal-actions').getByRole('button', { name: 'Schließen' }).click()
		const confirm = page.getByRole('dialog', { name: 'Änderungen verwerfen?' })
		await confirm.getByRole('button', { name: 'Verwerfen', exact: true }).click()
		await expect(dialog).toHaveCount(0)
		expect((await api.getJson(request, '/due-date-schedule')).schedule['3']).toMatchObject({ defaultOffsetDays: 14 })
	})
})
