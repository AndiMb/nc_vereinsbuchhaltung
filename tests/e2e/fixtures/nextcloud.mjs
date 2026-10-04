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
// IBAN des vereinseigenen Geldkontos – Voraussetzung dafür, dass es als
// einziehendes Konto des SEPA-Sammeleinzugs taugt.
export const BANK_ACCOUNT_IBAN = 'DE12500105170648489890'

// ---------------------------------------------------------------------------
// camt.053-Bausteine für den Bankabgleich (Issue #105)
// ---------------------------------------------------------------------------
//
// Der Bankabgleich arbeitet mit dem, was die Bank im Kontoauszug meldet: die
// Sammelgutschrift eines Einzugs mit einer Zeile je Posten (End-to-End-ID,
// Mandatsreferenz, Betrag) und die Rücklastschrift mit Rückgabegrund. Die
// Beispieldatei tests/fixtures/beispiel-camt053.xml kennt nichts davon (und
// trägt feste IDs, die zu keinem frisch freigegebenen Lauf passen) – die
// Specs bauen sich ihren Auszug deshalb aus den Posten ihres Laufs.

const xmlText = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const euros = (cents) => (Math.abs(cents) / 100).toFixed(2)

/**
 * Eine Detail-Zeile (`TxDtls`). Nur gesetzte Felder erscheinen im XML – so lässt
 * sich auch die Zeile ohne End-to-End-ID bauen, die nur über Mandatsreferenz
 * und Betrag (Stufe 2) zu einem Posten findet.
 *
 * `amountCents` ist der eigene Betrag der Zeile (bei einer Rückgabe mit Gebühr:
 * Original plus Gebühr), `originalAmountCents` der Ursprungsbetrag der
 * Lastschrift, `chargesCents` die Bankgebühr.
 */
function camtDetail(detail, direction) {
	const parts = []
	if (detail.endToEndId || detail.mandateReference) {
		parts.push(`<Refs>${detail.endToEndId ? `<EndToEndId>${xmlText(detail.endToEndId)}</EndToEndId>` : ''}${detail.mandateReference ? `<MndtId>${xmlText(detail.mandateReference)}</MndtId>` : ''}</Refs>`)
	}
	if (detail.amountCents !== undefined) {
		parts.push(`<Amt Ccy="EUR">${euros(detail.amountCents)}</Amt><CdtDbtInd>${direction}</CdtDbtInd>`)
	}
	if (detail.originalAmountCents !== undefined) {
		parts.push(`<AmtDtls><TxAmt><Amt Ccy="EUR">${euros(detail.originalAmountCents)}</Amt></TxAmt></AmtDtls>`)
	}
	if (detail.chargesCents !== undefined) {
		parts.push(`<Chrgs><TotalChargesAndTaxAmt Ccy="EUR">${euros(detail.chargesCents)}</TotalChargesAndTaxAmt></Chrgs>`)
	}
	if (detail.counterparty) {
		const side = direction === 'CRDT' ? 'Dbtr' : 'Cdtr'
		parts.push(`<RltdPties><${side}><Nm>${xmlText(detail.counterparty)}</Nm></${side}>${detail.counterpartyIban ? `<${side}Acct><Id><IBAN>${detail.counterpartyIban}</IBAN></Id></${side}Acct>` : ''}</RltdPties>`)
	}
	if (detail.purpose) {
		parts.push(`<RmtInf><Ustrd>${xmlText(detail.purpose)}</Ustrd></RmtInf>`)
	}
	if (detail.returnReasonCode) {
		parts.push(`<RtrInf><Rsn><Cd>${detail.returnReasonCode}</Cd></Rsn>${detail.returnReasonText ? `<AddtlInf>${xmlText(detail.returnReasonText)}</AddtlInf>` : ''}</RtrInf>`)
	}
	return `<TxDtls>${parts.join('')}</TxDtls>`
}

/**
 * Ein camt.053-Kontoauszug (XML) aus Umsätzen.
 *
 * Umsatz: `{ bookingDate, direction: 'CRDT'|'DBIT', amountCents, bookingText?,
 * batchReference?, details: [...] }` – die Detail-Zeilen siehe camtDetail().
 * Ohne `details` entsteht eine einzelne Zeile aus den Feldern des Umsatzes
 * (`counterparty`, `counterpartyIban`, `purpose`), wie bei einer gewöhnlichen
 * Überweisung: sie trägt keine SEPA-Referenzen und erzeugt deshalb keine
 * Detail-Zeile im Bankabgleich.
 */
export function camtStatement({ iban = BANK_ACCOUNT_IBAN, entries }) {
	const body = entries.map((entry) => {
		const details = entry.details ?? [{ counterparty: entry.counterparty, counterpartyIban: entry.counterpartyIban, purpose: entry.purpose }]
		return `<Ntry>
				<Amt Ccy="EUR">${euros(entry.amountCents)}</Amt><CdtDbtInd>${entry.direction}</CdtDbtInd><Sts>BOOK</Sts>
				<BookgDt><Dt>${entry.bookingDate}</Dt></BookgDt><ValDt><Dt>${entry.bookingDate}</Dt></ValDt>
				<NtryDtls>
					${entry.batchReference ? `<Btch><PmtInfId>${xmlText(entry.batchReference)}</PmtInfId></Btch>` : ''}
					${details.map((detail) => camtDetail(detail, entry.direction)).join('\n\t\t\t\t\t')}
				</NtryDtls>
				<AddtlNtryInf>${xmlText(entry.bookingText ?? (entry.direction === 'CRDT' ? 'GUTSCHRIFT' : 'LASTSCHRIFT'))}</AddtlNtryInf>
			</Ntry>`
	}).join('\n\t\t\t')
	return `<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
	<BkToCstmrStmt>
		<GrpHdr><MsgId>E2E-${Date.now()}</MsgId><CreDtTm>${new Date().toISOString().slice(0, 19)}</CreDtTm></GrpHdr>
		<Stmt>
			<Id>AUSZUG-${Date.now()}</Id>
			<Acct><Id><IBAN>${iban}</IBAN></Id><Ccy>EUR</Ccy></Acct>
			${body}
		</Stmt>
	</BkToCstmrStmt>
</Document>`
}

/**
 * Die Sammelgutschrift eines eigenen Einzugs: ein Umsatz über die Summe, mit
 * einer Zeile je Posten des Laufs (`batch.items` aus api.getDebitBatch()).
 *
 * @param opts.omitEndToEndId Posten-IDs, deren Zeile ohne End-to-End-ID gemeldet wird: sie findet nur über Mandatsreferenz und Betrag zu ihrem Posten
 */
export function collectionEntry({ bookingDate, items, omitEndToEndId = [], batchReference = `PMTINF-${Date.now()}` }) {
	return {
		bookingDate,
		direction: 'CRDT',
		amountCents: items.reduce((sum, item) => sum + item.amountCents, 0),
		bookingText: 'SEPA-LASTSCHRIFT-EINREICHUNG',
		batchReference,
		details: items.map((item) => ({
			endToEndId: omitEndToEndId.includes(item.id) ? undefined : item.endToEndId,
			mandateReference: item.mandateReference,
			amountCents: item.amountCents,
			purpose: `${item.remittanceInfo} ${item.memberDisplayName}`,
		})),
	}
}

/**
 * Die Rücklastschrift eines Postens: die Bank belastet das Konto um den
 * Ursprungsbetrag plus ihre Gebühr und nennt Rückgabegrund und End-to-End-ID.
 *
 * @param opts.item Posten aus `batch.items` (api.getDebitBatch())
 * @param opts.reasonCode ISO-Rückgabegrund, z. B. AM04 (Deckung fehlt: Zahlungsaufforderung) oder AC04 (Konto erloschen: Mandat wird gesperrt)
 */
export function returnEntry({ bookingDate, item, reasonCode, reasonText = undefined, chargesCents = undefined }) {
	return {
		bookingDate,
		direction: 'DBIT',
		amountCents: item.amountCents + (chargesCents ?? 0),
		bookingText: 'LASTSCHRIFT-RUECKGABE',
		details: [{
			endToEndId: item.endToEndId,
			mandateReference: item.mandateReference,
			originalAmountCents: item.amountCents,
			chargesCents,
			purpose: `Rücklastschrift ${item.memberDisplayName}`,
			returnReasonCode: reasonCode,
			returnReasonText: reasonText,
		}],
	}
}

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
 * WebDAV im Home eines Nutzers – so legen Sync-Client oder Dateien-App
 * Dateien ab, ohne die App zu kennen (Wachordner, Wächter-Ordner).
 */
export const dav = {
	async mkcol(request, user, path) {
		return request.fetch(davUrl(user, path), { method: 'MKCOL', headers: authHeaders(user) })
	},

	async put(request, user, path, buffer, contentType = 'application/octet-stream') {
		const resp = await request.fetch(davUrl(user, path), {
			method: 'PUT',
			headers: { ...authHeaders(user), 'Content-Type': contentType },
			data: buffer,
		})
		if (![201, 204].includes(resp.status())) {
			throw new Error(`PUT ${path} als ${user}: HTTP ${resp.status()}`)
		}
		return resp
	},

	async exists(request, user, path) {
		return (await request.fetch(davUrl(user, path), { method: 'HEAD', headers: authHeaders(user) })).status() === 200
	},

	async move(request, user, from, to) {
		const resp = await request.fetch(davUrl(user, from), {
			method: 'MOVE',
			headers: { ...authHeaders(user), Destination: davUrl(user, to) },
		})
		if (![201, 204].includes(resp.status())) {
			throw new Error(`MOVE ${from} → ${to} als ${user}: HTTP ${resp.status()}`)
		}
		return resp
	},

	async remove(request, user, path) {
		return request.fetch(davUrl(user, path), { method: 'DELETE', headers: authHeaders(user) })
	},
}

/**
 * /journal liefert je Buchung { journal: {...}, lines: [...] } – die Felder
 * der Buchung stecken eine Ebene tiefer als beim POST auf /journal.
 */
export function findBooking(journal, description) {
	return (journal.find((e) => e.journal.description === description) || {}).journal
}

/** Die Erste-Buchung-Tour erscheint nur einmal je Browserprofil – falls sie da ist, weg damit. */
export async function skipBookingTour(dialog) {
	const skip = dialog.getByRole('button', { name: 'Überspringen', exact: true })
	if (await skip.isVisible().catch(() => false)) {
		await skip.click()
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

/** Wartet, bis die App-Oberfläche steht – nach openApp(), aber auch nach einem eigenen goto()/reload(). */
export async function waitForAppLoaded(page) {
	await page.locator('.vbh').waitFor({ timeout: 15000 })
}

/** Öffnet die App und wartet, bis die Oberfläche steht. */
export async function openApp(page, username) {
	await login(page, username)
	await page.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/`)
	await waitForAppLoaded(page)
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

/** Konto im Kontenbaum (Tab Konten) anklicken; der Auszug erscheint rechts. */
export async function openAccountTreeNode(page, nummer) {
	await visibleSection(page).locator('.vbh-treenode', { hasText: nummer }).first().click()
}

/** Tab in der App-Navigation wechseln. */
export async function switchTab(page, label) {
	await tabButton(page, label).click()
}

/**
 * Geschäftsjahr im Kopfbereich wählen – über die Bezeichnung, so wie ein
 * Mensch es täte. Nach dem Laden steht der Filter auf dem laufenden Zeitraum;
 * Buchungen anderer Zeiträume brauchen diesen Schritt.
 */
export async function selectPeriod(page, label) {
	await page.locator('.vbh-yearsel select').selectOption({ label: String(label) })
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

	/** Mitgelieferten Standard-Kontenrahmen anlegen; liefert alle Konten. */
	async seedDefaultAccounts(request) {
		return (await call(request, 'POST', '/accounts/seed')).json()
	},

	/** Konto anlegen, z. B. ein Unterkonto mit parentId. */
	async createAccount(request, { number, name, type, category = null, isBank = false, parentId = null, user = 'admin' }) {
		return (await call(request, 'POST', '/accounts', { user, data: { number, name, type, category, isBank, parentId } })).json()
	},

	async updateAccount(request, id, data, { user = 'admin' } = {}) {
		return (await call(request, 'PUT', `/accounts/${id}`, { user, data })).json()
	},

	async deleteAccount(request, id, { user = 'admin' } = {}) {
		return call(request, 'DELETE', `/accounts/${id}`, { user })
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

	/** Mehreren Konten auf einmal eine Sphäre zuweisen ('ideell'|'vermoegensverwaltung'|'zweckbetrieb'|'wirtschaftlich'). */
	async bulkSphere(request, accountIds, sphere, { user = 'admin' } = {}) {
		return call(request, 'POST', '/accounts/sphere-bulk', { user, data: { accountIds, sphere } })
	},

	/** Buchung im Experten-Modus (Soll/Haben ausdrücklich). */
	async createBooking(request, { date, description, debitAccountId, creditAccountId, amount, user = 'admin', expectOk = true }) {
		return call(request, 'POST', '/journal', {
			user,
			expectOk,
			data: { date, description, debitAccountId, creditAccountId, amount },
		})
	},

	/** Buchung löschen – für Tests, die ihren Bestand hinterher wieder herstellen. */
	async deleteBooking(request, id, { user = 'admin' } = {}) {
		return call(request, 'DELETE', `/journal/${id}`, { user })
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

	async deleteAttachment(request, id) {
		return call(request, 'DELETE', `/attachments/${id}`)
	},

	// Wächter-Ordner für Belege
	async linkAttachment(request, journalId, fileId) {
		return (await call(request, 'POST', `/journal/${journalId}/attachments/link`, { data: { fileId } })).json()
	},

	async attachmentInbox(request) {
		return (await call(request, 'GET', '/attachments/inbox')).json()
	},

	async attachmentInboxSummary(request) {
		return (await call(request, 'GET', '/attachments/inbox/summary')).json()
	},

	async listJournal(request, { period = null } = {}) {
		const query = period ? `?period=${period}` : ''
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

	// --- Einzug: Lauf freigeben und einreichen (Issue #103) ------------------------
	// Die Oberfläche dafür gibt es seit #103 (Spec 42); wer nur einen eingereichten
	// Lauf als Vorbedingung braucht, baut ihn hier über die API.

	/** Lauf zu einem Einzugstermin freigeben (Schritt 1: die Posten werden eingefroren). */
	async releaseDebitBatch(request, dueDate, { user = 'admin', expectOk = true } = {}) {
		return (await call(request, 'POST', '/debit-batches', { user, expectOk, data: { dueDate } })).json()
	},

	/** „Datei ist bei der Bank eingereicht“ (Schritt 2): terminal, kein Storno mehr. */
	async submitDebitBatch(request, id, { user = 'admin', expectOk = true } = {}) {
		return (await call(request, 'POST', `/debit-batches/${id}/submit`, { user, expectOk })).json()
	},

	/** Ein Lauf samt Posten (`items`: endToEndId, mandateReference, amountCents, memberDisplayName, remittanceInfo, …). */
	async getDebitBatch(request, id, { user = 'admin' } = {}) {
		return (await call(request, 'GET', `/debit-batches/${id}`, { user })).json()
	},

	/** Freigeben und einreichen in einem Zug; liefert den eingereichten Lauf samt Posten. */
	async releaseAndSubmitDebitBatch(request, dueDate, { user = 'admin' } = {}) {
		const batch = await this.releaseDebitBatch(request, dueDate, { user })
		await this.submitDebitBatch(request, batch.id, { user })
		return this.getDebitBatch(request, batch.id, { user })
	},

	// --- Bankabgleich (Issue #105) --------------------------------------------------

	/** Umsätze (camtStatement(), collectionEntry(), returnEntry()) als camt.053-Auszug importieren. */
	async importCamtStatement(request, entries, { iban = BANK_ACCOUNT_IBAN, user = 'admin' } = {}) {
		return this.importStatement(request, camtStatement({ iban, entries }), { filename: 'auszug.xml', user })
	},

	/** Die Arbeitsliste des Bankabgleichs: `items` (Einzüge, Rückgaben) und `incoming` (Zahlungseingänge). */
	async bankReconciliation(request, { user = 'admin' } = {}) {
		return (await call(request, 'GET', '/bank-reconciliation', { user })).json()
	},

	/** Der Umsatz zu einem Textstück im Verwendungszweck samt Detail-Zeilen – so findet eine Spec ihren Umsatz, ohne IDs zu kennen. */
	async findReconciliationItem(request, purposePart, { user = 'admin' } = {}) {
		const { items } = await this.bankReconciliation(request, { user })
		const hit = items.find((entry) => (entry.bankTx.purpose || '').includes(purposePart))
		if (!hit) {
			throw new Error(`Kein Umsatz mit „${purposePart}“ im Bankabgleich – vorhanden: ${items.map((entry) => entry.bankTx.purpose).join(' | ')}`)
		}
		return hit
	},

	/** Einzelurteil über eine Detail-Zeile: `assign` (mit `debitItemId`), `reject` oder `unmatched`. */
	async decideSepaDetail(request, detailId, action, { debitItemId, user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/sepa-import/details/${detailId}/${action}`, { user, expectOk, ...(action === 'assign' ? { data: { debitItemId } } : {}) })
	},

	/** Verbuchen; mit `expectOk: false` bekommt die Spec auch eine Ablehnung (423 geschlossene Periode, 403 Rolle) zurück. */
	async settleSepaImport(request, bankTxId, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/sepa-import/${bankTxId}/settle`, { user, expectOk })
	},

	/** Rücklastschriftgebühren-Konto, Gebühren-Weiterbelastung ('1'/'0') und Standard-Erlöskonto (ab Verwalter; Konten als ID). */
	async setSepaImportSettings(request, { returnFeeAccountId, returnFeeRechargeEnabled, contributionDefaultAccountId }, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', '/sepa-import/settings', { user, expectOk, data: { returnFeeAccountId, returnFeeRechargeEnabled, contributionDefaultAccountId } })
	},

	/** Alle Geschäftsjahre; legt serverseitig den laufenden Zeitraum an, falls er fehlt. */
	async listPeriods(request, { user = 'admin' } = {}) {
		return (await call(request, 'GET', '/periods', { user })).json()
	},

	/**
	 * Die ID des Zeitraums, in den ein Datum fällt. Die Tests kennen ihre
	 * Buchungsdaten, aber nicht die IDs – und bei abweichendem Geschäftsjahr
	 * auch die Bezeichnung nicht sicher.
	 */
	async periodIdForDate(request, date, { user = 'admin' } = {}) {
		const periods = await this.listPeriods(request, { user })
		const hit = periods.find((p) => date >= p.startDate && date <= p.endDate)
		if (!hit) { throw new Error(`Kein Geschäftsjahr für ${date}`) }
		return hit.id
	},

	async closePeriod(request, periodId, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/periods/${periodId}/close`, { user, expectOk })
	},

	async reopenPeriod(request, periodId, { user = 'admin' } = {}) {
		return call(request, 'DELETE', `/periods/${periodId}/close`, { user })
	},

	/** Bezeichnung und/oder Ende eines Zeitraums ändern ({ label, endDate }). */
	async updatePeriod(request, periodId, data, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'PUT', `/periods/${periodId}`, { user, expectOk, data })
	},

	/** Die Geschäftsjahr-Regel umstellen (Preset oder eigene Werte). */
	async setPeriodRule(request, rule, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'PUT', '/periods/rule', { user, expectOk, data: rule })
	},

	async getSettings(request, { user = 'admin' } = {}) {
		return (await call(request, 'GET', '/settings', { user })).json()
	},

	/** Antwortet mit dem vollständigen Einstellungssatz nach dem Schreiben. */
	async updateSettings(request, settings, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', '/settings', { user, expectOk, data: settings })
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

	// Mitglieder-Stammdaten (Spec §2.2, docs/beitraege-sepa-modul-spec.md)
	async createMember(request, { memberType = 'person', firstName, lastName, organizationName, email, phone, internalNote, joinedAt, user = 'admin' } = {}) {
		return (await call(request, 'POST', '/members', { user, data: { memberType, firstName, lastName, organizationName, email, phone, internalNote, joinedAt } })).json()
	},

	async listMembers(request, { user = 'admin' } = {}) {
		return (await call(request, 'GET', '/members', { user })).json()
	},

	async deleteMember(request, id, { user = 'admin' } = {}) {
		return call(request, 'DELETE', `/members/${id}`, { user })
	},

	async linkMember(request, id, ncUserId, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/members/${id}/link`, { user, expectOk, data: { ncUserId } })
	},

	async unlinkMember(request, id, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/members/${id}/unlink`, { user, expectOk })
	},

	/**
	 * Setzt die Mailadresse eines NC-Kontos über die Provisioning-API – die
	 * NC-Kontoverknüpfung schlägt Konten anhand von IUserManager::getByEmail()
	 * vor, `setupUsers()` legt Testnutzer aber ohne Mailadresse an.
	 */
	async setUserEmail(request, uid, email, { user = 'admin' } = {}) {
		const resp = await request.fetch(`${BASE_URL}/ocs/v2.php/cloud/users/${uid}?format=json`, {
			method: 'PUT',
			headers: authHeaders(user),
			data: { key: 'email', value: email },
		})
		if (!resp.ok()) {
			throw new Error(`Mailadresse für ${uid} setzen fehlgeschlagen: HTTP ${resp.status()} – ${(await resp.text()).slice(0, 300)}`)
		}
		return resp
	},

	/** GET mit Erfolgserwartung, direkt als JSON. */
	async getJson(request, path, opts = {}) {
		return (await call(request, 'GET', path, opts)).json()
	},

	// --- Mandats-Lifecycle (Issue #66, Papier-Weg) ------------------------------
	// Mitglieder-Helfer (createMember/listMembers/...) siehe oben (Issue #65).

	async listMandates(request, { user = 'admin' } = {}) {
		return (await call(request, 'GET', '/mandates', { user })).json()
	},

	async mandatesByMember(request, memberId, { user = 'admin' } = {}) {
		return (await call(request, 'GET', `/mandates/by-member/${memberId}`, { user })).json()
	},

	/** Papier-Mandat anlegen (Entwurf) – Issue #66. */
	async createMandate(request, { memberId, iban, bic, accountHolder, signedAt, mandateReference, user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', '/mandates', {
			user,
			expectOk,
			data: { memberId, iban, bic, accountHolder, signedAt, mandateReference },
		})
	},

	async activateMandate(request, id, { signedAt, user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/mandates/${id}/activate`, { user, expectOk, data: { signedAt } })
	},

	async suspendMandate(request, id, { note, origin = 'manuell', user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/mandates/${id}/suspend`, { user, expectOk, data: { note, origin } })
	},

	/** Entsperren verlangt eine Notiz (Spec §2.2, Issue #100) – ohne Angabe eine Standardnotiz, damit ältere Specs unverändert bleiben. */
	async resumeMandate(request, id, { note = 'Geklärt', user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/mandates/${id}/resume`, { user, expectOk, data: { note } })
	},

	async revokeMandate(request, id, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/mandates/${id}/revoke`, { user, expectOk })
	},

	// --- Elektronische Mandatserteilung (Issue #67) -----------------------------

	/** Elektronischer Entwurf - Aktivierung läuft NICHT hier, sondern über den Einmal-Link. */
	async createElectronicMandate(request, { memberId, iban, bic, accountHolder, mandateReference, user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', '/mandates/electronic', {
			user,
			expectOk,
			data: { memberId, iban, bic, accountHolder, mandateReference },
		})
	},

	/** Verschickt/erneuert den Einmal-Link; die Antwort enthält die volle activationUrl (siehe MandateController::sendActivationLink()). */
	async sendActivationLink(request, id, { user = 'admin', expectOk = true } = {}) {
		return call(request, 'POST', `/mandates/${id}/send-activation-link`, { user, expectOk })
	},

	/** Self-Service-Kanal: das verknüpfte Mitglied fordert selbst einen (neuen) Link für sein eigenes elektronisches Mandat an. */
	async requestOwnMandateLink(request, { user, expectOk = true } = {}) {
		return call(request, 'POST', '/self/mandate/request-link', { user, expectOk })
	},

	// --- Self-Service Beitrag-Aktionen (Issue #76) ------------------------------

	/** Eigene Zuweisungen (Self-Service-Kanal). */
	async selfAssignments(request, { user, expectOk = true } = {}) {
		return (await call(request, 'GET', '/self/assignments', { user, expectOk })).json()
	},

	/** Vorschau vor dem Speichern (Spec §3.4 Pflicht-UI) - Self-Service-Kanal. */
	async selfPreviewAssignment(request, id, { monthlyAmount, intervalMonths, user, expectOk = true } = {}) {
		return call(request, 'POST', `/self/assignments/${id}/preview`, { user, expectOk, data: { monthlyAmount, intervalMonths } })
	},

	/** Betrag/Turnus der eigenen Zuweisung ändern (Self-Service-Kanal). */
	async selfUpdateAssignment(request, id, { monthlyAmount, intervalMonths, user, expectOk = true } = {}) {
		return call(request, 'PUT', `/self/assignments/${id}`, { user, expectOk, data: { monthlyAmount, intervalMonths } })
	},

	/** Eigene Kontaktstammdaten pflegen (Self-Service-Kanal). */
	async selfUpdateMe(request, data, { user, expectOk = true } = {}) {
		return call(request, 'PUT', '/self/me', { user, expectOk, data })
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
