import { addUser, getContainer, runOcc, User } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { api, authHeaders, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BELEG_PNG, davUrl, INCOME_ACCOUNT, openSettingsPage, USERS } from './fixtures/nextcloud.mjs'

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
		// Erst die Art der Ablage: das Nutzer-Feld erscheint erst, wenn die
		// Belege im Nextcloud-Dateibaum liegen sollen.
		const modeSelect = section.locator('select').first()
		await expect(modeSelect.locator('option[value="appdata"]')).toHaveText(/intern \(AppData\)/)
		await modeSelect.selectOption('user')
		const select = section.locator('select').nth(1)

		await expect(select.locator('option[value="admin"]')).toHaveCount(1)
		await expect(select.locator(`option[value="${USERS.verwalter}"]`)).toHaveCount(1)

		await select.selectOption('admin')
		await expect(section.getByText(/Belege werden unter/)).toBeVisible()
		await section.getByRole('button', { name: 'Speichern' }).click()
		const toast = page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()
		await expect(toast).toBeVisible()
		// Transparenter Hintergrund = Toast-Stylesheet nicht geladen (styles.test.js).
		await expect(toast).not.toHaveCSS('background-color', 'rgba(0, 0, 0, 0)')
		// Oben rechts wie Nextclouds eigene Toasts, nicht in der Paket-Ecke
		// unten links (toast-position.css).
		const box = await toast.boundingBox()
		const viewport = page.viewportSize()
		expect(box.x + box.width / 2).toBeGreaterThan(viewport.width / 2)
		expect(box.y + box.height / 2).toBeLessThan(viewport.height / 2)

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

// Darstellung der Beträge: ab Werk neutral, der Verwalter schaltet Vorzeichen
// und Farbe in den Einstellungen ein (die Wirkung in den Listen prüft
// 03-bookings.spec.mjs).
test.describe('Einstellungsseite: Darstellung', () => {
	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { amount_display: 'plain' })
	})

	test('ab Werk neutral, Umschalten auf Vorzeichen und Farbe lässt sich speichern', async ({ page, request }) => {
		await api.updateSettings(request, { amount_display: 'plain' })
		expect((await api.getJson(request, '/settings')).amount_display).toBe('plain')

		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_darstellung')
		const select = section.locator('select')
		await expect(select).toHaveValue('plain')
		await select.selectOption('signed')
		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()).toBeVisible()

		expect((await api.getJson(request, '/settings')).amount_display).toBe('signed')
	})
})

// Ampel-Schwellen der visuellen Gliederungsansicht: eigener Abschnitt mit
// eigenem Speichern (die Wirkung auf die Ampel prüft 70-gliederungsbericht-visuell).
test.describe('Einstellungsseite: Ampel im Gliederungsbericht', () => {
	const DEFAULTS = { expWarn: 80, expCrit: 120, incGood: 100, incWarn: 80, resTol: 20 }
	const FIELDS = [
		['expWarn', 'Ausgaben: orange ab'],
		['expCrit', 'Ausgaben: rot über'],
		['incGood', 'Einnahmen: grün ab'],
		['incWarn', 'Einnahmen: rot unter'],
		['resTol', 'Ergebnis: rot, wenn mehr als'],
	]

	test.beforeAll(async ({ request }) => {
		await api.updateSettings(request, { budget_status_thresholds: DEFAULTS })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { budget_status_thresholds: DEFAULTS })
	})

	function field(section, label) {
		return section.getByRole('spinbutton', { name: label })
	}

	test('Abschnitt nach der Darstellung; Speichern bleibt nach dem Neuladen; ungültige Kombination wird nicht gespeichert; Standardwerte', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_ampel')
		await expect(section.getByRole('heading', { name: 'Ampel im Gliederungsbericht' })).toBeVisible()
		const afterDisplay = await page.evaluate(() => {
			const display = document.querySelector('#settings-section_darstellung')
			const ampel = document.querySelector('#settings-section_ampel')
			return display.nextElementSibling === ampel
		})
		expect(afterDisplay, 'Ampel-Abschnitt folgt direkt auf „Darstellung“').toBe(true)
		for (const [key, label] of FIELDS) {
			await expect(field(section, label)).toHaveValue(String(DEFAULTS[key]))
		}

		await field(section, 'Ausgaben: orange ab').fill('90')
		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()).toBeVisible()
		expect((await api.getSettings(request)).budget_status_thresholds).toEqual({ ...DEFAULTS, expWarn: 90 })

		await openSettingsPage(page, USERS.admin)
		await expect(field(section, 'Ausgaben: orange ab')).toHaveValue('90')

		// Orange ab 130 liegt über Rot ab 120: Meldung in der Karte, nichts gespeichert.
		let posts = 0
		page.on('request', (r) => {
			if (r.method() === 'POST' && r.url().endsWith('/api/settings')) { posts++ }
		})
		await field(section, 'Ausgaben: orange ab').fill('130')
		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect(section.getByRole('alert')).toHaveText('Bei den Ausgaben muss die Schwelle für Orange unter der für Rot liegen.')
		expect(posts).toBe(0)
		expect((await api.getSettings(request)).budget_status_thresholds).toEqual({ ...DEFAULTS, expWarn: 90 })

		await section.getByRole('button', { name: 'Standardwerte' }).click()
		for (const [key, label] of FIELDS) {
			await expect(field(section, label)).toHaveValue(String(DEFAULTS[key]))
		}
		await expect(section.getByRole('alert')).toHaveCount(0)
		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect.poll(async () => (await api.getSettings(request)).budget_status_thresholds).toEqual(DEFAULTS)
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

test.describe('Einstellungsseite: Ablageordner der Rechnungen', () => {
	// Die Karten des Rechnungsmoduls stehen nur bei eingeschaltetem Modul da.
	let invoicesWereActive = false

	test.beforeAll(async ({ request }) => {
		invoicesWereActive = (await api.getSettings(request)).invoice_module_active === true
		await api.updateSettings(request, { invoice_module_active: '1' })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege', invoice_folder: '', invoice_module_active: invoicesWereActive ? '1' : '0' })
	})

	// Issue #212: der Pfad beginnt im Home des Ablage-Nutzers. Wer das nicht
	// sieht, legt neben dem gemeinsamen Ordner unbemerkt einen zweiten an.
	test('zeigt Ablage-Nutzer und vollständigen Pfad und bietet die Ordnerwahl an', async ({ page, request }) => {
		await api.updateSettings(request, { storage_user: 'admin', storage_path: 'Vereinsbuchhaltung/Belege', invoice_folder: '' })
		await openSettingsPage(page, USERS.admin)
		const card = page.locator('#settings-section_rechnungen-zahlung')
		await expect(card).toContainText('Ablage-Nutzer: admin')
		await expect(card.locator('code')).toHaveText('Vereinsbuchhaltung/Rechnungen/<Jahr>/')
		await expect(card.getByRole('button', { name: 'Ordner wählen…' })).toBeVisible()

		await card.getByLabel('Ablageordner für Rechnungs-Dateien').fill('/Verein/Finanzen/Rechnungen/')
		await expect(card.locator('code')).toHaveText('Verein/Finanzen/Rechnungen/<Jahr>/')
	})

	test('ohne Ablage-Nutzer bleibt der Hinweis auf die interne Ablage', async ({ page, request }) => {
		await api.updateSettings(request, { storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
		await openSettingsPage(page, USERS.admin)
		const card = page.locator('#settings-section_rechnungen-zahlung')
		await expect(card).toContainText('speichert die App sie intern')
		await expect(card.getByRole('button', { name: 'Ordner wählen…' })).toHaveCount(0)
	})
})

// Neuordnung der Seite: Inhaltsverzeichnis, Hilfe-Links, ausgeschaltete
// Module ohne ihre Karten, Modulschalter wirken sofort, jede Karte speichert
// nur ihre eigenen Felder.
test.describe('Einstellungsseite: Inhaltsverzeichnis, Module und Speichern je Karte', () => {
	let before
	let donationsBefore

	const savedToast = (page) => page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()
	const donationsEnabled = async (request) => (await api.getJson(request, '/donation-receipts/settings')).donation_receipts_enabled

	test.beforeAll(async ({ request }) => {
		before = await api.getSettings(request)
		donationsBefore = await donationsEnabled(request)
		await api.updateSettings(request, {
			invoice_module_active: '0',
			cost_centers_enabled: '0',
			reimbursement_enabled: '0',
			amount_display: 'plain',
			club_name: 'TSV E2E e. V.',
		})
		await api.raw(request, 'PUT', '/donation-receipts/settings', { data: { donation_receipts_enabled: false } })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, {
			invoice_module_active: before.invoice_module_active ? '1' : '0',
			cost_centers_enabled: before.cost_centers_enabled ? '1' : '0',
			amount_display: before.amount_display,
			club_name: before.club_name,
			club_street: before.club_street ?? '',
		})
		await api.updateSettings(request, {
			reimbursement_enabled: before.reimbursement_enabled ? '1' : '0',
			reimbursement_required_approvals: String(before.reimbursement_required_approvals),
		}, { expectOk: false })
		await api.raw(request, 'PUT', '/donation-receipts/settings', { data: { donation_receipts_enabled: !!donationsBefore } })
	})

	test('Inhaltsverzeichnis springt zu den Abschnitten, jede Überschrift verweist ins Handbuch', async ({ page }) => {
		await openSettingsPage(page, USERS.admin)
		const toc = page.getByTestId('settings-toc')
		await expect(toc).toBeVisible()
		// Die alten Anker gelten weiter, auch in der neuen Reihenfolge.
		for (const id of ['verein', 'berechtigungen', 'belege', 'bankdaten', 'geschaeftsjahr', 'darstellung', 'ampel', 'beitraege-sepa', 'rechnungen', 'auslagen', 'kostenstellen', 'daten', 'sicherung']) {
			await expect(page.locator(`#settings-section_${id}`)).toHaveCount(1)
		}

		await toc.getByRole('link', { name: 'Sicherung und Umzug' }).click()
		await expect(page).toHaveURL(/#settings-section_sicherung$/)
		await expect(page.locator('#settings-section_sicherung').getByRole('heading', { name: 'Sicherung und Umzug' })).toBeInViewport()

		const help = page.locator('#settings-section_verein .settings-section__info')
		await expect(help).toHaveAttribute('href', /\/help\/handbuch#section-2-5$/)
	})

	test('ausgeschaltetes Rechnungsmodul zeigt nur seinen Schalter; der Schalter wirkt sofort', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_rechnungen')
		const tocEntry = page.getByTestId('settings-toc').getByRole('listitem').filter({ hasText: 'Rechnungen' })
		await expect(tocEntry).toContainText('ausgeschaltet')
		await expect(section.getByTestId('settings-module-off-rechnungen')).toBeVisible()
		await expect(section.getByRole('heading', { name: 'Steuer, Zahlung und Texte' })).toHaveCount(0)

		// Kein Speichern-Knopf mehr: der Schalter speichert selbst.
		await section.getByText('Reiter „Rechnungen“ anzeigen').click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()).toBeVisible()
		await expect(section.getByRole('heading', { name: 'Steuer, Zahlung und Texte' })).toBeVisible()
		await expect(tocEntry).not.toContainText('ausgeschaltet')
		await expect.poll(async () => (await api.getSettings(request)).invoice_module_active).toBe(true)

		await section.getByText('Reiter „Rechnungen“ anzeigen').click()
		await expect(section.getByTestId('settings-module-off-rechnungen')).toBeVisible()
		await expect.poll(async () => (await api.getSettings(request)).invoice_module_active).toBe(false)
	})

	test('Angaben des Vereins stehen unter „Verein“ – auch mit Rechnungen aus und Zuwendungsbestätigungen an', async ({ page, request }) => {
		await api.raw(request, 'PUT', '/donation-receipts/settings', { data: { donation_receipts_enabled: true } })
		await openSettingsPage(page, USERS.admin)
		await expect(page.locator('#settings-section_rechnungen').getByTestId('settings-module-off-rechnungen')).toBeVisible()
		await expect(page.locator('#settings-section_zuwendungen').getByTestId('settings-module-off-zuwendungen')).toHaveCount(0)

		// Der alte Anker der Karte gilt weiter (Prüfliste des Rechnungseditors), jetzt im Abschnitt „Verein“.
		const club = page.locator('#settings-section_verein #settings-section_rechnungen-angaben')
		await expect(club.getByRole('heading', { name: 'Angaben des Vereins', exact: true })).toBeVisible()
		const value = `Vereinsweg ${Date.now() % 1000}`
		await club.getByLabel('Straße und Hausnummer').fill(value)
		await club.getByRole('button', { name: 'Speichern' }).click()
		await expect(savedToast(page)).toBeVisible()
		await expect.poll(async () => (await api.getSettings(request)).club_street).toBe(value)

		// Sprung der Prüfliste landet auf der Karte.
		await page.goto(page.url().replace(/#.*$/, '') + '#settings-section_rechnungen-angaben')
		await page.reload()
		await expect(page.locator('#settings-section_rechnungen-angaben').getByRole('heading', { name: 'Angaben des Vereins', exact: true })).toBeInViewport()
		await api.raw(request, 'PUT', '/donation-receipts/settings', { data: { donation_receipts_enabled: false } })
	})

	test('Zuwendungsbestätigungen: der Schalter wirkt sofort und blendet die Angaben ein und aus', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_zuwendungen')
		await expect(section.getByTestId('settings-module-off-zuwendungen')).toBeVisible()
		await section.getByText('Zuwendungsbestätigungen ausstellen').click()
		await expect(savedToast(page)).toBeVisible()
		await expect(section.getByTestId('settings-module-off-zuwendungen')).toHaveCount(0)
		await expect(section.getByRole('button', { name: 'Speichern' })).toBeVisible()
		await expect.poll(() => donationsEnabled(request)).toBe(true)

		await section.getByText('Zuwendungsbestätigungen ausstellen').click()
		await expect(section.getByTestId('settings-module-off-zuwendungen')).toBeVisible()
		await expect(section.getByRole('button', { name: 'Speichern' })).toHaveCount(0)
		await expect.poll(() => donationsEnabled(request)).toBe(false)
	})

	test('Auslagenerstattung: der Schalter wirkt sofort, springt bei zu wenigen Verwaltern zurück und blendet die Auslagennummern ein', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_auslagen')
		const toggle = section.getByRole('switch', { name: 'Auslagenerstattung aktivieren' })
		const numbers = section.getByRole('heading', { name: 'Auslagennummern' })
		await expect(toggle).not.toBeChecked()
		await expect(numbers).toHaveCount(0)

		// Mehr Freigaben, als es Verwalter gibt: der Server lehnt ab, der Schalter springt zurück.
		await section.getByLabel('Nötige Freigaben').fill('50')
		await section.getByText('Auslagenerstattung aktivieren').click()
		await expect(section.getByRole('alert')).toContainText('Verwalter')
		await expect(toggle).not.toBeChecked()
		expect((await api.getSettings(request)).reimbursement_enabled).toBe(false)

		await section.getByLabel('Nötige Freigaben').fill('1')
		await section.getByText('Auslagenerstattung aktivieren').click()
		await expect(toggle).toBeChecked()
		await expect(numbers).toBeVisible()
		await expect.poll(async () => (await api.getSettings(request)).reimbursement_enabled).toBe(true)

		await section.getByText('Auslagenerstattung aktivieren').click()
		await expect(toggle).not.toBeChecked()
		await expect(numbers).toHaveCount(0)
		await expect.poll(async () => (await api.getSettings(request)).reimbursement_enabled).toBe(false)
	})

	test('Kostenstellen-Schalter wirkt sofort', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_kostenstellen')
		await expect(section.getByRole('button', { name: 'Speichern' })).toHaveCount(0)
		await section.getByText('Kostenstellen aktivieren').click()
		await expect.poll(async () => (await api.getSettings(request)).cost_centers_enabled).toBe(true)
		await section.getByText('Kostenstellen aktivieren').click()
		await expect.poll(async () => (await api.getSettings(request)).cost_centers_enabled).toBe(false)
	})

	test('Speichern einer Karte nimmt Eingaben anderer Karten nicht mit', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		// Vereinsname geändert, aber nicht gespeichert …
		await page.locator('#settings-section_verein').getByLabel('Vereinsname (erscheint im Kopf des Kassenberichts)').fill('Nicht gespeichert e. V.')
		// … dann nur die Darstellung gespeichert.
		const display = page.locator('#settings-section_darstellung')
		await display.locator('select').selectOption('signed')
		await display.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()).toBeVisible()

		const saved = await api.getSettings(request)
		expect(saved.amount_display).toBe('signed')
		expect(saved.club_name).toBe('TSV E2E e. V.')
	})
})
