import { expect, test } from '@playwright/test'
import { inNextcloud } from './fixtures/girocode.mjs'
import { api, openApp, openSettingsPage, USERS } from './fixtures/nextcloud.mjs'

// Der "Was ist neu"-Splash (WhatsNewDialog.vue) ist der einzige Dialog, der
// sich ungefragt vor die Oberflaeche legt – geht er nicht mehr zu, ist die App
// unbedienbar. Genau das passierte, als ein Eintrag fuer eine noch nicht
// ausgelieferte Version vorbereitet wurde: "Verstanden" schreibt die laufende
// Version in whatsnew_last_seen_version, der neuere Eintrag blieb uebrig, und
// der Splash kam bei jedem Laden wieder.
test.describe('Was ist neu', () => {
	// Ohne Konten haette der Setup-Assistent Vorrang – der Splash bliebe aus.
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// Ausgeschaltete Hinweise (Block unten) unterdrückten den Splash ebenfalls.
		await api.updateSettings(request, { update_hints_enabled: '1' })
	})

	/** Zuletzt gesehene Version des Verwalters setzen (pro Nutzer, IConfig). */
	function markSeen(request, version) {
		return api.raw(request, 'POST', '/whatsnew/seen', { user: USERS.verwalter, data: { version } })
	}

	test('erscheint nach einem Update und laesst sich endgueltig wegklicken', async ({ page, request }) => {
		await markSeen(request, '0.20.0')

		await openApp(page, USERS.verwalter)
		const splash = page.locator('.vbh-whatsnew')
		await expect(splash).toBeVisible()
		await expect(splash.locator('.vbh-whatsnew-entry').first()).toBeVisible()

		await splash.getByRole('button', { name: 'Verstanden' }).click()
		await expect(splash).toBeHidden()

		// Der Kern: nach dem Neuladen bleibt er weg. Bewusst auf die Antwort von
		// GET /whatsnew gewartet – sonst prueft die Zusicherung "unsichtbar",
		// bevor die App den Splash ueberhaupt anfordern konnte, und bestuende
		// auch dann, wenn er eine Sekunde spaeter doch aufginge.
		const geladen = page.waitForResponse((r) => r.request().method() === 'GET' && r.url().endsWith('/api/whatsnew'))
		await page.reload()
		await geladen
		await page.locator('.vbh').waitFor()
		await expect(page.locator('.vbh-whatsnew')).toBeHidden()
	})

	test('zeigt keine Version, die noch gar nicht ausgeliefert ist', async ({ page, request }) => {
		await markSeen(request, '0.20.0')
		await openApp(page, USERS.verwalter)

		const splash = page.locator('.vbh-whatsnew')
		// Erst wenn der Splash offen ist, steht seine Liste fest – vorher liest
		// allInnerTexts() nichts oder einen Zwischenstand.
		await expect(splash.locator('.vbh-whatsnew-entry').first()).toBeVisible()
		const laufend = (await api.getJson(request, '/whatsnew', { user: USERS.verwalter })).currentVersion
		for (const ueberschrift of await splash.locator('.vbh-whatsnew-entry h4').allInnerTexts()) {
			const version = ueberschrift.replace(/\D*([\d.]+).*/, '$1')
			expect(istHoechstens(version, laufend), `${ueberschrift} liegt ueber der laufenden Version ${laufend}`).toBe(true)
		}
	})

	test('auf aktuellem Stand kommt er nicht, der Hilfe-Link holt ihn trotzdem', async ({ page, request }) => {
		await markSeen(request, (await api.getJson(request, '/whatsnew', { user: USERS.verwalter })).currentVersion)

		await openApp(page, USERS.verwalter)
		await expect(page.locator('.vbh-whatsnew')).toBeHidden()

		await page.getByRole('button', { name: 'Hilfe' }).first().click()
		await page.getByRole('link', { name: /Was ist neu in Version/ }).click()
		// Ungefiltert: auch Eintraege, die der Verwalter laengst gesehen hat.
		await expect(page.locator('.vbh-whatsnew .vbh-whatsnew-entry').first()).toBeVisible()
	})
})

// Schalter „Hinweise auf neue Versionen“ (Einstellungen → Darstellung). Aus
// heißt: kein Splash, und der UpdateNotificationFilter verwirft Nextclouds
// Benachrichtigung „Vereinsbuchhaltung wurde aktualisiert“ – auch für
// Mitglieder ohne Rolle. Die prüft der Test über IManager::prepare(), denselben
// Weg wie Glocke, Push und Mail (der Testserver hat die App notifications nicht).
test.describe('Schalter „Hinweise auf neue Versionen“', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test.beforeEach(async ({ request }) => {
		await api.updateSettings(request, { update_hints_enabled: '1' })
		await api.raw(request, 'POST', '/whatsnew/seen', { data: { version: '0.20.0' } })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { update_hints_enabled: '1' })
	})

	function updateNoticeShown() {
		return inNextcloud(String.raw`
$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
$n = $manager->createNotification();
$n->setApp('updatenotification')->setUser($argv[1])->setDateTime(new \DateTime())
	->setObject('app_updated', 'vereinsbuchhaltung')->setSubject('app_updated', ['vereinsbuchhaltung']);
try {
	$manager->prepare($n, 'de');
	$result = ['shown' => true];
} catch (\OCP\Notification\AlreadyProcessedException $e) {
	$result = ['shown' => false];
}
echo 'VBH-RESULT ' . json_encode($result);
`, [USERS.ohneRolle])
	}

	test('an: Splash erscheint, Nextcloud meldet das Update', async ({ page }) => {
		expect(await updateNoticeShown()).toEqual({ shown: true })
		await openApp(page, USERS.admin, { booted: true })
		await expect(page.locator('.vbh-whatsnew')).toBeVisible()
	})

	test('aus: in den Einstellungen abgeschaltet, kein Splash und keine Nextcloud-Meldung', async ({ page, request }) => {
		// Das Eingabefeld ist überlagert; geklickt wird auf die Beschriftung.
		const label = 'Nach einem Update auf Neuerungen hinweisen'
		await openSettingsPage(page, USERS.admin)
		const card = page.getByTestId('settings-update-hints')
		await expect(card.getByRole('switch', { name: label })).toBeChecked()
		await card.getByText(label).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()).toBeVisible()
		expect((await api.getSettings(request)).update_hints_enabled).toBe(false)

		expect(await updateNoticeShown()).toEqual({ shown: false })

		// Statt des Splash merkt die App die laufende Version still als gesehen.
		const gemerkt = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/whatsnew/seen'))
		await openApp(page, USERS.admin, { booted: true })
		await gemerkt
		await expect(page.locator('.vbh-whatsnew')).toBeHidden()
	})
})

/** "0.27.0" <= "0.28.0", elementweise als Zahlen – nicht als String. */
function istHoechstens(a, b) {
	const pa = a.split('.').map(Number)
	const pb = b.split('.').map(Number)
	for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
		const diff = (pa[i] || 0) - (pb[i] || 0)
		if (diff !== 0) { return diff < 0 }
	}
	return true
}
