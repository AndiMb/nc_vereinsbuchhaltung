import { expect, test } from '@playwright/test'
import { startMailCapture, stopMailCapture, waitForMailsTo } from './fixtures/mail-capture.mjs'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BASE_URL, openApp, openSettingsPage, switchTab, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Neue Fassung des Mandatstexts: Mitglieder mit wirksamem Mandat bestätigen sie erneut.
//
// Eine neue Fassung ändert bestehende Mandate nicht. Die Verwaltung schreibt auf der
// Einstellungsseite (Mandats-Rechtstext) alle an, die die aktuelle Fassung noch nicht
// tragen; der Link in der Mail öffnet ohne Anmeldung die Zustimmungsseite mit dem neuen
// Text und den bisherigen Angaben. Es entsteht KEIN neues Mandat – Referenz, IBAN und
// Unterschriftsdatum bleiben, nur das Zustimmungs-Protokoll wird ersetzt.
//
// Die Mails gehen an den sendmail-Ersatz im Container (fixtures/mail-capture.mjs): geprüft
// wird, WAS verschickt wurde und dass der Link in der Mail tatsächlich funktioniert.
//
// Mandate räumt `resetBook()` nicht ab: Reste anderer Specs mit Mailadresse bekommen beim
// Versand ebenfalls einen Link. Die Spec rechnet deshalb nie mit absoluten Zahlen, sondern
// mit den Mandats-IDs aus der Übersicht und mit der Größe von `readyIds`/`pendingIds`.

const CLUB_NAME = 'Testverein e.V.'
// Eindeutig je Lauf: der Rahmen darf sich nie mit dem aktuellen decken (sonst „unverändert“).
const RAHMEN = `Neue Fassung für die Bestätigung. (E2E ${Date.now()})`
const SUBJECT = 'Bitte bestätigen Sie Ihr SEPA-Lastschriftmandat erneut'

const MEMBERS = {
	anna: { firstName: 'Anna', lastName: 'Papier', email: 'anna.papier@example.org', iban: 'DE89370400440532013000' },
	bernd: { firstName: 'Bernd', lastName: 'Elektronisch', email: 'bernd.elektronisch@example.org', iban: 'DE12500105170648489890' },
	clara: { firstName: 'Clara', lastName: 'Ohnemail', email: undefined, iban: 'DE44500105175407324931' },
	dora: { firstName: 'Dora', lastName: 'Widerruf', email: 'dora.widerruf@example.org', iban: 'DE02120300000000202051' },
}

const legalSection = (page) => page.locator('#settings-section_mandats-rechtstext')
const reconfirmCard = (page) => page.getByTestId('legaltext-reconfirm')
/** „Schließen“ der Aktionsleiste; das Kreuz des Fensters heißt ebenfalls so. */
const closeDialog = (dialog) => dialog.locator('.vbh-modal-actions').getByRole('button', { name: 'Schließen' }).click()
const dialogOf = (page) => page.getByRole('dialog', { name: 'Zur erneuten Bestätigung anschreiben' })

/**
 * Die Zustimmungsseite ist öffentlich – ein frischer Browserkontext ohne Anmeldung, wie bei einem Mitglied ohne Konto.
 * Deutsch eingestellt: der Verlauf des Mandats hält seine Einträge in der Sprache der Anfrage fest.
 */
async function asVisitor(browser, url) {
	const context = await browser.newContext({ locale: 'de-DE' })
	const page = await context.newPage()
	const response = await page.goto(url)
	return { page, context, response }
}

/** Der Token aus dem Link der Mail; die Seite wird über BASE_URL geöffnet, nicht über den Host, den der Container in die Mail schreibt. */
function consentUrlFrom(mail) {
	const match = /mandate-consent\/([A-Za-z0-9]+\.[A-Za-z0-9]+)/.exec(mail.text) ?? /mandate-consent\/([A-Za-z0-9]+\.[A-Za-z0-9]+)/.exec(mail.raw)
	expect(match, `Link zur Zustimmungsseite in der Mail „${mail.subject}“`).not.toBeNull()
	return `${BASE_URL}/index.php/apps/vereinsbuchhaltung/mandate-consent/${match[1]}`
}

async function reconfirmation(request) {
	return api.getJson(request, '/mandate-legal-text/reconfirmation')
}

async function currentVersionId(request) {
	return (await api.getJson(request, '/mandate-legal-text')).version.id
}

/** Mitglied und aktives Papier-Mandat (ohne hochgeladenes Dokument). */
async function paperMandate(request, spec, { signedAt = '2025-03-01' } = {}) {
	const displayName = `${spec.firstName} ${spec.lastName}`
	const member = (await api.listMembers(request)).find((m) => m.displayName === displayName)
		?? await api.createMember(request, { firstName: spec.firstName, lastName: spec.lastName, email: spec.email })
	const live = (await api.mandatesByMember(request, member.id)).find((m) => m.status !== 'erloschen')
	if (live) {
		return { member, mandate: live }
	}
	const created = await (await api.createMandate(request, { memberId: member.id, iban: spec.iban, signedAt })).json()
	const mandate = await (await api.activateMandate(request, created.id, { signedAt })).json()
	return { member, mandate }
}

test.describe('Mandatstext erneut bestätigen', () => {
	test.describe.configure({ mode: 'serial' })

	const ids = {}

	test.beforeAll(async ({ request, browser }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// Die Mitglieder-Akte und „Mein Beitrag“ gibt es erst mit eingeschalteter Mitgliederverwaltung.
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)
		await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
		await api.updateSettings(request, {
			club_name: CLUB_NAME,
			show_missing_document_warning: '1',
			membership_enabled: '1',
			sepa_creditor_id: 'DE98ZZZ09999999999',
			sepa_debtor_account_id: bank.id,
		})
		await startMailCapture()

		// Anna: Papier-Mandat, kein Dokument hinterlegt – bekommt die neue Fassung also erstmals elektronisch.
		const anna = await paperMandate(request, MEMBERS.anna)
		ids.anna = anna.mandate.id
		// Clara: wirksames Mandat, aber keine Mailadresse – nur auf Papier erreichbar.
		const clara = await paperMandate(request, MEMBERS.clara)
		ids.clara = clara.mandate.id
		// Dora: bekommt einen Link, widerruft aber danach – der Link darf nichts mehr bestätigen.
		const dora = await paperMandate(request, MEMBERS.dora)
		ids.dora = dora.mandate.id

		// Bernd: elektronisches Mandat, per Einmal-Link unter der BISHERIGEN Fassung erteilt.
		const bernd = (await api.listMembers(request)).find((m) => m.displayName === 'Bernd Elektronisch')
			?? await api.createMember(request, { firstName: MEMBERS.bernd.firstName, lastName: MEMBERS.bernd.lastName, email: MEMBERS.bernd.email })
		const draft = await (await api.createElectronicMandate(request, { memberId: bernd.id, iban: MEMBERS.bernd.iban })).json()
		ids.bernd = draft.id
		const { activationUrl } = await (await api.sendActivationLink(request, draft.id)).json()
		const { page, context } = await asVisitor(browser, activationUrl)
		await page.getByRole('button', { name: 'Ich stimme zu und erteile das Mandat' }).click()
		await expect(page.getByText('Bereits bestätigt', { exact: true })).toBeVisible()
		await context.close()
		ids.versionBefore = (await api.getJson(request, `/mandates/${ids.bernd}`)).mandateTextVersion
		expect(ids.versionBefore).toBe(await currentVersionId(request))

		// Die neue Fassung: ab jetzt tragen alle vier Mandate eine ältere (Anna, Clara, Dora gar keine).
		const saved = await api.raw(request, 'POST', '/mandate-legal-text', { data: { rahmen: RAHMEN }, expectOk: true })
		expect(saved.status()).toBe(201)
		ids.versionAfter = await currentVersionId(request)
		expect(ids.versionAfter).not.toBe(ids.versionBefore)
	})

	test.afterAll(async ({ request }) => {
		await stopMailCapture()
		await api.updateSettings(request, { club_name: CLUB_NAME })
	})

	test('die Übersicht trennt Anschreibbare von Mitgliedern ohne Mailadresse', async ({ request }) => {
		const overview = await reconfirmation(request)

		expect(overview.readyIds).toEqual(expect.arrayContaining([ids.anna, ids.bernd, ids.dora]))
		expect(overview.noEmail.map((m) => m.mandateId)).toContain(ids.clara)
		expect(overview.noEmail.find((m) => m.mandateId === ids.clara).name).toBe('Clara Ohnemail')
		expect(overview.pendingIds).not.toContain(ids.anna)
		expect(overview.withoutElectronicConsent).toBeGreaterThanOrEqual(3) // Anna, Clara, Dora tragen keine elektronische Zustimmung
		expect(overview.batchSize).toBeGreaterThan(0)
		expect(overview.validityDays).toBe(14)
	})

	test('nur die Verwaltung sieht den Stand und darf anschreiben', async ({ request }) => {
		for (const user of [USERS.buchhalter, USERS.revisor, USERS.ohneRolle]) {
			const overview = await api.raw(request, 'GET', '/mandate-legal-text/reconfirmation', { user })
			expect(overview.status(), `Übersicht als ${user}`).toBe(403)
			const send = await api.raw(request, 'POST', '/mandate-legal-text/reconfirmation/send', { user, data: { mandateIds: [ids.anna] } })
			expect(send.status(), `Versand als ${user}`).toBe(403)
		}
		// Und auch nicht über die Hintertür: ohne Mandat, oder mit zu vielen, schickt der Server nichts.
		const none = await api.raw(request, 'POST', '/mandate-legal-text/reconfirmation/send', { data: { mandateIds: [] } })
		expect(none.status()).toBe(400)
		const tooMany = await api.raw(request, 'POST', '/mandate-legal-text/reconfirmation/send', { data: { mandateIds: Array.from({ length: 26 }, (_, i) => i + 1) } })
		expect(tooMany.status()).toBe(400)
	})

	test('die Einstellungsseite zeigt den Stand, der Versand schreibt alle Anschreibbaren an', async ({ page, request }) => {
		const before = await reconfirmation(request)

		await openSettingsPage(page, USERS.admin)
		await expect(legalSection(page)).toBeVisible()
		const card = reconfirmCard(page)
		await expect(card).toBeVisible()
		await expect(card.getByText(/Noch nicht bestätigt: \d+/)).toBeVisible()
		await expect(card.getByText(/Ohne Mailadresse: \d+/)).toBeVisible()
		await expect(card.getByText('Clara Ohnemail', { exact: false })).toBeVisible()

		await page.getByTestId('reconfirm-open').click()
		const dialog = dialogOf(page)
		await expect(dialog).toBeVisible()
		// Ohne Haken nur die ohne gültigen Link: die Zahl im Knopf ist die Größe von readyIds.
		const send = dialog.getByRole('button', { name: `${before.readyIds.length} Mails verschicken` })
		await expect(send).toBeEnabled()
		await send.click()

		await expect(dialog.getByText(`Verschickt: ${before.readyIds.length}`)).toBeVisible()
		await closeDialog(dialog)
		await expect(dialog).toBeHidden()

		// Danach gelten die Angeschriebenen als „Link schon verschickt“.
		const after = await reconfirmation(request)
		expect(after.readyIds).toEqual([])
		expect(after.pendingIds).toEqual(expect.arrayContaining([ids.anna, ids.bernd, ids.dora]))
		await expect(card.getByText(/Link schon verschickt und noch gültig: \d+/)).toBeVisible()
	})

	test('die Mail nennt Verein und Referenz, der Link öffnet den neuen Text mit den bisherigen Angaben', async ({ browser, request }) => {
		const [mail] = await waitForMailsTo(MEMBERS.anna.email, { count: 1 })
		expect(mail.subject).toBe(SUBJECT)
		expect(mail.text).toContain(CLUB_NAME)
		const before = await api.getJson(request, `/mandates/${ids.anna}`)
		expect(mail.text).toContain(before.mandateReference)
		expect(mail.text).toContain('Ihr Mandat und Ihre Bankverbindung bleiben unverändert')
		// Die Bankverbindung gehört nicht in die Mail.
		expect(mail.raw).not.toContain(MEMBERS.anna.iban)

		const { page, context, response } = await asVisitor(browser, consentUrlFrom(mail))
		expect(response.status()).toBe(200)
		await expect(page.getByRole('heading', { name: 'SEPA-Lastschriftmandat', exact: true })).toBeVisible()
		await expect(page.getByText('Der Text Ihres SEPA-Lastschriftmandats wurde aktualisiert')).toBeVisible()
		// Der neue Rahmentext, die bisherigen Angaben – und der Marker nie.
		await expect(page.getByText(RAHMEN, { exact: false })).toBeVisible()
		await expect(page.getByText('vbh:rahmen', { exact: false })).toHaveCount(0)
		await expect(page.getByText(before.mandateReference)).toBeVisible()
		await expect(page.getByText('Anna Papier', { exact: false })).toBeVisible()
		await expect(page.getByText('DE89 3704 0044 0532 0130 00', { exact: false })).toBeVisible()
		// Kein Formularfeld: nichts muss neu getippt werden.
		await expect(page.locator('.vbh-consent-card').locator('input:not([type="hidden"]), textarea, select')).toHaveCount(0)
		// Eine Bestätigung, keine Erteilung.
		await expect(page.getByRole('button', { name: 'Ich stimme zu und erteile das Mandat' })).toHaveCount(0)

		// Aufrufen allein ändert nichts am Mandat.
		expect((await api.getJson(request, `/mandates/${ids.anna}`)).mandateTextVersion).toBeNull()

		await page.getByRole('button', { name: 'Ich bestätige den aktualisierten Mandatstext' }).click()
		await expect(page.getByText('Bereits bestätigt', { exact: true })).toBeVisible()
		await expect(page.getByText('den aktuellen Text Ihres SEPA-Lastschriftmandats bereits bestätigt', { exact: false })).toBeVisible()
		// Erneut geöffnet: weiterhin „bereits bestätigt“, keine zweite Bestätigung.
		await page.goto(consentUrlFrom(mail))
		await expect(page.getByText('Bereits bestätigt', { exact: true })).toBeVisible()
		await context.close()

		const after = await api.getJson(request, `/mandates/${ids.anna}`)
		// Es ist dasselbe Mandat, nur das Zustimmungs-Protokoll ist neu.
		expect(after.id).toBe(before.id)
		expect(after.status).toBe('aktiv')
		expect(after.mandateReference).toBe(before.mandateReference)
		expect(after.iban).toBe(before.iban)
		expect(after.signedAt).toBe(before.signedAt)
		expect(after.mandateTextVersion).toBe(ids.versionAfter)
		expect(after.consentActor).toBe(MEMBERS.anna.email)
		expect(after.consentAt).toBeTruthy()
		expect(after.consentIp).toBeTruthy()
		expect(after.consentUserAgent).toBeTruthy()
		// Die elektronische Zustimmung ist jetzt der Nachweis: die Warnung „Mandat ohne Nachweis“ entfällt.
		expect(before.showMissingDocumentWarning).toBe(true)
		expect(after.showMissingDocumentWarning).toBe(false)
		// Der Verlauf hält Versand und Bestätigung fest, aber keine IP und keine IBAN.
		const messages = after.history.map((e) => e.message)
		expect(messages.some((m) => m.includes('Link zur erneuten Bestätigung') && m.includes(MEMBERS.anna.email))).toBe(true)
		const confirmed = messages.find((m) => m.startsWith('Mandat erneut bestätigt'))
		expect(confirmed).toBeTruthy()
		expect(confirmed).toContain('vorher ohne elektronische Zustimmung')
		expect(messages.join('\n')).not.toContain(after.consentIp)
		expect(messages.join('\n')).not.toContain(MEMBERS.anna.iban)

		// Anna ist raus aus der Liste der noch offenen.
		const overview = await reconfirmation(request)
		expect(overview.pendingIds).not.toContain(ids.anna)
		expect(overview.readyIds).not.toContain(ids.anna)
	})

	test('ein elektronisches Mandat ersetzt seine alte Zustimmung und behält Referenz und Status', async ({ browser, request }) => {
		const before = await api.getJson(request, `/mandates/${ids.bernd}`)
		expect(before.mandateTextVersion).toBe(ids.versionBefore)
		const [mail] = await waitForMailsTo(MEMBERS.bernd.email, { count: 1 })
		// Bernd bekam vorher schon die Aktivierungsmail (anderer Betreff) – hier zählt die Bestätigungsmail.
		const confirmation = (await waitForMailsTo(MEMBERS.bernd.email, { count: 2 })).find((m) => m.subject === SUBJECT)
		expect(confirmation, `Bestätigungsmail an Bernd (erste Mail: „${mail.subject}“)`).toBeTruthy()

		const { page, context } = await asVisitor(browser, consentUrlFrom(confirmation))
		await page.getByRole('button', { name: 'Ich bestätige den aktualisierten Mandatstext' }).click()
		await expect(page.getByText('Bereits bestätigt', { exact: true })).toBeVisible()
		await context.close()

		const after = await api.getJson(request, `/mandates/${ids.bernd}`)
		expect(after.mandateTextVersion).toBe(ids.versionAfter)
		expect(after.mandateReference).toBe(before.mandateReference)
		expect(after.status).toBe('aktiv')
		expect(after.signedAt).toBe(before.signedAt)
		expect(new Date(after.consentAt).getTime()).toBeGreaterThanOrEqual(new Date(before.consentAt).getTime())
		// Der Verlauf nennt, wann vorher zugestimmt wurde.
		const confirmed = after.history.map((e) => e.message).find((m) => m.startsWith('Mandat erneut bestätigt'))
		expect(confirmed).toMatch(/vorher zugestimmt am \d{2}\.\d{2}\.\d{4}/)
	})

	test('wer widerrufen hat, kann mit dem alten Link nichts mehr bestätigen', async ({ browser, request }) => {
		const [mail] = await waitForMailsTo(MEMBERS.dora.email, { count: 1 })
		await api.revokeMandate(request, ids.dora)

		const { page, context, response } = await asVisitor(browser, consentUrlFrom(mail))
		expect(response.status()).toBe(404)
		await expect(page.getByText('Link ungültig')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Ich bestätige den aktualisierten Mandatstext' })).toHaveCount(0)
		await context.close()

		const after = await api.getJson(request, `/mandates/${ids.dora}`)
		expect(after.status).toBe('erloschen')
		expect(after.mandateTextVersion).toBeNull()
		// Und in der Übersicht taucht Dora nicht mehr auf.
		const overview = await reconfirmation(request)
		for (const list of [overview.readyIds, overview.pendingIds, overview.noEmail.map((m) => m.mandateId)]) {
			expect(list).not.toContain(ids.dora)
		}
	})

	test('Erinnerung: wer schon einen Link hat, wird nur auf Wunsch erneut angeschrieben, der alte Link verfällt', async ({ page, browser, request }) => {
		// Noch ein Mitglied mit offenem Link, das nicht reagiert hat.
		const erika = await paperMandate(request, { firstName: 'Erika', lastName: 'Nachzügler', email: 'erika.nachzuegler@example.org', iban: 'DE02300209000106531065' })
		await api.raw(request, 'POST', '/mandate-legal-text/reconfirmation/send', { data: { mandateIds: [erika.mandate.id] }, expectOk: true })
		const [oldMail] = await waitForMailsTo('erika.nachzuegler@example.org', { count: 1 })
		const oldUrl = consentUrlFrom(oldMail)

		const state = await reconfirmation(request)
		expect(state.pendingIds).toContain(erika.mandate.id)
		expect(state.readyIds).not.toContain(erika.mandate.id)

		await openSettingsPage(page, USERS.admin)
		await page.getByTestId('reconfirm-open').click()
		const dialog = dialogOf(page)
		await expect(dialog).toBeVisible()
		// Ohne Haken bekommt sie nichts.
		await expect(dialog.getByRole('button', { name: `${state.readyIds.length} Mails verschicken` })).toBeVisible()
		await dialog.getByText(/erneut schreiben, deren früherer Link noch gilt/).click()
		const total = state.readyIds.length + state.pendingIds.length
		const send = dialog.getByRole('button', { name: `${total} Mails verschicken` })
		await send.click()
		await expect(dialog.getByText(`Verschickt: ${total}`)).toBeVisible()
		await closeDialog(dialog)

		// Zweite Mail an Erika, mit einem anderen Link; der erste gilt nicht mehr.
		const mails = (await waitForMailsTo('erika.nachzuegler@example.org', { count: 2 })).filter((m) => m.subject === SUBJECT)
		expect(mails).toHaveLength(2)
		const newUrl = consentUrlFrom(mails[1])
		expect(newUrl).not.toBe(oldUrl)

		const stale = await asVisitor(browser, oldUrl)
		expect(stale.response.status()).toBe(404)
		await expect(stale.page.getByText('Link ungültig')).toBeVisible()
		await stale.context.close()

		const fresh = await asVisitor(browser, newUrl)
		expect(fresh.response.status()).toBe(200)
		await expect(fresh.page.getByRole('button', { name: 'Ich bestätige den aktualisierten Mandatstext' })).toBeVisible()
		await fresh.context.close()
	})

	test('ein bereits bestätigtes Mandat bekommt keine Mail, auch wenn seine ID mitgeschickt wird', async ({ request }) => {
		const mailsBefore = (await waitForMailsTo(MEMBERS.anna.email, { count: 1 })).length

		const result = await (await api.raw(request, 'POST', '/mandate-legal-text/reconfirmation/send', { data: { mandateIds: [ids.anna, ids.dora, 999999] }, expectOk: true })).json()

		expect(result.sent).toBe(0)
		expect(result.alreadyCurrent).toBe(1) // Anna
		expect(result.skipped).toBe(2) // Dora (widerrufen) und die unbekannte ID
		// Es kam keine weitere Mail an.
		await new Promise((resolve) => setTimeout(resolve, 1500))
		expect((await waitForMailsTo(MEMBERS.anna.email, { count: 1 })).length).toBe(mailsBefore)
	})

	// --- Einzelversand aus der Mitglieder-Akte -----------------------------------

	/** Öffnet die Akte eines Mitglieds über „Mandat verwalten“ im Zeilenmenü; liefert den Dialog. */
	async function openAkte(page, name) {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('tr', { hasText: name })
		await row.getByRole('button', { name: 'Aktionen' }).click()
		await page.getByRole('menuitem', { name: 'Mandat verwalten' }).click()
		const dialog = page.getByRole('dialog', { name: `Mitglied: ${name}` })
		await expect(dialog).toBeVisible()
		return dialog
	}

	test('Akte: ein einzelnes Mitglied zur Bestätigung anschreiben, der Stand des Links steht dabei', async ({ page, browser, request }) => {
		test.setTimeout(60000)
		const fritz = await paperMandate(request, { firstName: 'Fritz', lastName: 'Einzeln', email: 'fritz.einzeln@example.org', iban: 'DE02300209000106531065' })

		const dialog = await openAkte(page, 'Fritz Einzeln')
		const row = dialog.locator('.vbh-mandate-card').first()
		await expect(row.locator('dt', { hasText: 'Mandatstext' }).locator('xpath=following-sibling::dd[1]')).toContainText('Neue Fassung noch nicht bestätigt')
		const send = dialog.getByTestId('mandate-reconfirm')
		await expect(send).toHaveText('Zur Bestätigung des Mandatstexts anschreiben')
		await send.click()

		await expect(page.getByText('Link zur Bestätigung des Mandatstexts an fritz.einzeln@example.org verschickt.', { exact: true })).toBeVisible()
		// Der Stand des Links steht in der Zeile, der Knopf bietet jetzt den erneuten Versand an.
		await expect(row.locator('dt', { hasText: 'Mandatstext' }).locator('xpath=following-sibling::dd[1]')).toContainText('Link gesendet am')
		await expect(send).toHaveText('Erneut zur Bestätigung anschreiben')
		// Kommt die Mail nicht an, lässt sich der Link direkt weitergeben (wie beim Einmal-Link).
		const shown = await dialog.locator('.vbh-mandate-link input').inputValue()
		expect(shown).toContain('/mandate-consent/')

		const [mail] = await waitForMailsTo('fritz.einzeln@example.org', { count: 1 })
		expect(mail.subject).toBe(SUBJECT)
		const { page: visitor, context } = await asVisitor(browser, consentUrlFrom(mail))
		await visitor.getByRole('button', { name: 'Ich bestätige den aktualisierten Mandatstext' }).click()
		await expect(visitor.getByText('Bereits bestätigt', { exact: true })).toBeVisible()
		await context.close()

		// Die Akte zeigt danach nichts mehr zu bestätigen.
		const after = await openAkte(page, 'Fritz Einzeln')
		await expect(after.locator('.vbh-mandate-card').first().locator('dt', { hasText: 'Zustimmung' })).toBeVisible()
		await expect(after.locator('dt', { hasText: 'Mandatstext' })).toHaveCount(0)
		await expect(after.getByTestId('mandate-reconfirm')).toHaveCount(0)
		expect((await api.getJson(request, `/mandates/${fritz.mandate.id}`)).mandateTextVersion).toBe(ids.versionAfter)
	})

	test('Akte: ohne Mailadresse ist der Knopf aus, der Revisor darf nicht senden', async ({ page, request }) => {
		const gerda = await paperMandate(request, { firstName: 'Gerda', lastName: 'Stumm', email: undefined, iban: 'DE02500105170137075030' })

		const dialog = await openAkte(page, 'Gerda Stumm')
		await expect(dialog.getByTestId('mandate-reconfirm')).toBeDisabled()

		const asRevisor = await api.raw(request, 'POST', `/mandates/${gerda.mandate.id}/send-reconfirmation-link`, { user: USERS.revisor })
		expect(asRevisor.status()).toBe(403)
		const noMail = await api.raw(request, 'POST', `/mandates/${gerda.mandate.id}/send-reconfirmation-link`)
		expect(noMail.status()).toBe(400)
		// Ein bereits bestätigtes oder beendetes Mandat bekommt ebenfalls keinen Link.
		const ended = await paperMandate(request, { firstName: 'Hans', lastName: 'Beendet', email: 'hans.beendet@example.org', iban: 'DE89370400440532013000' })
		await api.revokeMandate(request, ended.mandate.id)
		const revoked = await api.raw(request, 'POST', `/mandates/${ended.mandate.id}/send-reconfirmation-link`)
		expect(revoked.status()).toBe(400)
	})

	// --- Self-Service: Hinweis und Bestätigung in der angemeldeten Sitzung -----------

	test.describe('Self-Service (Mein Beitrag)', () => {
		test.beforeAll(async ({ request }) => {
			await api.updateSettings(request, { self_service_enabled: '1' })
		})

		test.afterAll(async ({ request }) => {
			const linked = (await api.listMembers(request)).find((m) => m.ncUserId === USERS.ohneRolle)
			if (linked) {
				await api.unlinkMember(request, linked.id)
			}
			await api.updateSettings(request, { self_service_enabled: '0' })
		})

		async function linkMember(request, spec) {
			const existing = (await api.listMembers(request)).find((m) => m.ncUserId === USERS.ohneRolle)
			if (existing) {
				await api.unlinkMember(request, existing.id)
			}
			const { member, mandate } = await paperMandate(request, spec)
			await api.linkMember(request, member.id, USERS.ohneRolle)
			return { member, mandate }
		}

		test('das Mitglied sieht den Hinweis, liest den neuen Text und bestätigt – das Mandat bleibt', async ({ page, request }) => {
			test.setTimeout(60000)
			const { mandate } = await linkMember(request, { firstName: 'Ida', lastName: 'Selbst', email: 'ida.selbst@example.org', iban: 'DE44500105175407324931' })
			const before = await api.getJson(request, `/mandates/${mandate.id}`)

			await openApp(page, USERS.ohneRolle)
			await tabButton(page, 'Mein Beitrag').click()
			const section = visibleSection(page)
			await expect(section.getByTestId('self-reconfirm-hint')).toContainText('Mandatstext aktualisiert')

			await section.getByTestId('self-reconfirm-open').click()
			const dialog = page.getByRole('dialog', { name: 'Mandatstext bestätigen' })
			await expect(dialog).toBeVisible()
			// Der neue Text steht im Dialog – der geladene Rechtstext, nicht der Hinweis darüber.
			await expect(dialog.locator('.vbh-mandate-legaltext')).toContainText(RAHMEN)
			await expect(dialog.locator('.vbh-mandate-legaltext')).not.toContainText('vbh:rahmen')
			// Die IBAN erscheint maskiert, wie überall im Self-Service.
			await expect(dialog.getByText(/DE44•+4931/)).toBeVisible()
			await expect(page.getByText('DE44500105175407324931')).toHaveCount(0)
			await dialog.getByRole('button', { name: 'Ich bestätige den aktualisierten Mandatstext' }).click()

			await expect(page.getByText('Mandatstext bestätigt.', { exact: true })).toBeVisible()
			await expect(section.getByTestId('self-reconfirm-hint')).toHaveCount(0)

			const after = await api.getJson(request, `/mandates/${mandate.id}`)
			expect(after.id).toBe(before.id)
			expect(after.status).toBe('aktiv')
			expect(after.mandateReference).toBe(before.mandateReference)
			expect(after.iban).toBe(before.iban)
			expect(after.signedAt).toBe(before.signedAt)
			expect(after.mandateTextVersion).toBe(ids.versionAfter)
			// Angemeldet bestätigt: das Konto ist der Akteur, nicht eine Mailadresse.
			expect(after.consentActor).toBe(USERS.ohneRolle)
			expect(after.history.some((e) => e.actorType === 'member' && e.message.startsWith('Mandat erneut bestätigt'))).toBe(true)

			// Die Quittungsmail geht an das Mitglied.
			const receipts = (await waitForMailsTo('ida.selbst@example.org', { count: 1 })).filter((m) => m.subject === 'Mandatstext bestätigt')
			expect(receipts).toHaveLength(1)
			expect(receipts[0].text).toContain(before.mandateReference)
		})

		test('wer die neue Fassung schon bestätigt hat, sieht keinen Hinweis; ein zweites Bestätigen wird abgelehnt', async ({ page, request }) => {
			// Ida hat im Test davor bestätigt und ist noch mit dem Konto verknüpft.
			const linked = (await api.listMembers(request)).find((m) => m.ncUserId === USERS.ohneRolle)
			expect(linked?.displayName).toBe('Ida Selbst')

			await openApp(page, USERS.ohneRolle)
			await tabButton(page, 'Mein Beitrag').click()
			await expect(visibleSection(page).getByText('Mein SEPA-Lastschriftmandat')).toBeVisible()
			await expect(visibleSection(page).getByTestId('self-reconfirm-hint')).toHaveCount(0)
			await expect(visibleSection(page).getByTestId('self-reconfirm-open')).toHaveCount(0)

			const again = await api.raw(request, 'POST', '/self/mandate/reconfirm', { user: USERS.ohneRolle })
			expect(again.status()).toBe(400)
		})

		test('ohne Verknüpfung mit einem Mitglied gibt es 403, und ein Entwurf wird nicht auf diesem Weg bestätigt', async ({ request }) => {
			const existing = (await api.listMembers(request)).find((m) => m.ncUserId === USERS.ohneRolle)
			await api.unlinkMember(request, existing.id)
			const unlinked = await api.raw(request, 'POST', '/self/mandate/reconfirm', { user: USERS.ohneRolle })
			expect(unlinked.status()).toBe(403)

			const member = await api.createMember(request, { firstName: 'Jana', lastName: 'Entwurf', email: 'jana.entwurf@example.org' })
			await api.createElectronicMandate(request, { memberId: member.id, iban: 'DE02120300000000202051' })
			await api.linkMember(request, member.id, USERS.ohneRolle)
			const draft = await api.raw(request, 'POST', '/self/mandate/reconfirm', { user: USERS.ohneRolle })
			expect(draft.status()).toBe(400)
		})
	})
})
