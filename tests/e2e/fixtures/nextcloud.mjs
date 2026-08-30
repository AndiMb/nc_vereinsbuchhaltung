import { existsSync, mkdirSync, readFileSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

// Gemeinsame Helfer der E2E-Suite: Anmeldung mit Zustands-Cache und die
// API-Aufrufe, mit denen sich die Specs ihren Buchungsbestand aufsetzen.
// Seeding läuft über die HTTP-API (Basic Auth statt Session-Cookie – damit
// entfällt die CSRF-Prüfung), geprüft wird dann in der Oberfläche.

const __dirname = dirname(fileURLToPath(import.meta.url))
export const AUTH_DIR = join(__dirname, '..', '.auth')
export const FIXTURES_DIR = join(__dirname, '..', '..', 'fixtures')

export const BASE_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
// App-POSTs brauchen /index.php, sonst antwortet der Server mit Umleitungen.
const API = `${BASE_URL}/index.php/apps/vereinsbuchhaltung/api`

export const USERS = {
	admin: 'admin', // Nextcloud-Admin = automatisch App-Verwalter
	verwalter: 'test1',
	buchhalter: 'test2',
	revisor: 'test3',
	ohneRolle: 'test4',
	englisch: 'test5', // startet ohne App-Rolle, Oberflächensprache Englisch
}

// Was in tests/fixtures/ liegt: derselbe Kontoauszug in drei Formaten.
// Wer der Beispieldatei Zeilen hinzufügt, passt die Zahlen hier an –
// nirgendwo sonst.
export const CAMT_STATEMENT = {
	csv: 'beispiel-camt.csv',
	camt053: 'beispiel-camt053.xml',
	mt940: 'beispiel-mt940.sta',
	txCount: 5,
	// Zeilen, deren Verwendungszweck "Mitgliedsbeitrag" enthält
	memberFeeCount: 2,
}

// Der mitgelieferte Standard-Kontenrahmen: die zwei Konten, mit denen die
// Specs buchen.
export const BANK_ACCOUNT = '1200'
export const INCOME_ACCOUNT = '4000'

// Beleg-Fixture: ein 1×1-Pixel-PNG – klein, aber eine echte Bilddatei.
export const BELEG_PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64',
)

/** WebDAV-Adresse einer Datei oder eines Ordners im Home eines Nutzers. */
export function davUrl(username, path) {
	return `${BASE_URL}/remote.php/dav/files/${username}/${path}`
}

export function authHeaders(username = 'admin', password = null) {
	return {
		Authorization: 'Basic ' + Buffer.from(`${username}:${password ?? username}`).toString('base64'),
		'Content-Type': 'application/json',
		'OCS-APIREQUEST': 'true',
		Cookie: '',
	}
}

/**
 * Anmeldung über die Login-Seite, mit Cookie-Cache unter tests/e2e/.auth –
 * das Global-Setup leert den Cache, wenn die Datenbank zurückgesetzt wurde.
 */
async function login(page, username, password = null) {
	const pwd = password ?? username
	if (!existsSync(AUTH_DIR)) {
		mkdirSync(AUTH_DIR, { recursive: true })
	}
	const statePath = join(AUTH_DIR, `${username}.json`)

	if (existsSync(statePath)) {
		try {
			const state = JSON.parse(readFileSync(statePath, 'utf-8'))
			if (state.cookies && state.cookies.length > 0) {
				await page.context().addCookies(state.cookies)
				await page.goto(`${BASE_URL}/apps/dashboard/`)
				if (!page.url().includes('/login')) {
					return
				}
			}
		} catch { /* Cache unbrauchbar – frisch anmelden */ }
	}

	await page.context().clearCookies()
	await page.goto(`${BASE_URL}/login`)
	await page.waitForLoadState('networkidle')
	// Sprachneutral: die Login-Seite folgt dem Accept-Language des Browsers.
	await page.getByRole('textbox', { name: /account name|email|kontoname/i }).fill(username)
	await page.getByRole('textbox', { name: /password|passwort/i }).fill(pwd)
	await page.locator('form button[type="submit"]').first().click()
	await page.waitForURL(/.*\/apps\/.*/, { timeout: 10000 })

	try {
		await page.context().storageState({ path: statePath })
	} catch { /* Cache ist nur eine Beschleunigung */ }
}

/** Öffnet die App und wartet, bis die Oberfläche steht. */
export async function openApp(page, username) {
	await login(page, username)
	await page.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/`)
	await page.locator('.vbh').waitFor({ timeout: 15000 })
}

/**
 * Öffnet den Vereinsbuchhaltung-Abschnitt der Nextcloud-Einstellungen
 * (SettingsApp.vue) unter Verwaltung. App-Verwalter ohne Server-Admin-Rechte
 * finden dieselbe Seite unter Persönlich (/settings/user/...), siehe
 * PersonalSettings::getSection().
 */
export async function openSettingsPage(page, username) {
	await login(page, username)
	await page.goto(`${BASE_URL}/index.php/settings/admin/vereinsbuchhaltung`)
	await page.locator('#settings-section_belege').waitFor({ timeout: 15000 })
}

/**
 * Der sichtbare Tab-Inhalt. Die App hält alle Tabs per v-show gleichzeitig
 * im DOM – ungescopte Textsuchen träfen auch die unsichtbaren Abschnitte.
 */
export function visibleSection(page) {
	return page.locator('.vbh-section:visible')
}

/** Ein Tab-Knopf der App-Navigation (für Klicks und Sichtbarkeits-Prüfungen). */
export function tabButton(page, label) {
	return page.locator('.vbh-tabs').getByRole('button', { name: label })
}

/** Tab in der App-Navigation wechseln. */
export async function switchTab(page, label) {
	await tabButton(page, label).click()
}

/**
 * Geschäftsjahr im Kopfbereich wählen. Nach dem Laden steht der Filter auf
 * dem aktuellen Jahr – Buchungen anderer Jahre brauchen diesen Schritt.
 */
export async function selectYear(page, yearOrLabel) {
	await page.locator('.vbh-yearsel select').selectOption({ label: String(yearOrLabel) })
}

/**
 * Eine Option in einem NcSelect wählen: tippen, gefilterten Treffer
 * abwarten, mit Enter übernehmen. Bewusst per Tastatur statt Klick auf die
 * Option: je nach @nextcloud/vue-Version liegen Teile des Floating-Label-
 * Markups über der Optionsliste und fangen den Mausklick ab – Enter nimmt
 * immer die hervorgehobene (erste gefilterte) Option.
 *
 * @param scope Locator, der das NcSelect enthält (Dialog, Tabellenzeile …)
 */
export async function pickNcSelectOption(scope, placeholder, search) {
	const input = scope.getByPlaceholder(placeholder)
	await input.click()
	await input.pressSequentially(search, { delay: 20 })
	await scope.page().locator('li.vs__dropdown-option', { hasText: search }).first().waitFor()
	await input.press('Enter')
}

// ---------------------------------------------------------------------------
// API-Helfer (Seeding und Prüfungen außerhalb der Oberfläche)
// ---------------------------------------------------------------------------

async function call(request, method, path, { user = 'admin', data, multipart, expectOk = true } = {}) {
	const headers = authHeaders(user)
	if (multipart) {
		// Bei multipart setzt Playwright den Content-Type samt Boundary selbst.
		delete headers['Content-Type']
	}
	const resp = await request.fetch(`${API}${path}`, {
		method,
		headers,
		...(data !== undefined ? { data } : {}),
		...(multipart !== undefined ? { multipart } : {}),
	})
	if (expectOk && !resp.ok()) {
		throw new Error(`${method} ${path} als ${user}: HTTP ${resp.status()} – ${(await resp.text()).slice(0, 300)}`)
	}
	return resp
}

export const api = {
	/** Ganzen Buchungsbestand löschen – jede Spec-Datei startet damit. */
	async resetBook(request) {
		await call(request, 'POST', '/reset')
	},

	/** Mitgelieferten Standard-Kontenrahmen anlegen. */
	async seedDefaultAccounts(request) {
		await call(request, 'POST', '/accounts/seed')
	},

	async listAccounts(request) {
		return (await call(request, 'GET', '/accounts')).json()
	},

	/**
	 * Konten nach Nummern heraussuchen, mit einem einzigen Listen-Abruf.
	 * Wirft, wenn eine Nummer fehlt.
	 */
	async accountsByNumber(request, ...numbers) {
		const accounts = await this.listAccounts(request)
		return numbers.map((number) => {
			const match = accounts.find((a) => a.number === number)
			if (!match) {
				throw new Error(`Kein Konto mit Nummer ${number} – vorhanden: ${accounts.map((a) => a.number).join(', ')}`)
			}
			return match
		})
	},

	async accountByNumber(request, number) {
		return (await this.accountsByNumber(request, number))[0]
	},

	/** Buchung im Experten-Modus (Soll/Haben ausdrücklich). */
	async createBooking(request, { date, description, debitAccountId, creditAccountId, amount, user = 'admin', expectOk = true }) {
		return call(request, 'POST', '/journal', {
			user,
			expectOk,
			data: { date, description, debitAccountId, creditAccountId, amount },
		})
	},

	/** Beleg an eine Buchung hängen; liefert den angelegten Datensatz. */
	async addAttachment(request, journalId, { name = 'beleg.png', mimeType = 'image/png', buffer = BELEG_PNG, user = 'admin' } = {}) {
		return (await call(request, 'POST', `/journal/${journalId}/attachments`, {
			user,
			multipart: { file: { name, mimeType, buffer } },
		})).json()
	},

	async listAttachments(request, journalId) {
		return (await call(request, 'GET', `/journal/${journalId}/attachments`)).json()
	},

	async listJournal(request, { year = null } = {}) {
		const query = year ? `?year=${year}` : ''
		return (await call(request, 'GET', `/journal${query}`)).json()
	},

	/** Kontoauszug importieren; content ist der rohe Dateiinhalt. */
	async importStatement(request, content, { filename = 'auszug.csv', user = 'admin' } = {}) {
		return (await call(request, 'POST', '/import/commit', { user, data: { content, filename } })).json()
	},

	async listTransactions(request, { status = null } = {}) {
		const query = status ? `?status=${status}` : ''
		return (await call(request, 'GET', `/transactions${query}`)).json()
	},

	async closeYear(request, year, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/years/${year}/close`, { user, expectOk })
	},

	async reopenYear(request, year, { user = 'admin' } = {}) {
		return call(request, 'DELETE', `/years/${year}/close`, { user })
	},

	async updateSettings(request, settings, { user = 'admin' } = {}) {
		return call(request, 'POST', '/settings', { user, data: settings })
	},

	/** App-Rolle vergeben (nur Verwalter dürfen das). */
	async setRole(request, principalId, role, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', '/permissions', {
			user,
			expectOk,
			data: { principalType: 'user', principalId, role },
		})
	},

	async seedDemo(request, { user = 'admin' } = {}) {
		return call(request, 'POST', '/demo/seed', { user })
	},

	async createRule(request, { matchField = 'description', matchValue, contraAccountId, user = 'admin' }) {
		return call(request, 'POST', '/rules', { user, data: { matchField, matchValue, contraAccountId } })
	},

	async createOpenItem(request, { debtor, description, amount, dueDate, user = 'admin' }) {
		return (await call(request, 'POST', '/open-items', { user, data: { debtor, description, amount, dueDate } })).json()
	},

	/** GET mit Erfolgserwartung, direkt als JSON. */
	async getJson(request, path, opts = {}) {
		return (await call(request, 'GET', path, opts)).json()
	},

	/** Roher Zugriff für Spezialfälle; expectOk standardmäßig aus. */
	async raw(request, method, path, opts = {}) {
		return call(request, method, path, { expectOk: false, ...opts })
	},
}

/** Fixture-Datei (Kontoauszüge usw.) als String. */
export function fixture(name) {
	return readFileSync(join(FIXTURES_DIR, name), 'utf-8')
}
