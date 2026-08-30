import { test, expect } from '@playwright/test'
import { api, openApp, USERS } from './fixtures/nextcloud.mjs'

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
