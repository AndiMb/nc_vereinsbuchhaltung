import { addUser, getContainer, runOcc, User } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, openSettingsPage, authHeaders, davUrl, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BELEG_PNG, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Die Einstellungsseite (Nextcloud-Einstellungen → Vereinsbuchhaltung):
// Belegablage von der internen Ablage auf den Ordner eines Nextcloud-Nutzers
// umstellen – Auswahlfeld, Speichern und die abgelegte Datei.

test.describe('Einstellungsseite: Belegablage', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// Ausgangszustand ausdrücklich herstellen: interne Ablage.
		await api.updateSettings(request, {
			storage_user: '',
			storage_path: 'Vereinsbuchhaltung/Belege',
		})
	})

	test.afterAll(async ({ request }) => {
		// Aufräumen, solange der NC-Modus noch aktiv ist: nur dann räumt
		// resetBook() auch die Beleg-Dateien im Nutzer-Home ab. Der
		// Datenbank-Schnappschuss des Global-Setups setzt zwar die
		// Einstellungen zurück, nicht aber das Dateisystem – ohne das hier
		// sammelten sich die Belege über die Läufe hinweg an.
		await api.resetBook(request)
		await api.updateSettings(request, { storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
	})

	test('Nutzer-Dropdown bietet die Nextcloud-Nutzer an, Auswahl lässt sich speichern', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_belege')
		const select = section.locator('select')

		await expect(select.locator('option[value=""]')).toHaveText(/intern \(AppData\)/)
		await expect(select.locator('option[value="admin"]')).toHaveCount(1)
		await expect(select.locator(`option[value="${USERS.verwalter}"]`)).toHaveCount(1)

		await select.selectOption('admin')
		await expect(section.getByText(/Belege werden unter/)).toBeVisible()
		await section.getByRole('button', { name: 'Speichern' }).click()
		const toast = page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()
		await expect(toast).toBeVisible()
		// Transparenter Hintergrund = Toast-Stylesheet nicht geladen (styles.test.js).
		await expect(toast).not.toHaveCSS('background-color', 'rgba(0, 0, 0, 0)')

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
		const attachment = await api.addAttachment(request, booking.id)

		// Ablageschema von AttachmentStorageService::getNcFilePath():
		// <Pfad>/<BuchungsID>/<BelegID>_<Dateiname> im Home des Nutzers –
		// dort per WebDAV sichtbar, wie in der Dateien-App.
		const dav = davUrl('admin', `Vereinsbuchhaltung/Belege/${booking.id}/${attachment.id}_beleg.png`)
		const resp = await request.fetch(dav, { headers: authHeaders() })
		expect(resp.status()).toBe(200)
		expect((await resp.body()).length).toBe(BELEG_PNG.length)
	})
})

// Das einziehende Konto zeigt als einzige Einstellung auf einen Datensatz der
// App. Verwaist sie, darf das nicht die ganze Seite unspeicherbar machen –
// die sendet immer den vollständigen Feldsatz.
test.describe('Einstellungsseite: einziehendes Konto blockiert nichts', () => {
	/** Frischer Kontenrahmen, Geldkonto mit IBAN als einziehendes gesetzt. */
	async function seedDebtorAccount(request) {
		await api.resetBook(request)
		const accounts = await api.seedDefaultAccounts(request)
		const bank = accounts.find((a) => a.number === BANK_ACCOUNT)
		await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
		const saved = await (await api.updateSettings(request, { sepa_debtor_account_id: bank.id })).json()
		expect(saved.sepa_debtor_account_id).toBe(bank.id)
		return bank
	}

	test('„Alle Daten löschen" räumt das einziehende Konto mit ab', async ({ request }) => {
		await seedDebtorAccount(request)

		await api.resetBook(request)
		expect((await api.getSettings(request)).sepa_debtor_account_id).toBeNull()
	})

	test('Löschen des Kontos selbst räumt die Einstellung ebenfalls ab', async ({ request }) => {
		const bank = await seedDebtorAccount(request)

		await api.deleteAccount(request, bank.id)
		expect((await api.getSettings(request)).sepa_debtor_account_id).toBeNull()
	})

	test('ein nachträglich ungültiges Konto blockiert nur seine eigene Änderung', async ({ request }) => {
		const bank = await seedDebtorAccount(request)
		// Ohne IBAN lässt sich über das Konto nichts mehr einziehen.
		await api.updateAccount(request, bank.id, { iban: '' })

		// Unverändert mitgesendet, wie es die Seite tut: darf nicht stören.
		const saved = await (await api.updateSettings(request, {
			club_name: 'Trotzdem speicherbar e.V.',
			sepa_debtor_account_id: bank.id,
		})).json()
		expect(saved.club_name).toBe('Trotzdem speicherbar e.V.')

		// Es neu auszuwählen bleibt ein Fehler mit klarer Meldung.
		await api.updateSettings(request, { sepa_debtor_account_id: '' })
		const rejected = await api.updateSettings(request, { sepa_debtor_account_id: bank.id }, { expectOk: false })
		expect(rejected.status()).toBe(400)
		expect((await rejected.json()).message).toMatch(/IBAN/)
	})
})

// storage_user und statement_watch_user zeigen als einzige Einstellungen auf
// einen Nextcloud-Nutzer. Verschwindet der, darf das weder unbemerkt bleiben
// noch die ganze Seite unspeicherbar machen – sie sendet immer den
// vollständigen Feldsatz.
test.describe('Einstellungsseite: gelöschter Nextcloud-Nutzer blockiert nichts', () => {
	// Eigener Wegwerf-Nutzer: test1–test5 aus dem Snapshot brauchen die
	// übrigen Specs noch.
	const TEMP_USER = 'wegwerf'
	const container = getContainer()
	let userExists = false

	/**
	 * Wartet, bis über occ geschriebene Einstellungen im Webprozess ankommen.
	 *
	 * Nextcloud hält die App-Konfiguration prozesslokal in APCu (drei Sekunden,
	 * OC\AppConfig::LOCAL_CACHE_TTL). Ein Schreibzugriff leert nur den Cache des
	 * schreibenden Prozesses – occ und der Webserver haben getrennte Segmente,
	 * ohne dieses Warten läse der nächste Request noch den alten Stand. Der
	 * erste Versuch wartet die TTL deshalb gleich ab.
	 */
	async function waitForSettings(request, expected) {
		await expect.poll(() => api.getSettings(request), { timeout: 15000, intervals: [3000, 500] }).toMatchObject(expected)
	}

	function setViaOcc(key, value) {
		return runOcc(['config:app:set', 'vereinsbuchhaltung', key, '--value', value], { container })
	}

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, {
			storage_user: '',
			storage_path: 'Vereinsbuchhaltung/Belege',
			statement_watch_user: '',
			statement_watch_path: '',
		})
		if (userExists) {
			await runOcc(['user:delete', TEMP_USER], { container }) // nur wenn ein Test vor dem Löschen abbrach
		}
	})

	test('Löschen des Nutzers räumt Belegablage und Wachordner mit ab', async ({ request }) => {
		test.setTimeout(60000) // occ user:add/user:delete im Container
		await addUser(new User(TEMP_USER, `${TEMP_USER}-nur-fuer-den-test`), { container })
		userExists = true

		const saved = await (await api.updateSettings(request, {
			storage_user: TEMP_USER,
			storage_path: 'Vereinsbuchhaltung/Belege',
			statement_watch_user: TEMP_USER,
			statement_watch_path: 'Auszuege',
		})).json()
		expect(saved.storage_user).toBe(TEMP_USER)
		expect(saved.statement_watch_user).toBe(TEMP_USER)

		await runOcc(['user:delete', TEMP_USER], { container })
		userExists = false

		await waitForSettings(request, {
			storage_user: '', // zurück auf die app-interne Ablage
			// Nutzer UND Pfad: halb ausgefüllt wäre der Wachordner an, fände aber nie etwas.
			statement_watch_user: '',
			statement_watch_path: '',
		})
	})

	test('ein verwaister Nutzer blockiert nur seine eigene Änderung', async ({ request }) => {
		// Von Hand gesetzt statt über eine Löschung: nicht jede Löschung erreicht
		// den UserDeletedListener. Genau dafür ist die zweite Ebene im
		// Controller da, siehe SettingsController::validateUser().
		await setViaOcc('storage_user', TEMP_USER)
		await setViaOcc('statement_watch_user', TEMP_USER)
		await setViaOcc('statement_watch_path', 'Auszuege')
		await waitForSettings(request, { storage_user: TEMP_USER })

		// Unverändert mitgesendet, wie es die Seite tut: darf nicht stören.
		const saved = await (await api.updateSettings(request, {
			club_name: 'Trotzdem speicherbar e.V.',
			storage_user: TEMP_USER,
			storage_path: 'Vereinsbuchhaltung/Belege',
			statement_watch_user: TEMP_USER,
			statement_watch_path: 'Auszuege',
		})).json()
		expect(saved.club_name).toBe('Trotzdem speicherbar e.V.')
		expect(saved.storage_user).toBe(TEMP_USER)

		// Auch der Pfad daneben bleibt änderbar: der Nutzer wird als
		// Paarhälfte ergänzt, nicht neu gewählt.
		const repathed = await (await api.updateSettings(request, {
			statement_watch_path: 'Auszuege/2026',
		})).json()
		expect(repathed.statement_watch_path).toBe('Auszuege/2026')

		// Einen anderen, nicht existierenden Nutzer zu wählen bleibt ein Fehler.
		const rejected = await api.updateSettings(request, {
			statement_watch_user: 'gibtesnicht',
			statement_watch_path: 'Auszuege',
		}, { expectOk: false })
		expect(rejected.status()).toBe(400)
		expect((await rejected.json()).message).toMatch(/existiert nicht/)
	})
})
