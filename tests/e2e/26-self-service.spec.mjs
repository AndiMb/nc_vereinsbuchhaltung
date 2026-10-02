import { addUser, getContainer, runOcc, User } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { api, openApp, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Self-Service-Zugang "Mein Beitrag" (Issue #74, Spec §3.4): folgt
// ausschließlich der Mitglied↔NC-Konto-Verknüpfung, keiner vbh-Rolle. Geprüft
// wird hier die Oberfläche; die Middleware-Ausnahme selbst (verknüpft+aktiv/
// verknüpft+inaktiv/nicht verknüpft/IDOR) ist PHPUnit-Sache, siehe
// tests/unit/PermissionMiddlewareSelfServiceTest.php und SelfControllerTest.php.
//
// Die Kontoverknüpfung für den Test läuft über dieselbe Member-API wie
// 23-members.spec.mjs (Issue #65) - anders als noch beim Fork dieses Branches
// gibt es die jetzt wirklich (createMember/linkMember).

const container = getContainer()

// Eigener Wegwerf-Nutzer für den Negativtest statt eines der geteilten
// test1–test5: test5 (USERS.englisch) sieht im Vollsuite-Lauf inzwischen NICHT
// mehr rollenlos aus, weil 16-l10n.spec.mjs ihm für die Sprachprüfung die
// Revisor-Rolle vergibt und sie danach nicht zurücknimmt - ein frischer Nutzer
// ist unabhängig von der Ausführungsreihenfolge anderer Spec-Dateien
// garantiert ohne Rolle und ohne Verknüpfung (gleiches Muster wie TEMP_USER in
// 18-settings.spec.mjs).
const UNLINKED_USER = 'wegwerfselfservice'

const LINKED_MEMBER = {
	firstName: 'Erika',
	lastName: 'Musterfrau',
	email: 'erika.musterfrau@example.org',
	phone: '+49 30 1234567',
	// Belegt im Test, dass internal_note dem Self-Service NIE angezeigt wird
	// (Spec §2.2) - der Text darf auf der Seite nirgends auftauchen.
	internalNote: 'Vereinsinterne Notiz, darf im Self-Service nie sichtbar sein.',
}

/**
 * Räumt eine evtl. aus einem abgebrochenen vorherigen Lauf übrig gebliebene
 * Verknüpfung für dieses Konto weg - macht beforeAll idempotent für Retries
 * (die laufen bei Playwright erneut) und für ein versehentlich übersprungenes
 * afterAll.
 */
async function unlinkExisting(request, ncUserId) {
	const members = await api.listMembers(request)
	const existing = members.find((m) => m.ncUserId === ncUserId)
	if (existing) {
		await api.unlinkMember(request, existing.id)
		await api.deleteMember(request, existing.id)
	}
}

test.describe('Self-Service ("Mein Beitrag")', () => {
	let memberId
	let unlinkedUserExists = false

	test.beforeAll(async ({ request }) => {
		test.setTimeout(60000) // occ user:add im Container

		await api.updateSettings(request, { self_service_enabled: '1' })
		await unlinkExisting(request, USERS.ohneRolle)

		const created = await api.createMember(request, LINKED_MEMBER)
		memberId = created.id
		await api.linkMember(request, memberId, USERS.ohneRolle)

		await runOcc(['user:delete', UNLINKED_USER], { container, failOnError: false }) // nur falls ein Test vorher abbrach
		// Passwort = Nutzername (wie test1-test5, siehe setupUsers()) - openApp()/
		// login() nehmen ohne expliziten dritten Parameter genau das an.
		await addUser(new User(UNLINKED_USER), { container })
		unlinkedUserExists = true
		// Ohne diese explizite Einstellung stempelt der erste Login die vom
		// Browser gesendete Accept-Language (bei Playwright Englisch) dauerhaft
		// in die Nutzereinstellung, unabhängig vom System-Default "de" (siehe
		// derselbe Kommentar in tests/e2e/setup/server.mjs) - der Test prüft
		// hier bewusst den deutschen Quelltext.
		await runOcc(['user:setting', UNLINKED_USER, 'core', 'lang', 'de'], { container })
	})

	test.afterAll(async ({ request }) => {
		if (memberId) {
			await api.unlinkMember(request, memberId)
			await api.deleteMember(request, memberId)
		}
		await api.updateSettings(request, { self_service_enabled: '0' })
		if (unlinkedUserExists) {
			await runOcc(['user:delete', UNLINKED_USER], { container })
		}
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
		await expect(section.getByText(LINKED_MEMBER.email)).toBeVisible()
		await expect(section.getByText(LINKED_MEMBER.phone)).toBeVisible()

		// Datenhygiene bis in die Oberfläche: die interne Vereinsnotiz darf
		// nirgends auf der Seite auftauchen (Spec §2.2).
		await expect(page.getByText('Vereinsinterne Notiz')).toHaveCount(0)
	})

	test('nicht verknüpftes Konto sieht "Mein Beitrag" nicht, obwohl der Schalter an ist', async ({ page }) => {
		await openApp(page, UNLINKED_USER)

		await expect(page.getByText('Kein Zugriff')).toBeVisible()
		await expect(tabButton(page, 'Mein Beitrag')).toHaveCount(0)
	})
})
