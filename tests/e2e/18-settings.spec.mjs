import { expect, test } from '@playwright/test'
import { api, authHeaders, BANK_ACCOUNT, BASE_URL, INCOME_ACCOUNT, openSettingsPage, USERS } from './fixtures/nextcloud.mjs'

// Die Einstellungsseite (Nextcloud-Einstellungen → Vereinsbuchhaltung):
// Belegablage auf einen Nutzer-Ordner umstellen. Regressionstest für
// v0.25.0, wo das Nutzer-Dropdown leer blieb (users-Prop war in
// SettingsApp.vue nicht angebunden) – nur „intern (AppData)" war wählbar.

// 1×1-Pixel-PNG – klein, aber eine echte Bilddatei.
const PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64',
)

test.describe('Einstellungsseite: Belegablage', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// Ausgangszustand ausdrücklich herstellen: interne Ablage. Die Seite
		// speichert immer den ganzen Satz (SettingsApp::saveSettings()), also
		// müssen auch die Felder anderer Specs gültig sein: resetBook() löscht
		// die Konten, ein von 11-contributions hinterlassenes einziehendes
		// Konto zeigte danach ins Leere und ließe jedes Speichern mit HTTP 400
		// scheitern. Der Wachordner aus 17-watchfolder aus demselben Grund mit.
		await api.updateSettings(request, {
			storage_user: '',
			storage_path: 'Vereinsbuchhaltung/Belege',
			sepa_debtor_account_id: '',
			statement_watch_user: '',
			statement_watch_path: '',
		})
	})

	test.afterAll(async ({ request }) => {
		// Erst den Bestand räumen, solange der NC-Modus noch aktiv ist – so
		// verschwinden auch die Beleg-Dateien im Nutzer-Home. Danach zurück
		// auf die interne Ablage, auf die sich die übrigen Specs verlassen.
		await api.resetBook(request)
		await api.updateSettings(request, { storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
	})

	test('Nutzer-Dropdown bietet die Nextcloud-Nutzer an, Auswahl lässt sich speichern', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_belege')
		const select = section.locator('select')

		// Der Kern der Regression: neben „— intern (AppData) —" müssen die
		// Nextcloud-Nutzer als Optionen auftauchen.
		await expect(select.locator('option[value=""]')).toHaveText(/intern \(AppData\)/)
		await expect(select.locator('option[value="admin"]')).toHaveCount(1)
		await expect(select.locator(`option[value="${USERS.verwalter}"]`)).toHaveCount(1)

		await select.selectOption('admin')
		await expect(section.getByText(/Belege werden unter/)).toBeVisible()
		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByText('Einstellungen gespeichert.').first()).toBeVisible()

		const settings = await api.getJson(request, '/settings')
		expect(settings.storage_user).toBe('admin')
	})

	test('mit gewähltem Nutzer landet der Beleg im Nutzer-Home statt in AppData', async ({ request }) => {
		// Vorbedingung selbst herstellen (kein Verlass auf den UI-Test davor).
		await api.updateSettings(request, { storage_user: 'admin', storage_path: 'Vereinsbuchhaltung/Belege' })

		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		const booking = await (await api.createBooking(request, {
			date: '2026-05-05',
			description: 'Beleg im Nutzer-Ordner',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 42,
		})).json()
		const attachment = await (await api.raw(request, 'POST', `/journal/${booking.id}/attachments`, {
			expectOk: true,
			multipart: {
				file: { name: 'beleg.png', mimeType: 'image/png', buffer: PNG },
			},
		})).json()

		// Ablageschema von AttachmentStorageService::getNcFilePath():
		// <Pfad>/<BuchungsID>/<BelegID>_<Dateiname> im Home des Nutzers –
		// dort per WebDAV sichtbar, wie in der Dateien-App.
		const dav = `${BASE_URL}/remote.php/dav/files/admin/Vereinsbuchhaltung/Belege/${booking.id}/${attachment.id}_beleg.png`
		const resp = await request.fetch(dav, { headers: authHeaders() })
		expect(resp.status()).toBe(200)
		expect((await resp.body()).length).toBe(PNG.length)
	})
})
