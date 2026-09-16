import { getContainer, runExec, runOcc } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { api, openApp, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Self-Service-Zugang "Mein Beitrag" (Issue #74, Spec §3.4): folgt
// ausschließlich der Mitglied↔NC-Konto-Verknüpfung, keiner vbh-Rolle. Geprüft
// wird hier die Oberfläche; die Middleware-Ausnahme selbst (verknüpft+aktiv/
// verknüpft+inaktiv/nicht verknüpft/IDOR) ist PHPUnit-Sache, siehe
// tests/unit/PermissionMiddlewareSelfServiceTest.php und SelfControllerTest.php.
//
// Es gibt in diesem Stand des Moduls noch keine Member-CRUD-API (folgt mit der
// eigentlichen Mitgliederverwaltung) - die Kontoverknüpfung für den Test legt
// deshalb self-service-seed.php direkt über MemberMapper an, siehe dort.

const container = getContainer()
const SEED_SCRIPT = '/var/www/html/apps-writable/vereinsbuchhaltung/tests/e2e/fixtures/self-service-seed.php'

const LINKED_MEMBER = { firstName: 'Erika', lastName: 'Musterfrau', email: 'erika.musterfrau@example.org' }

async function linkMember(ncUserId, { firstName, lastName, email } = LINKED_MEMBER) {
	const { stdout, stderr, exitCode } = await runExec(['php', SEED_SCRIPT, 'link', ncUserId, firstName, lastName, email], { container })
	if (exitCode !== 0) {
		throw new Error(`Mitglied-Verknüpfung für ${ncUserId} fehlgeschlagen: ${stderr || stdout}`)
	}
}

async function unlinkMember(ncUserId) {
	await runExec(['php', SEED_SCRIPT, 'unlink', ncUserId], { container })
}

function setSelfServiceEnabled(enabled) {
	return runOcc(['config:app:set', 'vereinsbuchhaltung', 'self_service_enabled', '--value', enabled ? '1' : '0'], { container })
}

/**
 * Wartet, bis eine über occ geschriebene App-Einstellung im Webprozess
 * ankommt (APCu-Prozesscache, drei Sekunden – siehe 18-settings.spec.mjs für
 * dieselbe Falle bei storage_user/statement_watch_user).
 */
async function waitForSelfServiceEnabled(request, enabled) {
	await expect.poll(
		async () => (await api.getSettings(request)).self_service_enabled,
		{ timeout: 15000, intervals: [3000, 500] },
	).toBe(enabled)
}

test.describe('Self-Service ("Mein Beitrag")', () => {
	test.beforeAll(async ({ request }) => {
		await setSelfServiceEnabled(true)
		await waitForSelfServiceEnabled(request, true)
		// test4 (USERS.ohneRolle) ist im "init"-Snapshot bewusst ohne vbh-Rolle -
		// hier zusätzlich mit einem Mitglied verknüpft, um den Self-Service-Zugang
		// zu zeigen. test5 (USERS.englisch) bleibt für den Negativtest unverändert
		// unverknüpft.
		await linkMember(USERS.ohneRolle)
	})

	test.afterAll(async ({ request }) => {
		await unlinkMember(USERS.ohneRolle)
		await setSelfServiceEnabled(false)
		await waitForSelfServiceEnabled(request, false)
	})

	test('verknüpftes Konto sieht "Mein Beitrag" mit eigenen Stammdaten statt "Kein Zugriff"', async ({ page }) => {
		await openApp(page, USERS.ohneRolle)

		// Kein Buchhaltungs-Tab, aber auch nicht mehr das alte "Kein Zugriff" -
		// die Ersetzung durch "Mein Beitrag" ist der Kern von Issue #74.
		await expect(page.getByText('Kein Zugriff')).toHaveCount(0)
		await expect(tabButton(page, 'Buchungen')).toHaveCount(0)

		const tab = tabButton(page, 'Mein Beitrag')
		await expect(tab).toBeVisible()
		await tab.click()

		const section = visibleSection(page)
		await expect(section.getByText('Erika Musterfrau')).toBeVisible()
		await expect(section.getByText('erika.musterfrau@example.org')).toBeVisible()
		await expect(section.getByText('+49 30 1234567')).toBeVisible()

		// Datenhygiene bis in die Oberfläche: die interne Vereinsnotiz aus dem
		// Seed-Skript darf nirgends auf der Seite auftauchen (Spec §2.2).
		await expect(page.getByText('Vereinsinterne Notiz')).toHaveCount(0)
	})

	test('nicht verknüpftes Konto sieht "Mein Beitrag" nicht, obwohl der Schalter an ist', async ({ page }) => {
		await openApp(page, USERS.englisch) // test5: ohne Rolle, nie verknüpft

		await expect(page.getByText('No access')).toBeVisible()
		await expect(tabButton(page, 'My contribution')).toHaveCount(0)
	})
})
