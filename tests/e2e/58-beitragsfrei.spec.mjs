import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Beitragsfreie Zuweisung (0 €): so bildet ein Verein Pausen und Passivmitgliedschaften ab, etwa mit einer
// Beitragsgruppe „Ruhend“. Für eine solche Zuweisung entstehen keine Forderungen, es wird nichts eingezogen,
// ein Mandat ist nicht nötig, und die Mitgliederliste sagt „beitragsfrei“. Die App hält die beitragsfreien
// Perioden im Hintergrund fest, damit eine spätere Betragserhöhung sie nicht rückwirkend nachfordert.

const GROUP_NAME = 'Ruhend E2E'
const today = () => new Date().toISOString().slice(0, 10)
const suffix = () => `${Date.now() % 100000}`

async function enableMembership(request) {
	await api.updateSettings(request, { membership_enabled: '1', club_name: 'Testverein e.V.' })
}

async function ensureGroup(request) {
	const groups = await api.getJson(request, '/contribution-groups')
	let group = groups.find((g) => g.name === GROUP_NAME)
	if (!group) {
		group = await (await api.raw(request, 'POST', '/contribution-groups', {
			expectOk: true,
			data: { name: GROUP_NAME, minMonthlyAmount: 0, defaultMonthlyAmount: 0, allowedIntervals: [12], defaultInterval: 12, isActive: true },
		})).json()
	}
	return group
}

/** Der tägliche Lauf (erzeugt Forderungen, verschickt Vorabinfos), genau wie der Cron ihn ausführt. */
async function runDailyJob() {
	const container = getContainer()
	const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
	const job = JSON.parse(stdout).find((j) => (j.class || '').includes('ContributionDueCycleJob'))
	expect(job, 'ContributionDueCycleJob ist registriert').toBeTruthy()
	await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })
}

/** Die Forderungen dieses Mitglieds, wie die Oberfläche sie bekommt. */
async function claimsOf(request, member) {
	return (await api.getJson(request, '/claims')).filter((c) => c.memberId === member.id)
}

test.describe('Beitragsfreie Zuweisung (0 €)', () => {
	let group
	let member
	let assignment

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
		group = await ensureGroup(request)
		// Lastschrift, aber bewusst OHNE Mandat: bei 0 € ist nichts einzuziehen, also auch kein Störfall.
		member = await api.createMember(request, { firstName: 'Pause', lastName: `Ruhend-${suffix()}`, email: `pause.ruhend-${suffix()}@example.org` })
		assignment = await (await api.raw(request, 'POST', '/assignments', {
			expectOk: true,
			data: { memberId: member.id, groupId: group.id, intervalMonths: 12, monthlyAmount: 0, paymentMethod: 'direct_debit', validFrom: today() },
		})).json()
	})

	test('der Tageslauf legt keine Forderung an und meldet keinen Störfall „Mandat fehlt“', async ({ request }) => {
		test.setTimeout(60000)
		await runDailyJob()

		expect(await claimsOf(request, member)).toEqual([])
		const openItems = await api.getJson(request, '/open-items')
		expect(openItems.filter((o) => o.memberId === member.id)).toEqual([])

		const tasks = await api.getJson(request, '/tasks', { user: USERS.buchhalter })
		expect(tasks.filter((t) => String(t.message).includes(member.displayName))).toEqual([])
	})

	test('die Mitgliederliste zeigt „beitragsfrei“ und keine Auffälligkeit, obwohl ein Mandat fehlt', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('table.vbh-table:visible tr', { hasText: member.displayName })
		await expect(row).toContainText('beitragsfrei')
		await expect(row).not.toContainText('kein Mandat')
	})

	test('eine spätere Betragserhöhung fordert die beitragsfreie Periode nicht rückwirkend nach', async ({ request }) => {
		test.setTimeout(60000)
		const resp = await api.raw(request, 'PUT', `/assignments/${assignment.id}`, { data: { monthlyAmount: 10 } })
		expect(resp.ok(), `Betrag anheben: ${resp.status()} ${await resp.text()}`).toBeTruthy()

		await runDailyJob()

		// Die laufende Periode war beitragsfrei und bleibt es. Eine Forderung für die Zeit ab Beginn der Zuweisung
		// (bis heute) darf es nicht geben; eine für das nächste Jahr wäre erst kurz vor dessen Beginn möglich.
		const rueckwirkend = (await claimsOf(request, member)).filter((c) => c.periodStart <= today())
		expect(rueckwirkend).toEqual([])
	})
})
