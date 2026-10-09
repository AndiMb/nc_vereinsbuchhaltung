import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { clearCapturedMails, startMailCapture, stopMailCapture, waitForMailsTo } from './fixtures/mail-capture.mjs'
import { api, authHeaders, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BASE_URL, INCOME_ACCOUNT, openApp, returnEntry, switchTab, tabButton, USERS, visibleSection, waitForAppLoaded } from './fixtures/nextcloud.mjs'

// Sichten und Rollen, die sich nur mit einem anderen Konto prüfen lassen
// (Testprotokoll docs/testprotokoll/testprotokoll.md, Durchlauf vom 09.10.2026).
//
// Beim manuellen Durchgang ließen sich Schritte nicht live prüfen, die eine
// Anmeldung als anderer Nutzer brauchen. Diese Spec schließt die Lücken, die die
// vorhandenen Specs (23, 26, 31, 32, 35, 36, 41–44, 46, 48, 50–52) offen lassen:
//
// - 7.17 / 11.3 / 11.9  Sperrfenster nach der Vorabinfo: echt hergestellt über den
//   Tageslauf (32 prüft die Ablehnung nur mit einer vorgetäuschten Server-Antwort).
// - 3.6 / 3.7           Kontoverknüpfung in der Akte, gesehen aus dem verknüpften Konto.
// - 11.1 / 11.2 / 14.1  Überblick, Stammdaten ändern, kein „Sie“ in der Du-Fassung.
// - 11.8 / 14.2         Entwurf bestätigen im Self-Service, Du- und Sie-Dialog.
// - 11.9 / 11.12        Meldungen der Untergrenze, individuelle Untergrenze ohne Begründung.
// - 11.11 / 12.4 / 12.5 Beitragsbestätigung und Datenübersicht aus Mitgliedssicht.
// - 13.4 / 13.5         Revisor sieht im Forderungsdetail keinen Rückgabecode.
// - 13.6 / 13.7         „Mein Beitrag“ folgt Schalter und Verknüpfung, nicht der Rolle.
// - 14.4 / 14.6 / 14.7  Englisch in „Mein Beitrag“ ohne Mandat, Einzug-Reiter des Revisors,
//                       Quittungsmail (Du-Fassung und Englisch, deutsche Zahlen).
//
// Testkonten wie überall in der Suite: test4 (USERS.ohneRolle, „Deutsch“) steht für
// jane und john des Protokolls, test3 (USERS.revisor) für bob, test5 (USERS.englisch)
// für user1. Jeder Test legt seine Mitglieder mit eindeutigen Namen an: `resetBook()`
// räumt Mitglieder, Mandate und Zuweisungen nicht ab, und ein Konto gehört höchstens
// einem Mitglied – die Verknüpfung wird deshalb vor jedem Test gesetzt (linkOnly) und
// im afterAll gelöst, damit keine andere Spec ein fremdes Mitglied für „test4“ findet.
//
// Bewusst NICHT geprüft (unsicher oder nicht ohne Zeitreise/NC-Oberfläche belegbar):
// - 11.7 Aktivitäten und Benachrichtigungen (Oberfläche der Nextcloud-Aktivitäten-App),
// - 13.3 die Nextcloud-Einstellungsseite für Buchhalter (Verhalten von Nextcloud, nicht der
//   App; die Rechte der App-API prüft 40-settings-legal-text),
// - 13.4 das Was-ist-neu-Fenster für Revisoren (Rollenfilter: Vitest whatsNew.test.js; ob ein
//   Revisor den 0.35.0-Eintrag sehen soll, ist im Protokoll eine offene Frage),
// - 14.3 die Vorabinfo- und Widerrufsmails, 14.5 die deutsche Zustimmungsseite zur englischen Mail.

const IBAN = 'DE02120300000000202051'
const IBAN_NEW = 'DE89370400440532013000'
const IBAN_MASKED = /DE02•+2051/
const IBAN_NEW_MASKED = /DE89•+3000/
const FEE_ACCOUNT = '5400'

/** Sie-Anrede (großgeschrieben): in der Du-Fassung darf sie in Sätzen für Mitglieder nicht stehen. */
const SIE_FORM = /\b(Sie|Ihr|Ihre|Ihrem|Ihren|Ihrer|Ihres|Ihnen)\b/

/** Wörter, die in der englischen Oberfläche nichts verloren haben (wie 51-uebersetzungen). */
const GERMAN_REMNANTS = /\b(Zeitstrahl|Bankabgleich|Forderungen|Läufe|Beitragsjahr|Rücklastschrift|Rücklastschriften|Störfälle|Zahlungsaufforderung|Mahnstand|Mandat|Lastschrift|Kontoinhaber|Stammdaten|Mitgliedsnummer|Datenübersicht|Beitragsbestätigung)\b/

const LOCK_EXPLANATION = 'Für die laufende Periode wurde bereits eine Vorabinfo verschickt – Betrag und Turnus stehen bis zum Einzug fest.'
const lockMessage = (date) => `${LOCK_EXPLANATION} Möglich wäre diese Änderung erst ab ${date.split('-').reverse().join('.')}.`

const GROUP_LOCK = { name: 'Sichten-Gruppe Sperrfenster', min: 5, def: 10, intervals: [1, 3, 12], defaultInterval: 3 }
const GROUP_FULL = { name: 'Sichten-Gruppe Vollmitglied', min: 12, def: 15, intervals: [1, 3, 6, 12], defaultInterval: 3 }
const GROUP_REDUCED = { name: 'Ermäßigt Sichten', min: 5, def: 7.5, intervals: [1, 3, 12], defaultInterval: 3 }

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const iso = (date) => date.toISOString().slice(0, 10)
const today = () => iso(new Date())
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const nextDay = (isoDate) => iso(new Date(Date.parse(`${isoDate}T00:00:00Z`) + 86400000))
const daysUntil = (isoDate) => Math.round((Date.parse(`${isoDate}T00:00:00Z`) - Date.parse(`${today()}T00:00:00Z`)) / 86400000)
const unique = (base) => `${base}-${Math.random().toString(36).slice(2, 6)}`

// ---------------------------------------------------------------------------
// Bausteine
// ---------------------------------------------------------------------------

/** Sprache eines NC-Kontos über die Provisioning-API setzen (Verwalter dürfen das für andere Konten; wie 51-uebersetzungen). */
async function setUserLanguage(request, uid, language) {
	const resp = await request.fetch(`${BASE_URL}/ocs/v2.php/cloud/users/${uid}?format=json`, {
		method: 'PUT',
		headers: authHeaders('admin'),
		data: { key: 'language', value: language },
	})
	if (!resp.ok()) {
		throw new Error(`Sprache ${language} für ${uid} setzen fehlgeschlagen: HTTP ${resp.status()} – ${(await resp.text()).slice(0, 300)}`)
	}
}

/** Beitragsmodul, Self-Service und das einziehende Konto einschalten; das Beitragsjahr beginnt im Januar (Vorgabe). */
async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		self_service_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
		fiscal_year_start_month: 1,
	})
}

/** Frischer Bestand mit Kontenrahmen und eingeschaltetem Modul. */
async function setUpBook(request) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	await enableModule(request)
}

/** Die Schalter zurück auf „aus“ – so lassen auch 26, 31, 32 und 52 sie zurück. */
async function switchModuleOff(request) {
	await api.updateSettings(request, { self_service_enabled: '0', membership_enabled: '0' })
}

/** Löst jede Verknüpfung dieses Kontos (abgebrochener Lauf, Wiederholung, übersprungenes afterAll). */
async function unlinkAccount(request, uid) {
	for (const linked of (await api.listMembers(request)).filter((m) => m.ncUserId === uid)) {
		await api.unlinkMember(request, linked.id)
	}
}

/** Das Konto gehört ab jetzt (nur) diesem Mitglied. */
async function linkOnly(request, memberId, uid) {
	await unlinkAccount(request, uid)
	await api.linkMember(request, memberId, uid)
}

/** Ein Mitglied mit eindeutigem Nachnamen und passender Mailadresse; `extra` ergänzt Anschrift, Telefon, interne Notiz … */
async function createMember(request, firstName, lastName, extra = {}) {
	const surname = unique(lastName)
	const resp = await api.raw(request, 'POST', '/members', {
		data: { memberType: 'person', firstName, lastName: surname, email: `${firstName}.${surname}@example.org`.toLowerCase(), ...extra },
	})
	expect(resp.status(), 'Mitglied anlegen').toBe(201)
	return resp.json()
}

async function ensureGroup(request, { name, min, def, intervals, defaultInterval }) {
	const groups = await api.getJson(request, '/contribution-groups')
	const existing = groups.find((g) => g.name === name)
	if (existing) {
		return existing
	}
	const resp = await api.raw(request, 'POST', '/contribution-groups', {
		data: { name, minMonthlyAmount: min, defaultMonthlyAmount: def, allowedIntervals: intervals, defaultInterval, isActive: true },
	})
	expect(resp.status(), 'Beitragsgruppe anlegen').toBe(201)
	return resp.json()
}

/** Zuweisung ab heute; Überweisung braucht kein Mandat, Lastschrift schon. */
async function createAssignment(request, { memberId, groupId, intervalMonths = 3, monthlyAmount = 10, paymentMethod = 'ueberweisung', ...extra }) {
	const resp = await api.raw(request, 'POST', '/assignments', {
		data: { memberId, groupId, intervalMonths, monthlyAmount, paymentMethod, validFrom: today(), ...extra },
	})
	expect(resp.status(), 'Zuweisung anlegen').toBe(201)
	return resp.json()
}

async function activeMandate(request, member, { iban = IBAN } = {}) {
	const mandate = await (await api.createMandate(request, { memberId: member.id, iban, signedAt: today() })).json()
	await api.activateMandate(request, mandate.id)
	return mandate
}

/** Eine Einzelforderung; mit `paid` gleich als bezahlt vermerkt (der Weg, den auch 35 geht). */
async function createClaim(request, memberId, { type = 'beitrag', amount, label, dueDate = today(), paid = false }) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type, amount, label, dueDate } })
	expect(resp.ok(), `Forderung „${label}“ anlegen`).toBeTruthy()
	const claim = await resp.json()
	if (paid) {
		const settled = await api.raw(request, 'POST', `/claims/${claim.id}/settle`, { data: { settlementType: 'paid' } })
		expect(settled.ok(), `Forderung „${label}“ als bezahlt vermerken`).toBeTruthy()
	}
	return claim
}

/** Öffnet die App als Mitglied und den Reiter „Mein Beitrag“ (oder „My contribution“); wartet, bis die Karten stehen. */
async function openMeinBeitrag(page, user, { tab = 'Mein Beitrag', firstHeading = 'Meine Stammdaten' } = {}) {
	await openApp(page, user)
	await tabButton(page, tab).click()
	const section = visibleSection(page)
	await expect(section.getByRole('heading', { name: firstHeading, exact: true })).toBeVisible()
	return section
}

/** Die Karte einer Zuweisung („Mein Beitrag“ mit Betrag, Turnus, Vorschau und Speichern). */
const assignmentCard = (section, groupName) => section.locator('.vbh-selfservice-assignment', { hasText: groupName })

/** Die Karte „Mein SEPA-Lastschriftmandat“ – darin steht auch der Bereich „Rücklastschriften“. */
const mandateCard = (section) => section.locator('.vbh-card', { hasText: 'Mein SEPA-Lastschriftmandat' })

/** Eine Karte der Seite, gefunden über ihre Überschrift. */
const cardWithHeading = (section, heading) => section.locator('.vbh-card').filter({ has: section.page().getByRole('heading', { name: heading, exact: true }) })

/**
 * Erfolgs-Toast: er steht doppelt im DOM (Toast und Bildschirmleser-Ansage „Erfolg: …“),
 * der exakte Text trifft nur den Toast (siehe 31-self-service-mandate).
 */
const toast = (page, text) => page.getByText(text, { exact: true })

/** Öffnet einen Link, der in einem neuen Tab aufgeht (Beitragsbestätigung, Datenübersicht), und liefert den Tab. */
async function openInNewTab(page, link) {
	const [popup] = await Promise.all([
		page.context().waitForEvent('page'),
		link.click(),
	])
	await popup.waitForLoadState()
	return popup
}

/** Der Willkommenshinweis für Kassenprüfer erscheint je Browserprofil einmal (siehe 08-roles, 50-offene-posten-regeln). */
async function dismissRevisorIntro(page) {
	const intro = page.getByText('Willkommen als Kassenprüfer/in')
	await intro.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
	if (await intro.isVisible()) {
		await page.getByRole('button', { name: 'Verstanden' }).click()
	}
}

// --- Tageslauf (Einzugszyklus) ---------------------------------------------

/** Vorwarnfenster und Vorabinfo-Vorlauf, wie der Server sie gerade führt (siehe 48-rollen-haertung). */
async function readLeadDays(request) {
	const { warningLeadDays, prenotificationLeadDays } = await api.getJson(request, '/due-date-schedule')
	return { warningLeadDays, prenotificationLeadDays }
}

/** Stellt die Vorlaufzeiten als Verwalter ein (die App-Config räumt `resetBook()` nicht). */
async function writeLeadDays(request, values) {
	const resp = await api.raw(request, 'POST', '/due-date-schedule/lead-days', { data: values })
	expect(resp.ok(), 'Vorlaufzeiten setzen').toBeTruthy()
}

/** Startet den Tageslauf des Einzugszyklus von Hand (wie 28-contribution-prenotification). */
async function runCycleJob() {
	const container = getContainer()
	const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
	const job = JSON.parse(stdout).find((j) => (j.class || '').includes('ContributionDueCycleJob'))
	expect(job, 'ContributionDueCycleJob ist registriert').toBeTruthy()
	await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })
}

/** Die Forderungen einer Zuweisung, nach Periodenbeginn aufsteigend. */
async function claimsOf(request, assignmentId) {
	return (await api.getJson(request, '/claims'))
		.filter((claim) => claim.assignmentId === assignmentId)
		.sort((a, b) => String(a.periodStart).localeCompare(String(b.periodStart)))
}

/**
 * Lässt die Vorabinfo für alle Forderungen der Zuweisung rausgehen, ohne Zeitreise: Die
 * Forderung entsteht mit einem Termin, der mindestens eine ganze Vorlauffrist vor uns liegt
 * (Nachzügler-Regel, siehe Kopf von 32) – einen Tag später als „heute“ wäre sie nie fällig.
 * Wird der Vorabinfo-Vorlauf aber erst NACH dem Erzeugen so weit hochgesetzt, dass der
 * Termin hineinfällt, versendet der nächste Lauf die Vorabinfo und setzt `prenotified_at`.
 * Idempotent: sind alle Forderungen schon vorabinformiert, passiert nichts.
 */
async function sendPrenotification(request, assignmentId) {
	let own = await claimsOf(request, assignmentId)
	expect(own.length, 'Der Tageslauf hat für die Zuweisung keine Forderung erzeugt').toBeGreaterThan(0)
	if (own.every((claim) => claim.prenotifiedAt)) {
		return own
	}
	const farthest = Math.max(...own.map((claim) => daysUntil(claim.dueDate)))
	await writeLeadDays(request, { prenotificationLeadDays: Math.min(365, Math.max(1, farthest + 1)) })
	await runCycleJob()
	own = await claimsOf(request, assignmentId)
	expect(own.every((claim) => claim.prenotifiedAt), 'Der Tageslauf hat die Vorabinfo nicht verschickt (Mailweg, Mandat und Adresse prüfen)').toBe(true)
	return own
}

// ---------------------------------------------------------------------------
// 7.17 / 11.3 / 11.9: Sperrfenster nach der Vorabinfo
// ---------------------------------------------------------------------------
//
// 32-self-service-contribution erklärt, warum sich `prenotified_at` nicht in einem
// Testlauf herstellen lässt, und mockt deshalb die Serverantwort. Es geht doch: Der
// Tageslauf erzeugt die Forderung mit einem Vorabinfo-Vorlauf von 1 Tag (Termin
// frühestens in zwei Tagen, noch keine Vorabinfo fällig); wird der Vorlauf danach so
// weit erhöht, dass der Termin hineinfällt, verschickt der nächste Lauf die Vorabinfo.
// Der Testserver hat keinen Mailserver: Mail-Modus „null“ (Mails werden angenommen und
// verworfen), danach wieder entfernt – config.php gehört nicht zum Datenbank-Snapshot.

test.describe('Sperrfenster nach der Vorabinfo (Protokoll 7.17, 11.3, 11.9)', () => {
	let leadBefore
	let member
	let assignment

	test.beforeAll(async ({ request }) => {
		test.setTimeout(180000)
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
		// Apache liest config.php über opcache (Standard: Zeitstempel alle 2 s prüfen): ohne Pause sähe der erste Web-Request noch den alten Mail-Modus.
		await sleep(4000)

		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await setUpBook(request)
		await unlinkAccount(request, USERS.ohneRolle)
		leadBefore = await readLeadDays(request)

		const group = await ensureGroup(request, GROUP_LOCK)
		member = await createMember(request, 'Sperre', 'Fenster')
		await activeMandate(request, member)
		await api.linkMember(request, member.id, USERS.ohneRolle)
		assignment = await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 3, monthlyAmount: 10, paymentMethod: 'direct_debit' })

		// Vorabinfo-Vorlauf 1 Tag: die Forderung entsteht, die Vorabinfo ist noch nicht fällig.
		await writeLeadDays(request, { prenotificationLeadDays: 1 })
		await runCycleJob()
		const own = await claimsOf(request, assignment.id)
		expect(own.length, 'Der Tageslauf hat für die Zuweisung keine Forderung erzeugt').toBeGreaterThan(0)
		expect(own.every((claim) => claim.prenotifiedAt === null), 'noch keine Vorabinfo').toBe(true)
	})

	test.afterAll(async ({ request }) => {
		if (leadBefore) {
			await writeLeadDays(request, leadBefore)
		}
		await unlinkAccount(request, USERS.ohneRolle)
		await switchModuleOff(request)
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	test('Gegenprobe: vor der Vorabinfo zeigen Betrag und Turnus eine Vorschau „Wirkt ab“, und jede Änderung verlangt eine neue', async ({ page, request }) => {
		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		const card = assignmentCard(section, GROUP_LOCK.name)
		await expect(card).toBeVisible()
		const save = card.getByRole('button', { name: 'Speichern' })
		await expect(save).toBeDisabled()

		await card.getByLabel('Monatsbeitrag (€)').fill('11')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(card.getByText(/Wirkt ab \d{2}\.\d{2}\.\d{4} · erster Einzug am \d{2}\.\d{2}\.\d{4}/)).toBeVisible()
		await expect(save).toBeEnabled()

		// Der Turnus ist eine weitere Änderung: die alte Vorschau passt nicht mehr, Speichern ist wieder gesperrt.
		await card.getByLabel('Turnus').selectOption('12')
		await expect(save).toBeDisabled()
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(card.getByText(/Wirkt ab/)).toBeVisible()
		await expect(save).toBeEnabled()

		// Nichts wurde gespeichert.
		const own = (await api.selfAssignments(request, { user: USERS.ohneRolle })).find((a) => a.id === assignment.id)
		expect(own).toMatchObject({ monthlyAmount: 10, intervalMonths: 3 })
	})

	test('nach der Vorabinfo: Betrag und Turnus werden mit Erklärung abgelehnt, nichts wird gespeichert', async ({ page, request }) => {
		test.setTimeout(180000)
		const claims = await sendPrenotification(request, assignment.id)
		// Die erste Periode ohne Vorabinfo beginnt am Tag nach der letzten vorabinformierten.
		const firstFreeDay = nextDay(claims[claims.length - 1].periodEnd)
		const asMember = { user: USERS.ohneRolle, expectOk: false }

		// Betrag: die Meldung nennt das früheste mögliche Datum, es gibt keine Vorschau, Speichern bleibt gesperrt.
		let section = await openMeinBeitrag(page, USERS.ohneRolle)
		let card = assignmentCard(section, GROUP_LOCK.name)
		await card.getByLabel('Monatsbeitrag (€)').fill('11')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(toast(page, lockMessage(firstFreeDay))).toBeVisible()
		await expect(card.getByText(/Wirkt ab/)).toHaveCount(0)
		await expect(card.getByRole('button', { name: 'Speichern' })).toBeDisabled()

		// Turnus: dieselbe Ablehnung (frisch geladene Seite, damit die Meldung oben nicht mitzählt).
		const intervalPreview = await api.selfPreviewAssignment(request, assignment.id, { intervalMonths: 12, ...asMember })
		expect(intervalPreview.status()).toBe(400)
		const intervalMessage = (await intervalPreview.json()).message
		expect(intervalMessage).toMatch(new RegExp(`^${LOCK_EXPLANATION} Möglich wäre diese Änderung erst ab \\d{2}\\.\\d{2}\\.\\d{4}\\.$`))
		section = await openMeinBeitrag(page, USERS.ohneRolle)
		card = assignmentCard(section, GROUP_LOCK.name)
		await card.getByLabel('Turnus').selectOption('12')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(toast(page, intervalMessage)).toBeVisible()
		await expect(card.getByText(/Wirkt ab/)).toHaveCount(0)
		await expect(card.getByRole('button', { name: 'Speichern' })).toBeDisabled()

		// Auch das Speichern selbst lehnt der Server ab – mit derselben Erklärung, ohne etwas zu ändern.
		const amountSave = await api.selfUpdateAssignment(request, assignment.id, { monthlyAmount: 11, ...asMember })
		expect(amountSave.status()).toBe(400)
		expect((await amountSave.json()).message).toBe(lockMessage(firstFreeDay))
		const intervalSave = await api.selfUpdateAssignment(request, assignment.id, { intervalMonths: 12, ...asMember })
		expect(intervalSave.status()).toBe(400)
		const own = (await api.selfAssignments(request, { user: USERS.ohneRolle })).find((a) => a.id === assignment.id)
		expect(own).toMatchObject({ monthlyAmount: 10, intervalMonths: 3 })
	})

	test('nach der Vorabinfo bleibt die IBAN änderbar: kein Sperrfenster, der Dialog sagt es in der Du-Fassung, die Verwaltung sieht den Urheber', async ({ page, request }) => {
		test.setTimeout(180000)
		await sendPrenotification(request, assignment.id)

		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		const card = mandateCard(section)
		await expect(card.getByText(IBAN_MASKED)).toBeVisible()
		await card.getByRole('button', { name: 'Bankverbindung ändern' }).click()

		const dialog = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await expect(dialog.getByRole('radio', { name: 'Gleiches Konto, nur die IBAN hat sich geändert' })).toBeChecked()
		await expect(dialog).toContainText('Kein Sperrfenster – du kannst die IBAN bis zur Einreichung des nächsten Einzugs jederzeit ändern.')
		await dialog.getByLabel('Neue IBAN', { exact: true }).fill(IBAN_NEW)
		await dialog.getByRole('button', { name: 'IBAN ändern', exact: true }).click()

		await expect(toast(page, 'IBAN geändert.')).toBeVisible()
		await expect(card.getByText(IBAN_NEW_MASKED)).toBeVisible()
		// Die volle IBAN steht nirgends, nur die maskierte.
		await expect(page.locator('body')).not.toContainText(IBAN_NEW)

		// Verwaltung: die Bankverbindung hat ein Amendment, im Verlauf steht der Kanal „Mitglied“.
		const [live] = (await api.mandatesByMember(request, member.id)).filter((m) => m.status === 'aktiv')
		expect(live.iban).toBe(IBAN_NEW)
		const detail = await api.getJson(request, `/mandates/${live.id}`)
		expect(detail.amendments.length).toBeGreaterThanOrEqual(1)
		expect(detail.history.some((entry) => entry.actorType === 'member')).toBe(true)
	})
})

// ---------------------------------------------------------------------------
// 3.6 / 3.7: Kontoverknüpfung in der Akte, gesehen aus dem verknüpften Konto
// ---------------------------------------------------------------------------
//
// bob des Protokolls ist hier test3 (Revisor): Personalunion von Rolle und Mitglied. Zwei
// Browserkontexte, weil jedes Konto seine eigene Sitzung braucht.

test.describe('Kontoverknüpfung in der Akte (Protokoll 3.6, 3.7)', () => {
	test.beforeAll(async ({ request }) => {
		await setUpBook(request)
		await unlinkAccount(request, USERS.revisor)
	})

	test.afterAll(async ({ request }) => {
		await unlinkAccount(request, USERS.revisor)
		await switchModuleOff(request)
	})

	test('„Mein Beitrag“ erscheint beim verknüpften Konto neben „Beiträge“ und verschwindet nach dem Lösen wieder', async ({ page, browser, request }) => {
		test.setTimeout(120000)
		const tag = Math.random().toString(36).slice(2, 6)
		const email = `verknuepfung.sichten.${tag}@example.org`
		await api.setUserEmail(request, USERS.revisor, email)
		const member = await createMember(request, 'Willi', 'Wegwerf', { email })

		const revisorContext = await browser.newContext()
		try {
			// bob vor der Verknüpfung: Reiter „Beiträge“ (nur Einzug), kein „Mein Beitrag“.
			const bob = await revisorContext.newPage()
			await openApp(bob, USERS.revisor)
			await expect(tabButton(bob, 'Beiträge')).toBeVisible()
			await expect(tabButton(bob, 'Mein Beitrag')).toHaveCount(0)

			// Die Verwaltung öffnet die Akte. Vor dem Klick steht dort noch nichts „Verknüpft mit …“.
			await openApp(page, USERS.buchhalter)
			await switchTab(page, 'Beiträge')
			await visibleSection(page).locator('tr', { hasText: member.displayName }).getByRole('button', { name: 'Akte öffnen' }).click()
			const akte = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
			await expect(akte).toBeVisible()
			await expect(akte.getByText('Verknüpft mit')).toHaveCount(0)

			// Die Mailadresse liefert nur den Vorschlag; erst „Verknüpfen“ setzt die Verknüpfung.
			await akte.getByRole('button', { name: 'Vorschläge suchen' }).click()
			const suggestion = akte.locator('.vbh-linksuggestions li', { hasText: USERS.revisor })
			await expect(suggestion).toBeVisible()
			await expect(akte.getByText('Verknüpft mit')).toHaveCount(0)
			await suggestion.getByRole('button', { name: 'Verknüpfen' }).click()
			await expect(toast(page, 'Verknüpft.')).toBeVisible()
			await expect(akte.getByText(`Verknüpft mit „${USERS.revisor}".`)).toBeVisible()

			// bob lädt neu: „Mein Beitrag“ steht neben „Beiträge“ und zeigt die Daten des Mitglieds; „Beiträge“ bleibt beim Einzug.
			await bob.reload()
			await waitForAppLoaded(bob)
			await expect(tabButton(bob, 'Mein Beitrag')).toBeVisible()
			await expect(tabButton(bob, 'Beiträge')).toBeVisible()
			await tabButton(bob, 'Mein Beitrag').click()
			const own = visibleSection(bob)
			await expect(own.getByRole('heading', { name: 'Meine Stammdaten', exact: true })).toBeVisible()
			await expect(own.getByText(member.displayName)).toBeVisible()
			await expect(own.getByText(email)).toBeVisible()
			await switchTab(bob, 'Beiträge')
			await expect(visibleSection(bob).locator('.vbh-subtabs button')).toHaveCount(1)
			await expect(visibleSection(bob).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true })).toBeVisible()

			// 3.7: Verknüpfung lösen, mit Rückfrage.
			await akte.getByRole('button', { name: 'Verknüpfung lösen' }).click()
			const unlinkDialog = page.getByRole('dialog', { name: 'Verknüpfung lösen' })
			await expect(unlinkDialog).toContainText('Die Verknüpfung mit dem Nextcloud-Konto lösen? Das Mitglied und seine Historie bleiben bestehen.')
			await unlinkDialog.getByRole('button', { name: 'Lösen', exact: true }).click()
			await expect(toast(page, 'Verknüpfung gelöst.')).toBeVisible()
			await expect(akte.getByRole('button', { name: 'Vorschläge suchen' })).toBeVisible()
			await expect(akte.getByText('Verknüpft mit')).toHaveCount(0)

			// bob lädt neu: „Mein Beitrag“ ist weg, „Beiträge“ bleibt, und die Schnittstelle sperrt den Self-Service.
			await bob.reload()
			await waitForAppLoaded(bob)
			await expect(tabButton(bob, 'Beiträge')).toBeVisible()
			await expect(tabButton(bob, 'Mein Beitrag')).toHaveCount(0)
			expect((await api.raw(request, 'GET', '/self/me', { user: USERS.revisor })).status()).toBe(403)
		} finally {
			await revisorContext.close()
			await unlinkAccount(request, USERS.revisor)
			await api.deleteMember(request, member.id).catch(() => {})
		}
	})
})

test.describe('Kontoverknüpfung in der Akte: Hinweise ohne Vorschlag (Protokoll 3.6)', () => {
	test.beforeAll(async ({ request }) => {
		await setUpBook(request)
	})

	test.afterAll(async ({ request }) => {
		await switchModuleOff(request)
	})

	/** Öffnet die Akte des Mitglieds über Beiträge → Mitglieder → Name. */
	async function openAkte(page, member) {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).locator('tr', { hasText: member.displayName }).getByRole('button', { name: 'Akte öffnen' }).click()
		const akte = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
		await expect(akte).toBeVisible()
		return akte
	}

	test('ohne Mailadresse gibt es keinen Vorschlag: der Hinweis sagt, was zu tun ist', async ({ page, request }) => {
		const member = await createMember(request, 'Ohne', 'Mailadresse', { email: undefined })
		const akte = await openAkte(page, member)

		await expect(akte.getByText('Ohne Mailadresse gibt es keinen Vorschlag – erst speichern, dann verknüpfen.')).toBeVisible()
		await expect(akte.getByRole('button', { name: 'Vorschläge suchen' })).toHaveCount(0)
		await expect(akte.getByText('Verknüpft mit')).toHaveCount(0)
	})

	test('mit einer Mailadresse ohne Nextcloud-Konto gibt es keinen Treffer', async ({ page, request }) => {
		const member = await createMember(request, 'Unbekannt', 'Adresse', { email: `niemand.${Math.random().toString(36).slice(2, 8)}@example.org` })
		const akte = await openAkte(page, member)

		await akte.getByRole('button', { name: 'Vorschläge suchen' }).click()
		await expect(akte.getByText('Kein Nextcloud-Konto mit dieser Mailadresse gefunden.')).toBeVisible()
		await expect(akte.locator('.vbh-linksuggestions li')).toHaveCount(0)
		await expect(akte.getByText('Verknüpft mit')).toHaveCount(0)
	})
})

// ---------------------------------------------------------------------------
// 11.1 / 11.2 / 11.9 / 11.12 / 14.1: „Mein Beitrag“ im Überblick (Du-Fassung)
// ---------------------------------------------------------------------------

test.describe('„Mein Beitrag“ im Überblick (Protokoll 11.1, 11.2, 11.9, 11.12, 14.1)', () => {
	const NOTE = 'Interne Notiz nur für den Vorstand, darf im Self-Service nie sichtbar sein.'
	let member
	let stranger
	let group

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await setUpBook(request)
		group = await ensureGroup(request, GROUP_FULL)

		member = await createMember(request, 'Jana', 'Sichtprobe', {
			phone: '+49 30 5550100',
			street: 'Musterweg 1',
			postalCode: '10115',
			city: 'Berlin',
			internalNote: NOTE,
		})
		await activeMandate(request, member)
		await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 3, monthlyAmount: 15, paymentMethod: 'direct_debit' })

		// Ein zweites Mitglied, von dem „Mein Beitrag“ nichts zeigen darf.
		stranger = await createMember(request, 'Fritz', 'Fremdling', { phone: '+49 30 5559999', internalNote: 'Fremde Notiz' })
		await activeMandate(request, stranger, { iban: IBAN_NEW })
	})

	test.beforeEach(async ({ request }) => {
		await linkOnly(request, member.id, USERS.ohneRolle)
	})

	test.afterAll(async ({ request }) => {
		await unlinkAccount(request, USERS.ohneRolle)
		await switchModuleOff(request)
	})

	test('Überblick: nur der Reiter „Mein Beitrag“, keine Werkzeuge der Buchhaltung, alle Karten, IBAN maskiert, nichts von anderen', async ({ page }) => {
		await openApp(page, USERS.ohneRolle)

		// Nur dieser eine Reiter; Zeitraum, Knopf „Buchung“, Hilfe, Klemmbrett und Geldbestand gehören zur Buchhaltung.
		await expect(page.locator('.vbh-tabs button')).toHaveCount(1)
		await expect(tabButton(page, 'Mein Beitrag')).toBeVisible()
		await expect(page.locator('.vbh-navright')).toHaveCount(0)
		await expect(page.locator('.vbh-yearsel')).toHaveCount(0)
		await expect(page.locator('.vbh-newbooking-btn')).toHaveCount(0)
		await expect(page.locator('.vbh-bankchip')).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Hilfe' })).toHaveCount(0)
		await expect(page.getByRole('button', { name: /^Aufgaben/ })).toHaveCount(0)
		await expect(page.getByText('Kein Zugriff')).toHaveCount(0)

		await tabButton(page, 'Mein Beitrag').click()
		const section = visibleSection(page)
		for (const heading of ['Meine Stammdaten', 'Mein Beitrag', 'Mein SEPA-Lastschriftmandat', 'Rücklastschriften', 'Meine Beitragsbestätigung', 'Meine Daten']) {
			await expect(section.getByRole('heading', { name: heading, exact: true })).toBeVisible()
		}

		// Eigene Daten …
		const contact = cardWithHeading(section, 'Meine Stammdaten')
		await expect(contact.getByText(member.displayName)).toBeVisible()
		await expect(contact.getByText(member.email)).toBeVisible()
		await expect(contact.getByText('+49 30 5550100')).toBeVisible()
		await expect(contact.getByText('Musterweg 1, 10115 Berlin')).toBeVisible()
		await expect(contact.getByText('Mitglied seit')).toBeVisible()
		await expect(assignmentCard(section, GROUP_FULL.name)).toContainText(/Untergrenze: 12,00\s*€/)
		await expect(mandateCard(section).getByText(IBAN_MASKED)).toBeVisible()
		await expect(mandateCard(section).getByText('Aktiv')).toBeVisible()
		for (const button of ['Bankverbindung ändern', 'Kontoinhaber wechseln', 'Mandat widerrufen']) {
			await expect(mandateCard(section).getByRole('button', { name: button })).toBeVisible()
		}
		await expect(mandateCard(section).getByText('Bisher keine Rücklastschrift.')).toBeVisible()

		// … die volle IBAN, die interne Notiz und die Daten anderer Mitglieder nirgends.
		const body = page.locator('body')
		for (const forbidden of [IBAN, 'DE02 1203 0000 0000 2020 51', NOTE, 'Interne Notiz', stranger.displayName, '5559999', IBAN_NEW]) {
			await expect(body, `„${forbidden}“ darf in „Mein Beitrag“ nie stehen`).not.toContainText(forbidden)
		}
	})

	test('Stammdaten ändern: nur Kontaktfelder sind bearbeitbar, der E-Mail-Wechsel kündigt die Sicherheitsmail an, Speichern wirkt sofort', async ({ page, request }) => {
		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		const contact = cardWithHeading(section, 'Meine Stammdaten')
		await contact.getByRole('button', { name: 'Bearbeiten' }).click()

		// Änderbar sind Name, E-Mail, Telefon und Anschrift – Mitgliedsnummer und Eintrittsdatum liegen bei der Kassenführung.
		for (const label of ['Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Straße', 'PLZ', 'Ort']) {
			await expect(contact.getByLabel(label, { exact: true })).toBeVisible()
		}
		// Das Land ist eine Auswahl (alle Länder), kein Textfeld.
		await expect(contact.getByRole('combobox', { name: 'Land' })).toBeVisible()
		await expect(contact.getByLabel('Mitgliedsnummer')).toHaveCount(0)
		await expect(contact.getByLabel('Mitglied seit')).toHaveCount(0)

		// Der Hinweis erscheint nur bei einer geänderten Adresse; ohne Speichern (keine Mails) wieder zurück.
		const hint = 'Bei einer Änderung der E-Mail-Adresse erhält die bisherige Adresse zur Sicherheit eine Mail darüber.'
		await expect(contact.getByText(hint)).toHaveCount(0)
		await contact.getByLabel('E-Mail', { exact: true }).fill('jana.neu@example.org')
		await expect(contact.getByText(hint)).toBeVisible()
		await contact.getByLabel('E-Mail', { exact: true }).fill(member.email)
		await expect(contact.getByText(hint)).toHaveCount(0)

		await contact.getByLabel('Telefon', { exact: true }).fill('+49 30 5550123')
		await contact.getByLabel('Straße', { exact: true }).fill('Neuer Weg 7')
		await contact.getByRole('button', { name: 'Speichern' }).click()
		await expect(toast(page, 'Kontaktdaten gespeichert.')).toBeVisible()
		await expect(contact.getByText('+49 30 5550123')).toBeVisible()
		await expect(contact.getByText('Neuer Weg 7, 10115 Berlin')).toBeVisible()

		// Die Kassenführung sieht die Änderung; die interne Notiz blieb unberührt.
		const stored = await api.getJson(request, `/members/${member.id}`)
		expect(stored).toMatchObject({ phone: '+49 30 5550123', street: 'Neuer Weg 7', internalNote: NOTE })
	})

	test('Du-Fassung (Konto auf „Deutsch“): in der Karte und in den Dialogen bleibt kein „Sie“ oder „Ihr“ stehen', async ({ page }) => {
		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		await expect(section.getByText('zu deiner Mitgliedschaft gespeicherten Daten')).toBeVisible()
		await expect(section).not.toContainText(SIE_FORM)

		await mandateCard(section).getByRole('button', { name: 'Mandat widerrufen' }).click()
		let dialog = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await expect(dialog).toContainText('brauchst du danach ein neues Mandat')
		await expect(dialog).not.toContainText(SIE_FORM)
		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(dialog).toBeHidden()

		await mandateCard(section).getByRole('button', { name: 'Bankverbindung ändern' }).click()
		dialog = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await expect(dialog).toContainText('du kannst die IBAN bis zur Einreichung des nächsten Einzugs jederzeit ändern')
		await expect(dialog).not.toContainText(SIE_FORM)
		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(dialog).toBeHidden()
	})

	test('Betrag ändern: Meldung unter der Untergrenze, nur die erlaubten Turnusse, Speichern erst nach einer Vorschau für genau diese Werte', async ({ page }) => {
		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		const card = assignmentCard(section, GROUP_FULL.name)
		const save = card.getByRole('button', { name: 'Speichern' })
		await expect(save).toBeDisabled()

		// Das Turnus-Feld bietet nur, was die Gruppe erlaubt.
		await expect(card.getByLabel('Turnus').locator('option')).toHaveText(['monatlich', 'vierteljährlich', 'halbjährlich', 'jährlich'])

		// Unter der Untergrenze: Meldung, keine Vorschau, nichts wird gespeichert.
		await card.getByLabel('Monatsbeitrag (€)').fill('11')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(toast(page, 'Der Monatsbeitrag darf die Untergrenze von 12,00 € nicht unterschreiten.')).toBeVisible()
		await expect(card.getByText(/Wirkt ab/)).toHaveCount(0)
		await expect(save).toBeDisabled()

		// Darüber: die Vorschau gilt für genau diese Eingabe, jede weitere Änderung sperrt Speichern wieder.
		await card.getByLabel('Monatsbeitrag (€)').fill('20')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(card.getByText(/Wirkt ab \d{2}\.\d{2}\.\d{4} · erster Einzug am \d{2}\.\d{2}\.\d{4} · Betrag/)).toBeVisible()
		await expect(save).toBeEnabled()
		await card.getByLabel('Monatsbeitrag (€)').fill('21')
		await expect(save).toBeDisabled()
		await expect(card.getByText(/Wirkt ab/)).toHaveCount(0)
	})

	test('individuelle Untergrenze: das Mitglied sieht den eigenen Wert statt der Gruppen-Untergrenze, aber nie die Begründung', async ({ page, request }) => {
		const reason = `Familienrabatt laut Vorstandsbeschluss ${unique('E56')}`
		const special = await createMember(request, 'Anna', 'Sonderfall')
		const assignment = await createAssignment(request, {
			memberId: special.id,
			groupId: group.id,
			intervalMonths: 3,
			monthlyAmount: 15,
			minMonthlyAmountOverride: 10,
			overrideReason: reason,
		})
		await linkOnly(request, special.id, USERS.ohneRolle)

		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		const card = assignmentCard(section, GROUP_FULL.name)
		await expect(card).toContainText(/Untergrenze: 10,00\s*€/)
		await expect(card).not.toContainText('12,00')
		await expect(page.locator('body')).not.toContainText(reason)

		// 11 € liegen über der individuellen Untergrenze: eine Vorschau, keine Untergrenzen-Meldung.
		await card.getByLabel('Monatsbeitrag (€)').fill('11')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(card.getByText(/Wirkt ab/)).toBeVisible()
		await expect(page.getByText('nicht unterschreiten')).toHaveCount(0)

		// Auch die Schnittstelle liefert die Begründung nicht aus.
		const own = await api.selfAssignments(request, { user: USERS.ohneRolle })
		expect(JSON.stringify(own)).not.toContain(reason)
		expect(own.find((a) => a.id === assignment.id)).not.toHaveProperty('overrideReason')
	})
})

// ---------------------------------------------------------------------------
// 11.11 / 12.4 / 12.5: Beitragsbestätigung und Datenübersicht aus Mitgliedssicht
// ---------------------------------------------------------------------------

test.describe('Beitragsbestätigung und Datenübersicht aus Mitgliedssicht (Protokoll 11.11, 12.4, 12.5)', () => {
	let member
	let stranger
	let labels

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await setUpBook(request)
		const group = await ensureGroup(request, GROUP_FULL)
		const tag = unique('E56')
		labels = {
			current: `Beitrag laufendes Jahr ${tag}`,
			previous: `Beitrag Vorjahr ${tag}`,
			fee: `Gebühr Mahnung ${tag}`,
			open: `Beitrag noch offen ${tag}`,
			stranger: `Beitrag Fremdling ${tag}`,
		}

		member = await createMember(request, 'Jana', 'Zertifikat', { street: 'Musterweg 1', postalCode: '10115', city: 'Berlin' })
		await activeMandate(request, member)
		await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 3, monthlyAmount: 15, paymentMethod: 'direct_debit' })
		await createClaim(request, member.id, { amount: 15, label: labels.current, paid: true })
		await createClaim(request, member.id, { amount: 20, label: labels.previous, dueDate: `${new Date().getUTCFullYear() - 1}-06-15`, paid: true })
		await createClaim(request, member.id, { type: 'gebuehr', amount: 7.5, label: labels.fee, paid: true })
		await createClaim(request, member.id, { amount: 9, label: labels.open })

		// Ein zweites Mitglied mit eigener bezahlter Forderung: nichts davon darf in den Seiten des ersten stehen.
		stranger = await createMember(request, 'Fritz', 'Fremdling-Zertifikat')
		await createClaim(request, stranger.id, { amount: 33, label: labels.stranger, paid: true })
	})

	test.beforeEach(async ({ request }) => {
		await linkOnly(request, member.id, USERS.ohneRolle)
	})

	test.afterAll(async ({ request }) => {
		await unlinkAccount(request, USERS.ohneRolle)
		await switchModuleOff(request)
	})

	test('Beitragsbestätigung: nur eigene bezahlte Beiträge des gewählten Jahres, keine Gebühr, kein Offenes, keine fremden Daten', async ({ page, request }) => {
		test.setTimeout(90000)
		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		const card = cardWithHeading(section, 'Meine Beitragsbestätigung')
		await expect(card.getByText('Informelle Bestätigung der bezahlten Beiträge eines Beitragsjahres – kein amtlicher Spendennachweis nach § 10b EStG.')).toBeVisible()

		// Die Auswahl bietet die Jahre mit einem bezahlten Beitrag und das laufende, neuestes zuerst.
		const { years } = await api.getJson(request, '/self/certificate/years', { user: USERS.ohneRolle })
		expect(years.length).toBeGreaterThanOrEqual(2)
		const select = card.getByLabel('Beitragsjahr')
		await expect(select.locator('option')).toHaveText(years.map(String))
		await expect(select).toHaveValue(String(years[0]))

		// Laufendes Jahr: nur der bezahlte Beitrag dieses Jahres, Summe 15,00 €.
		let popup = await openInNewTab(page, card.getByRole('link', { name: 'Öffnen', exact: true }))
		await expect(popup.getByRole('heading', { name: /Beitragsbestätigung/ })).toBeVisible()
		await expect(popup.getByText(member.displayName)).toBeVisible()
		await expect(popup.getByText('Musterweg 1')).toBeVisible()
		await expect(popup.getByText(labels.current)).toBeVisible()
		await expect(popup.locator('tr.sum')).toContainText(/15,00\s*€/)
		await expect(popup.getByText(/§ ?10b EStG/)).toBeVisible()
		for (const absent of [labels.previous, labels.fee, labels.open, labels.stranger, stranger.displayName]) {
			await expect(popup.locator('body'), `„${absent}“ gehört nicht in die Bestätigung`).not.toContainText(absent)
		}
		await popup.close()

		// Vorjahr: nur der Beitrag des Vorjahres, Summe 20,00 €.
		await select.selectOption(String(years[1]))
		popup = await openInNewTab(page, card.getByRole('link', { name: 'Öffnen', exact: true }))
		await expect(popup.getByText(labels.previous)).toBeVisible()
		await expect(popup.locator('tr.sum')).toContainText(/20,00\s*€/)
		await expect(popup.locator('body')).not.toContainText(labels.current)
		await popup.close()

		// Die Mitglieds-ID kommt aus der Kontoverknüpfung, nicht aus der Adresse: eine fremde ID im Aufruf ändert nichts.
		const spoofed = await api.raw(request, 'GET', `/self/certificate?year=${years[0]}&memberId=${stranger.id}`, { user: USERS.ohneRolle })
		expect(spoofed.status()).toBe(200)
		const html = await spoofed.text()
		expect(html).toContain(labels.current)
		expect(html).not.toContain(labels.stranger)
	})

	test('Datenübersicht (Art. 15 DSGVO): alle Abschnitte mit den eigenen Daten, IBAN maskiert, kein Export, nichts von anderen', async ({ page, request }) => {
		test.setTimeout(90000)
		const section = await openMeinBeitrag(page, USERS.ohneRolle)
		const card = cardWithHeading(section, 'Meine Daten')
		await expect(card.getByText(/Druckfertige Auskunft nach Art\. 15 DSGVO über alle zu deiner Mitgliedschaft gespeicherten Daten/)).toBeVisible()

		const popup = await openInNewTab(page, card.getByRole('link', { name: 'Datenübersicht öffnen' }))
		await expect(popup.getByRole('heading', { name: 'Datenübersicht' })).toBeVisible()
		await expect(popup.getByText(/Auskunft nach Art\. 15 DSGVO \(kein strukturierter Export nach Art\. 20\)/)).toBeVisible()
		for (const heading of ['Stammdaten', 'SEPA-Lastschriftmandate', 'Forderungen', 'Beitragszuweisungen']) {
			await expect(popup.getByRole('heading', { name: heading, exact: true })).toBeVisible()
		}

		// Eigene Daten: Name, Mandat mit maskierter IBAN, die Forderungen (auch Gebühr und Offenes) und die Zuweisung.
		await expect(popup.getByText(member.displayName).first()).toBeVisible()
		await expect(popup.getByText(IBAN_MASKED)).toBeVisible()
		for (const own of [labels.current, labels.previous, labels.fee, labels.open, GROUP_FULL.name]) {
			await expect(popup.getByText(own).first()).toBeVisible()
		}
		const body = popup.locator('body')
		await expect(body).not.toContainText(IBAN)
		for (const absent of [labels.stranger, stranger.displayName]) {
			await expect(body, `„${absent}“ gehört nicht in die Datenübersicht`).not.toContainText(absent)
		}

		// Kein Export (kein Art. 20): weder Knopf noch Download-Link.
		await expect(popup.getByRole('button')).toHaveCount(0)
		await expect(popup.getByRole('link', { name: /export|csv|json|download/i })).toHaveCount(0)
		await popup.close()

		// Auch hier kommt die Mitglieds-ID aus der Kontoverknüpfung.
		const spoofed = await api.raw(request, 'GET', `/self/data-overview?memberId=${stranger.id}`, { user: USERS.ohneRolle })
		expect(spoofed.status()).toBe(200)
		const html = await spoofed.text()
		expect(html).toContain(labels.current)
		expect(html).not.toContain(labels.stranger)
	})
})

// ---------------------------------------------------------------------------
// 11.8 / 14.2: Mandat-Entwurf bestätigen, Du- und Sie-Dialog
// ---------------------------------------------------------------------------

test.describe('Mandat-Entwurf im Self-Service bestätigen (Protokoll 11.8, 14.2)', () => {
	test.beforeAll(async ({ request }) => {
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await setUpBook(request)
	})

	test.afterAll(async ({ request }) => {
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await unlinkAccount(request, USERS.ohneRolle)
		await switchModuleOff(request)
	})

	test('der Dialog „Mandat bestätigen“ duzt auf „Deutsch“ und siezt auf „Deutsch (förmlich)“; die Bestätigung erteilt das Mandat sofort', async ({ page, request }) => {
		test.setTimeout(120000)
		const note = 'Mandat per Einmal-Link oder im Self-Service bestätigen lassen.'
		const member = await createMember(request, 'Jonas', 'Zustimmer', { internalNote: note })
		await linkOnly(request, member.id, USERS.ohneRolle)
		const draft = await (await api.createElectronicMandate(request, { memberId: member.id, iban: IBAN_NEW, accountHolder: member.displayName })).json()

		try {
			// Informelles Deutsch: Du-Fassung. Ein elektronischer Entwurf bietet bestätigen, Link per Mail oder verwerfen.
			await setUserLanguage(request, USERS.ohneRolle, 'de')
			let section = await openMeinBeitrag(page, USERS.ohneRolle)
			for (const button of ['Jetzt bestätigen', 'Stattdessen Link per Mail zuschicken', 'Entwurf verwerfen']) {
				await expect(mandateCard(section).getByRole('button', { name: button })).toBeVisible()
			}
			await expect(page.locator('body')).not.toContainText('Einmal-Link oder im Self-Service')

			await mandateCard(section).getByRole('button', { name: 'Jetzt bestätigen' }).click()
			let dialog = page.getByRole('dialog', { name: 'Mandat bestätigen' })
			await expect(dialog).toContainText('Für dich liegt ein elektronischer Mandats-Entwurf vor. Mit der Bestätigung erteilst du das SEPA-Lastschriftmandat – wirksam ab sofort.')
			await expect(dialog.getByText(IBAN_NEW_MASKED)).toBeVisible()
			await dialog.getByRole('button', { name: 'Abbrechen' }).click()
			await expect(dialog).toBeHidden()

			// Förmliches Deutsch: der Quelltext in Sie-Form.
			await setUserLanguage(request, USERS.ohneRolle, 'de_DE')
			section = await openMeinBeitrag(page, USERS.ohneRolle)
			await mandateCard(section).getByRole('button', { name: 'Jetzt bestätigen' }).click()
			dialog = page.getByRole('dialog', { name: 'Mandat bestätigen' })
			await expect(dialog).toContainText('Für Sie liegt ein elektronischer Mandats-Entwurf vor. Mit der Bestätigung erteilen Sie das SEPA-Lastschriftmandat – wirksam ab sofort.')
			await expect(dialog.getByText(IBAN_NEW_MASKED)).toBeVisible()
			await expect(dialog.getByRole('heading', { name: 'Mandatstext' })).toBeVisible()

			await dialog.getByRole('button', { name: 'Ich stimme zu und erteile das Mandat' }).click()
			await expect(toast(page, 'Mandat erteilt.')).toBeVisible()
			await expect(mandateCard(section).getByText('Aktiv')).toBeVisible()
			await expect(mandateCard(section).getByRole('button', { name: 'Jetzt bestätigen' })).toHaveCount(0)
			await expect(mandateCard(section).getByRole('button', { name: 'Mandat widerrufen' })).toBeVisible()
			await expect(page.locator('body')).not.toContainText('Einmal-Link oder im Self-Service')

			// Die Verwaltung sieht das Mandat als aktiv, bestätigt vom Kanal „Mitglied“.
			const confirmed = await api.getJson(request, `/mandates/${draft.id}`)
			expect(confirmed.status).toBe('aktiv')
			expect(confirmed.history.some((entry) => entry.actorType === 'member')).toBe(true)
		} finally {
			await setUserLanguage(request, USERS.ohneRolle, 'de')
		}
	})
})

// ---------------------------------------------------------------------------
// 13.6 / 13.7: „Mein Beitrag“ folgt Schalter und Verknüpfung, nicht der Rolle
// ---------------------------------------------------------------------------

test.describe('Zugang zu „Mein Beitrag“ (Protokoll 13.6, 13.7)', () => {
	test.beforeAll(async ({ request }) => {
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await setUpBook(request)
	})

	test.afterAll(async ({ request }) => {
		await unlinkAccount(request, USERS.ohneRolle)
		await switchModuleOff(request)
	})

	test('verknüpft und eingeschaltet: Reiter da; Schalter aus oder Verknüpfung gelöst: „Kein Zugriff“ und eine gesperrte Schnittstelle', async ({ page, request }) => {
		test.setTimeout(90000)
		const member = await createMember(request, 'Zita', 'Zugang')
		await linkOnly(request, member.id, USERS.ohneRolle)
		// test4 ist 'Deutsch': die Meldung steht in der Du-Fassung (l10n/de.json), die Sie-Fassung im Quelltext.
		const noAccess = /keine Berechtigung für die Vereinsbuchhaltung\. Bitte wen(de dich|den Sie sich) an eine Verwalterin oder einen Verwalter\./

		// Verknüpft, Schalter an: kein „Kein Zugriff“, dafür der Reiter – ohne jede Rolle.
		await openApp(page, USERS.ohneRolle)
		await expect(tabButton(page, 'Mein Beitrag')).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Kein Zugriff' })).toHaveCount(0)
		expect((await api.raw(request, 'GET', '/self/me', { user: USERS.ohneRolle })).status()).toBe(200)
		expect((await api.raw(request, 'GET', '/accounts', { user: USERS.ohneRolle })).status()).toBe(403)

		// Schalter aus, Konto weiter verknüpft: „Kein Zugriff“, kein Reiter, die Self-Service-Schnittstelle antwortet 403.
		await api.updateSettings(request, { self_service_enabled: '0' })
		await page.reload()
		await waitForAppLoaded(page)
		await expect(page.getByRole('heading', { name: 'Kein Zugriff' })).toBeVisible()
		await expect(page.getByText(noAccess)).toBeVisible()
		await expect(tabButton(page, 'Mein Beitrag')).toHaveCount(0)
		expect((await api.raw(request, 'GET', '/self/me', { user: USERS.ohneRolle })).status()).toBe(403)

		// Schalter wieder an: der Reiter ist wieder da.
		await api.updateSettings(request, { self_service_enabled: '1' })
		await page.reload()
		await waitForAppLoaded(page)
		await expect(tabButton(page, 'Mein Beitrag')).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Kein Zugriff' })).toHaveCount(0)

		// Schalter an, aber keine Verknüpfung (13.6): wieder „Kein Zugriff“, ohne Reiter und ohne Daten.
		await api.unlinkMember(request, member.id)
		await page.reload()
		await waitForAppLoaded(page)
		await expect(page.getByRole('heading', { name: 'Kein Zugriff' })).toBeVisible()
		await expect(page.getByText(noAccess)).toBeVisible()
		await expect(page.locator('.vbh-tabs button')).toHaveCount(0)
		expect((await api.raw(request, 'GET', '/self/me', { user: USERS.ohneRolle })).status()).toBe(403)
	})
})

// ---------------------------------------------------------------------------
// 13.4 / 13.5: Revisor sieht im Forderungsdetail keinen Rückgabecode
// ---------------------------------------------------------------------------
//
// 44 deckt den Rückgabecode im Bankabgleich ab, 43 verweist für das Forderungsdetail auf
// PHPUnit (ClaimOverviewServiceTest). Hier steht die Probe an der echten Oberfläche: Der
// Buchhalter sieht „Rückgabecode (nur für die Buchhaltung sichtbar)“, der Revisor liest
// dieselbe Rücklastschrift in Klartext, ohne den Code und ohne den Freitext der Bank.
//
// Die Rücklastschrift entsteht wie in 52 auf dem echten Weg (Lauf freigeben und einreichen,
// camt-Rückgabe importieren, Zeile bestätigen, verbuchen). Die Zahlungsaufforderung danach geht
// aus einem Web-Request raus: Mail-Modus „null“ (siehe 44, 52).

test.describe('Revisor und Rückgabecode im Forderungsdetail (Protokoll 13.4, 13.5)', () => {
	let member

	test.beforeAll(async ({ request }) => {
		test.setTimeout(180000)
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
		await sleep(4000)

		await setUpBook(request)
		await unlinkAccount(request, USERS.ohneRolle)
		const [income, fee] = await api.accountsByNumber(request, INCOME_ACCOUNT, FEE_ACCOUNT)
		await api.setSepaImportSettings(request, { returnFeeAccountId: fee.id, returnFeeRechargeEnabled: '0', contributionDefaultAccountId: income.id })

		member = await createMember(request, 'Rueckgabe', 'Codeprobe')
		await activeMandate(request, member)
		const label = `Beitrag Codeprobe ${unique('E56')}`
		const dueDate = plusDays(41)
		await createClaim(request, member.id, { amount: 12.5, label, dueDate })
		const batch = await api.releaseAndSubmitDebitBatch(request, dueDate)
		const item = batch.items.find((i) => i.remittanceInfo === label)
		expect(item, 'Posten des Laufs').toBeTruthy()

		await api.importCamtStatement(request, [returnEntry({ bookingDate: today(), item, reasonCode: 'AM04', reasonText: 'Insufficient funds' })])
		const worklist = await api.findReconciliationItem(request, `Rücklastschrift ${item.memberDisplayName}`)
		await api.decideSepaDetail(request, worklist.details[0].id, 'assign', { debitItemId: item.id })
		await api.settleSepaImport(request, worklist.bankTx.id)
	})

	test.afterAll(async () => {
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	/** Öffnet Beiträge → Einzug → Forderungen → Details der Forderung dieses Mitglieds (jedes Konto in seiner eigenen Sitzung). */
	async function openDetail(page, user) {
		await openApp(page, user)
		if (user === USERS.revisor) { await dismissRevisorIntro(page) }
		await switchTab(page, 'Beiträge')
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
		await visibleSection(page).getByRole('tab', { name: 'Forderungen', exact: true }).click()
		const panel = visibleSection(page).getByRole('tabpanel', { name: 'Forderungen' })
		await panel.locator('tbody tr', { hasText: member.displayName }).first().getByRole('button', { name: /^Details zur Forderung/ }).click()
		const detail = panel.locator('.vbh-claimdetail')
		await expect(detail).toBeVisible()
		await expect(detail).toContainText('Rücklastschrift vom')
		return detail
	}

	test('der Buchhalter sieht Rückgabecode und Bankfreitext, der Revisor nur den Klartext', async ({ page, browser, request }) => {
		test.setTimeout(90000)
		const asBookkeeper = await openDetail(page, USERS.buchhalter)
		await expect(asBookkeeper).toContainText('Rückgabecode (nur für die Buchhaltung sichtbar):')
		await expect(asBookkeeper).toContainText('AM04')
		await expect(asBookkeeper).toContainText('Insufficient funds')

		const revisorContext = await browser.newContext()
		try {
			const asRevisor = await openDetail(await revisorContext.newPage(), USERS.revisor)
			await expect(asRevisor.getByText('Rücklastschrift vom')).toBeVisible()
			for (const forbidden of ['Rückgabecode', 'AM04', 'Insufficient funds']) {
				await expect(asRevisor, `„${forbidden}“ sieht der Revisor nicht`).not.toContainText(forbidden)
			}
			// Keine Aktionsknöpfe und kein Sprung in die Akte (Mitglieder: ab Buchhalter).
			await expect(asRevisor.getByRole('button')).toHaveCount(0)
		} finally {
			await revisorContext.close()
		}

		// Dasselbe an der Schnittstelle: der Schlüssel fehlt ganz.
		const forRevisor = await api.raw(request, 'GET', '/claims/overview', { user: USERS.revisor })
		expect(forRevisor.ok()).toBeTruthy()
		const revisorJson = await forRevisor.text()
		expect(revisorJson).not.toMatch(/AM04|Insufficient|reasonCode|reasonText/)
		const forBookkeeper = await api.raw(request, 'GET', '/claims/overview', { user: USERS.buchhalter })
		expect(await forBookkeeper.text()).toContain('AM04')
	})
})

// ---------------------------------------------------------------------------
// 14.4 / 14.6: Englisch
// ---------------------------------------------------------------------------

test.describe('Englisch (Protokoll 14.4, 14.6)', () => {
	test.beforeAll(async ({ request }) => {
		test.setTimeout(90000)
		await setUserLanguage(request, USERS.englisch, 'en')
		await setUpBook(request)
		await unlinkAccount(request, USERS.englisch)
		// 16-l10n vergibt test5 die Leserolle für die Sprachprüfung und lässt sie stehen; 51 setzt sie nach sich wieder darauf.
		await api.setRole(request, USERS.englisch, 'revisor')
	})

	test.afterAll(async ({ request }) => {
		await unlinkAccount(request, USERS.englisch)
		await switchModuleOff(request)
		await setUserLanguage(request, USERS.englisch, 'en')
	})

	test('„My contribution“ ohne Mandat: Überschriften, Hinweise und Knöpfe auf Englisch, Untergrenze, keine Anschrift, keine interne Notiz', async ({ page, request }) => {
		const group = await ensureGroup(request, GROUP_REDUCED)
		const member = await createMember(request, 'Lena', 'Englisch', { internalNote: 'Studentin, keine Anschrift hinterlegt (interne Notiz).' })
		await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 3, monthlyAmount: 7.5 })
		await linkOnly(request, member.id, USERS.englisch)

		const section = await openMeinBeitrag(page, USERS.englisch, { tab: 'My contribution', firstHeading: 'My details' })
		for (const heading of ['My details', 'My contribution', 'My SEPA direct debit mandate', 'Returned direct debits', 'My contribution confirmation', 'My data']) {
			await expect(section.getByRole('heading', { name: heading, exact: true })).toBeVisible()
		}

		// Ohne Mandat: Hinweis und Knopf zum Erteilen; keine Rücklastschrift; Links zu Bestätigung und Datenübersicht.
		await expect(section.getByText('No mandate on file.')).toBeVisible()
		await expect(section.getByRole('button', { name: 'Grant mandate now' })).toBeVisible()
		await expect(section.getByText('No returned direct debit so far.')).toBeVisible()
		await expect(section.getByRole('link', { name: 'Open data overview' })).toBeVisible()

		// Die Daten des Mitglieds: Gruppe, Untergrenze, keine Anschrift (also keine Adresszeile), keine interne Notiz.
		await expect(assignmentCard(section, GROUP_REDUCED.name)).toContainText(/Lower limit: 5,00\s*€/)
		await expect(section.locator('dt', { hasText: 'Address' })).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('Studentin')

		// Keine deutschen Reste in den Karten.
		await expect(section).not.toContainText(GERMAN_REMNANTS)
	})

	test('Einzug-Reiter des Revisors auf Englisch: nur „Collection“ mit den drei Segmenten, lesend, ohne deutsche Reste', async ({ page }) => {
		await openApp(page, USERS.englisch)
		await expect(tabButton(page, 'Contributions')).toBeVisible()
		await tabButton(page, 'Contributions').click()
		const section = visibleSection(page)

		// Ein Revisor sieht von „Contributions“ nur den Unterreiter „Collection“.
		const subtabs = section.locator('.vbh-subtabs')
		await expect(subtabs.locator('button')).toHaveCount(1)
		await expect(subtabs.getByRole('button', { name: 'Collection', exact: true })).toBeVisible()

		const segments = section.locator('.vbh-segments')
		await expect(segments.getByRole('tab', { name: 'Timeline & runs' })).toBeVisible()
		await expect(segments.getByRole('tab', { name: 'Claims' })).toBeVisible()
		await expect(segments.getByRole('tab', { name: /^Bank reconciliation/ })).toBeVisible()
		await expect(section.locator('.vbh-tl h4')).toContainText('Contribution year')

		// Lesend: kein Terminplan („Schedule“), keine Freigabe.
		await expect(section.getByRole('button', { name: 'Schedule', exact: true })).toHaveCount(0)
		await expect(section.getByRole('button', { name: 'Release & create file' })).toHaveCount(0)

		await segments.getByRole('tab', { name: /^Bank reconciliation/ }).click()
		await expect(section.getByText('You can only view the bank reconciliation. Judging and posting require the bookkeeper role.')).toBeVisible()

		// Gebaut wird nur aus Beschriftungen der App; Namen aus dem Bestand anderer Specs („Zeitstrahl Ohnemandat“ …) stehen nicht in diesen Leisten.
		await expect(subtabs).not.toContainText(GERMAN_REMNANTS)
		await expect(segments).not.toContainText(GERMAN_REMNANTS)
		await expect(section.locator('.vbh-tl h4')).not.toContainText(GERMAN_REMNANTS)
	})
})

// ---------------------------------------------------------------------------
// 11.9 / 14.3 / 14.7: Quittungsmail der Beitragsänderung
// ---------------------------------------------------------------------------
//
// Die Mail folgt der Sprache des Kontos (Du-Fassung, Englisch); Beträge sind deutsch
// formatiert, Daten stehen als TT.MM.JJJJ. Gelesen wird die
// echte Mail über den sendmail-Ersatz (fixtures/mail-capture.mjs).

test.describe('Quittungsmail der Beitragsänderung (Protokoll 11.9, 14.3, 14.7)', () => {
	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await setUserLanguage(request, USERS.englisch, 'en')
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await setUpBook(request)
		await startMailCapture()
	})

	test.afterAll(async ({ request }) => {
		await stopMailCapture()
		for (const uid of [USERS.englisch, USERS.ohneRolle]) {
			await unlinkAccount(request, uid)
		}
		await switchModuleOff(request)
	})

	/** Mitglied mit Überweisungs-Zuweisung (10 €, alle 3 Monate), Beitrag und Turnus ändert das Konto selbst; liefert die abgegriffene Mail. */
	async function receiptMail(request, uid, firstName) {
		const group = await ensureGroup(request, GROUP_REDUCED)
		const member = await createMember(request, firstName, 'Quittung')
		const assignment = await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 3, monthlyAmount: 10 })
		await linkOnly(request, member.id, uid)
		await clearCapturedMails()

		await api.selfUpdateAssignment(request, assignment.id, { monthlyAmount: 15, intervalMonths: 12, user: uid })
		const [mail] = await waitForMailsTo(member.email)
		// Zeilenumbrüche der Vorlage glätten; geprüft wird der Text bis zum Schlusssatz, nicht die Fußzeile von Nextcloud (Slogan).
		const text = mail.text.replace(/\s+/g, ' ')
		const end = text.search(/nicht nötig\.|no action is needed on your part\./)
		expect(end, 'Schlusssatz der Quittungsmail').toBeGreaterThan(0)
		return { member, mail: { ...mail, text, body: text.slice(0, end) } }
	}

	test('Konto auf „Deutsch“: Du-Fassung mit deutschen Beträgen und Daten', async ({ request }) => {
		test.setTimeout(90000)
		const { mail } = await receiptMail(request, USERS.ohneRolle, 'Dora')

		expect(mail.subject).toBe('Dein Beitrag wurde geändert')
		expect(mail.text).toContain('Dein Monatsbeitrag wurde von 10,00 € auf 15,00 € geändert.')
		expect(mail.text).toContain('Dein Zahlungsturnus wurde von alle 3 Monate auf alle 12 Monate geändert.')
		expect(mail.text).toMatch(/Wirkt ab: \d{2}\.\d{2}\.\d{4}/)
		expect(mail.text).toMatch(/Voraussichtlich erster betroffener Einzug: \d{2}\.\d{2}\.\d{4}/)
		expect(mail.text).toContain('eine Handlung deinerseits ist nicht nötig')
		expect(mail.body).not.toMatch(SIE_FORM)
	})

	test('Konto auf Englisch: englischer Text, aber Beträge „10,00 €“ und Daten „TT.MM.JJJJ“ wie im Deutschen', async ({ request }) => {
		test.setTimeout(90000)
		const { mail } = await receiptMail(request, USERS.englisch, 'Emma')

		expect(mail.subject).toBe('Your contribution has been changed')
		expect(mail.text).toContain('Your monthly fee has been changed from 10,00 € to 15,00 €.')
		expect(mail.text).toContain('Your payment interval has been changed from every 3 months to every 12 months.')
		expect(mail.text).toMatch(/Effective from: \d{2}\.\d{2}\.\d{4}/)
		expect(mail.text).toMatch(/Expected first affected collection: \d{2}\.\d{2}\.\d{4}/)
		expect(mail.text).toContain('no action is needed on your part')
		expect(mail.body).not.toMatch(/\b(Ihr|Ihre|Dein|Deine|Wirkt|Guten|Voraussichtlich|Beitrag|Monatsbeitrag)\b/)
	})

	/**
	 * Wie receiptMail(), aber die KASSENFÜHRUNG ändert den Betrag (PUT /assignments/{id} als Verwalter): das Mitglied
	 * bekommt dieselbe Quittung, in der Sprache seines Kontos und mit der Kassenführung als Urheberin.
	 */
	async function staffReceiptMail(request, uid, firstName) {
		const group = await ensureGroup(request, GROUP_REDUCED)
		const member = await createMember(request, firstName, 'Kassenführung')
		const assignment = await createAssignment(request, { memberId: member.id, groupId: group.id, intervalMonths: 3, monthlyAmount: 10 })
		await linkOnly(request, member.id, uid)
		await clearCapturedMails()

		const response = await api.raw(request, 'PUT', `/assignments/${assignment.id}`, { data: { monthlyAmount: 15 } })
		expect(response.ok(), `Betrag ändern: ${response.status()} ${await response.text()}`).toBeTruthy()
		expect((await response.json()).receipt, 'die Antwort sagt, dass die Quittung rausging').toBe('sent')

		const [mail] = await waitForMailsTo(member.email)
		return { ...mail, text: mail.text.replace(/\s+/g, ' ') }
	}

	test('Beitragsänderung durch die Kassenführung: Quittung in der Du-Fassung des Mitglieds', async ({ request }) => {
		test.setTimeout(90000)
		const mail = await staffReceiptMail(request, USERS.ohneRolle, 'Lena')

		expect(mail.subject).toBe('Dein Beitrag wurde geändert')
		expect(mail.text).toContain('Die Kassenführung hat deinen Monatsbeitrag von 10,00 € auf 15,00 € geändert.')
		expect(mail.text).toMatch(/Wirkt ab: \d{2}\.\d{2}\.\d{4}/)
		expect(mail.text).toContain('eine Handlung deinerseits ist nicht nötig')
	})

	test('Beitragsänderung durch die Kassenführung: Konto auf Englisch bekommt die englische Quittung', async ({ request }) => {
		test.setTimeout(90000)
		const mail = await staffReceiptMail(request, USERS.englisch, 'Emil')

		expect(mail.subject).toBe('Your contribution has been changed')
		expect(mail.text).toContain('The treasurer has changed your monthly fee from 10,00 € to 15,00 €.')
		expect(mail.text).toMatch(/Effective from: \d{2}\.\d{2}\.\d{4}/)
		expect(mail.text).toContain('no action is needed on your part')
	})
})
