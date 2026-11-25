import { expect, test } from '@playwright/test'
import { inNextcloud } from './fixtures/container.mjs'
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

// Der Schalter „Hinweise auf neue Versionen“ (Einstellungen → Darstellung):
// ab Werk an, ausgeschaltet geht der Splash niemandem mehr von selbst auf, und
// Nextclouds eigene Benachrichtigung „Vereinsbuchhaltung wurde aktualisiert“
// (App updatenotification) verwirft der UpdateNotificationFilter der App –
// auch für Mitglieder ohne Rolle, die den Splash ohnehin nie sehen.
test.describe('Schalter „Hinweise auf neue Versionen“', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { update_hints_enabled: '1' })
	})

	function markSeen(request, version) {
		return api.raw(request, 'POST', '/whatsnew/seen', { user: USERS.verwalter, data: { version } })
	}

	/** Lädt die App und wartet auf GET /whatsnew – erst danach ist entschieden, ob der Splash kommt. */
	async function openAppAndWaitForWhatsNew(page) {
		await openApp(page, USERS.verwalter, { booted: true })
	}

	/**
	 * Bereitet Nextcloud die Update-Benachrichtigung der App für ein Mitglied
	 * ohne Rolle auf, wie Glocke, Push und Mail es tun (IManager::prepare()).
	 * Ohne die App notifications im Testserver gibt es keine Glocke; die
	 * Entscheidung fällt aber genau hier.
	 */
	function updateNoticeForMember() {
		return inNextcloud(String.raw`
$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
$n = $manager->createNotification();
$n->setApp('updatenotification')->setUser($argv[1])->setDateTime(new \DateTime())
	->setObject('app_updated', 'vereinsbuchhaltung')->setSubject('app_updated', ['vereinsbuchhaltung']);
try {
	$result = ['shown' => true, 'subject' => $manager->prepare($n, 'de')->getParsedSubject()];
} catch (\OCP\Notification\AlreadyProcessedException $e) {
	$result = ['shown' => false];
} catch (\Throwable $e) {
	$result = ['error' => get_class($e) . ': ' . $e->getMessage()];
}
echo 'VBH-RESULT ' . json_encode($result);
`, [USERS.ohneRolle])
	}

	/**
	 * Den Schalter in den Einstellungen umlegen und warten, bis der Server ihn
	 * hat. Das Eingabefeld selbst ist unsichtbar überlagert – geklickt wird wie
	 * in 40-settings-legal-text auf die Beschriftung.
	 */
	async function flipToggle(page, { from }) {
		const label = 'Nach einem Update auf Neuerungen hinweisen'
		await openSettingsPage(page, USERS.admin)
		const card = page.getByTestId('settings-update-hints')
		const toggle = card.getByRole('switch', { name: label })
		await expect(toggle).toBeChecked({ checked: from })
		await card.getByText(label).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()).toBeVisible()
		await expect(toggle).toBeChecked({ checked: !from })
	}

	test('ab Werk an: Splash und Nextcloud-Benachrichtigung wie bisher', async ({ page, request }) => {
		expect((await api.getSettings(request)).update_hints_enabled).toBe(true)
		expect(await updateNoticeForMember()).toEqual({ shown: true, subject: expect.stringContaining('Vereinsbuchhaltung') })

		await markSeen(request, '0.20.0')
		await openAppAndWaitForWhatsNew(page)
		await expect(page.locator('.vbh-whatsnew')).toBeVisible()
	})

	test('in den Einstellungen abschaltbar, die Nextcloud-Benachrichtigung entfällt', async ({ page, request }) => {
		await api.updateSettings(request, { update_hints_enabled: '1' })
		await flipToggle(page, { from: true })

		expect((await api.getSettings(request)).update_hints_enabled).toBe(false)
		expect(await updateNoticeForMember()).toEqual({ shown: false })
	})

	test('ausgeschaltet: kein Splash, die Liste bleibt über die Hilfe erreichbar', async ({ page, request }) => {
		await api.updateSettings(request, { update_hints_enabled: '0' })

		// Ungesehene Einträge liegen vor – trotzdem kein Splash. Die laufende
		// Version gilt danach still als gesehen: schaltet der Verein die
		// Hinweise später wieder ein, kommt nicht alles Aufgestaute auf einmal.
		await markSeen(request, '0.20.0')
		const gemerkt = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/whatsnew/seen'))
		await openAppAndWaitForWhatsNew(page)
		await gemerkt
		await expect(page.locator('.vbh-whatsnew')).toBeHidden()
		const stand = await api.getJson(request, '/whatsnew', { user: USERS.verwalter })
		expect(stand).toMatchObject({ enabled: false, lastSeenVersion: stand.currentVersion })

		// Auf Nachfrage bleibt die Liste da.
		await page.getByRole('button', { name: 'Hilfe' }).first().click()
		await page.getByRole('link', { name: /Was ist neu in Version/ }).click()
		await expect(page.locator('.vbh-whatsnew .vbh-whatsnew-entry').first()).toBeVisible()
	})

	test('wieder einschaltbar, die Nextcloud-Benachrichtigung kommt zurück', async ({ page, request }) => {
		await api.updateSettings(request, { update_hints_enabled: '0' })
		await flipToggle(page, { from: false })

		expect((await api.getSettings(request)).update_hints_enabled).toBe(true)
		expect((await updateNoticeForMember()).shown).toBe(true)
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
