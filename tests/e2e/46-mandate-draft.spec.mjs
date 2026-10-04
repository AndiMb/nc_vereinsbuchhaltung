import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { api, openApp, switchTab, tabButton, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Entwurf-Mandat verwerfen oder korrigieren (Issue #118, Spec §2.2/§3.4): ein
// Mandat im Zustand „Entwurf“ hatte keinen Ausweg – Widerruf und Amendment
// setzen ein aktives Mandat voraus, und „höchstens ein lebendes Mandat je
// Mitglied“ ließ einen Entwurf mit Tippfehler in der IBAN für immer stehen.
// Diese Spec deckt den Ablauf ab: in der Akte korrigieren (ohne Amendment,
// jede Änderung im Verlauf, ein ausgesendeter Einmal-Link wird ungültig) und
// verwerfen (Pflicht-Notiz, Endgrund „verworfen“, danach ein neues Mandat),
// die Rollenlinie (nur Buchhalter; im Self-Service darf das Mitglied den
// EIGENEN Entwurf nur verwerfen, nie korrigieren, und nie eine fremde ID
// ansteuern) und den Ausweg-Hinweis im Widerrufsdialog bei einem ausgesetzten
// Mandat.
//
// Jeder Test legt sein eigenes Mitglied an und stellt seine Vorbedingungen
// selbst her (nach einem Fehlschlag läuft beforeAll erneut, /reset räumt die
// Mitglieder-Tabellen aber nicht ab).
//
// Der Testserver hat keinen Mailserver: für den Einmal-Link-Versand setzt die
// Spec den NC-Mail-Modus „null“ (Mails werden angenommen und verworfen) und
// entfernt ihn danach wieder – dasselbe Muster wie 28-contribution-prenotification.
// Die Link-Adresse steht ohnehin in der Antwort des Versands (siehe
// MandateController::sendActivationLink()), ein Mail-Catcher ist nicht nötig.

const IBAN = 'DE12500105170648489890'
const IBAN_GROUPED = 'DE12 5001 0517 0648 4898 90'
/** Formal gültig, aber „verschrieben“ – das Muster des Tippfehlers, um den es im Issue geht. */
const IBAN_TYPO = 'DE12500105170648489899'
const IBAN_TYPO_GROUPED = 'DE12 5001 0517 0648 4898 99'
const IBAN_NEW = 'DE89370400440532013000'

const today = () => new Date().toISOString().slice(0, 10)

async function enableMembership(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

/** Legt ein Mitglied mit eindeutigem Nachnamen an (siehe 39-mandate-management.spec.mjs: /reset räumt Mitglieder nicht ab). */
async function createMember(request, firstName, lastName) {
	const unique = `${lastName}-${Math.random().toString(36).slice(2, 6)}`
	return api.createMember(request, { firstName, lastName: unique, email: `${firstName}.${unique}@example.org`.toLowerCase() })
}

/** Papier-Entwurf über die API: ohne Unterschriftsdatum bleibt das Mandat ein Entwurf. */
async function createPaperDraft(request, member, { iban = IBAN } = {}) {
	return (await api.createMandate(request, { memberId: member.id, iban })).json()
}

/** Elektronischer Entwurf; mit `withLink` geht der Einmal-Link gleich raus, die Antwort liefert die volle Adresse. */
async function createElectronicDraft(request, member, { iban = IBAN, withLink = false } = {}) {
	const mandate = await (await api.createElectronicMandate(request, { memberId: member.id, iban })).json()
	if (!withLink) { return { mandate, activationUrl: null } }
	const sent = await (await api.sendActivationLink(request, mandate.id)).json()
	return { mandate, activationUrl: sent.activationUrl }
}

/** Aktives Papier-Mandat (Unterschriftsdatum → sofort aktiv). */
async function createActiveMandate(request, member, { iban = IBAN } = {}) {
	const mandate = await (await api.createMandate(request, { memberId: member.id, iban, signedAt: today() })).json()
	await api.activateMandate(request, mandate.id)
	return mandate
}

/** Öffnet die Akte eines Mitglieds über „Mandat verwalten“ im Zeilenmenü; liefert den Dialog. */
async function openMandate(page, name) {
	await openApp(page, USERS.buchhalter)
	await switchTab(page, 'Beiträge')
	const row = visibleSection(page).locator('tr', { hasText: name })
	await row.getByRole('button', { name: 'Aktionen' }).click()
	await page.getByRole('menuitem', { name: 'Mandat verwalten' }).click()
	const dialog = page.getByRole('dialog', { name: `Mitglied: ${name}` })
	await expect(dialog).toBeVisible()
	return dialog
}

const panel = (dialog) => dialog.locator('section.vbh-mandate-panel')
const status = (dialog) => panel(dialog).locator('.vbh-mandate-status')

/** Der Wert zu einer Bezeichnung der Definitionsliste des lebenden Mandats (beendete stehen in `details`). */
function field(dialog, label) {
	return panel(dialog).locator('.vbh-mandate-card').first().locator('dt', { hasText: label }).locator('xpath=following-sibling::dd[1]')
}

/**
 * Erfolgs-Toast (showSuccess). Der Toast steht doppelt im DOM: als sichtbares
 * Toast-Element und als Bildschirmleser-Ansage „Erfolg: <Text>“ in einer
 * Live-Region – ein Teilstring-Locator träfe beide (strict mode violation),
 * deshalb der exakte Text.
 */
function successToast(page, text) {
	return page.getByText(text, { exact: true })
}

test.describe('Entwurf-Mandat korrigieren oder verwerfen (Akte)', () => {
	test.beforeAll(async ({ request }) => {
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test.afterAll(async () => {
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	test('Papier-Entwurf: IBAN-Tippfehler korrigieren – ohne Amendment, im Verlauf, danach normal aktivierbar', async ({ page, request }) => {
		const member = await createMember(request, 'Paula', 'Papierkorrektur')
		const draft = await createPaperDraft(request, member, { iban: IBAN_TYPO })
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)

		await expect(status(dialog)).toHaveText('Entwurf')
		await expect(field(dialog, 'IBAN')).toHaveText(IBAN_TYPO_GROUPED)
		// Aus dem Entwurf gibt es zwei Wege hinaus, die es vorher nicht gab.
		await expect(p.getByRole('button', { name: 'Entwurf korrigieren' })).toBeVisible()
		await expect(p.getByRole('button', { name: 'Entwurf verwerfen' })).toBeVisible()

		await p.getByRole('button', { name: 'Entwurf korrigieren' }).click()
		const correct = page.getByRole('dialog', { name: 'Entwurf korrigieren' })
		await expect(correct).toBeVisible()
		// Vorbelegt mit den gespeicherten Werten; ohne Änderung lässt sich nichts speichern.
		await expect(correct.getByLabel('IBAN', { exact: true })).toHaveValue(IBAN_TYPO_GROUPED)
		await expect(correct.getByLabel('Kontoinhaber')).toHaveValue(member.displayName)
		await expect(correct.getByRole('button', { name: 'Entwurf korrigieren' })).toBeDisabled()
		// Papier: der Hinweis, dass die Angaben zum unterschriebenen Formular passen müssen (kein Link-Hinweis).
		await expect(correct).toContainText('zum unterschriebenen Formular passen')
		await expect(correct).not.toContainText('Einmal-Link')

		await correct.getByLabel('IBAN', { exact: true }).fill(IBAN)
		await expect(correct.getByRole('button', { name: 'Entwurf korrigieren' })).toBeEnabled()
		await correct.getByRole('button', { name: 'Entwurf korrigieren' }).click()
		await expect(correct).toBeHidden()

		await expect(successToast(page, 'Entwurf korrigiert.')).toBeVisible()
		await expect(field(dialog, 'IBAN')).toHaveText(IBAN_GROUPED)
		await expect(status(dialog)).toHaveText('Entwurf') // die Korrektur ändert den Zustand nicht

		// Der Verlauf hält die Änderung fest – die IBAN nur maskiert, den Namen gar nicht.
		await p.locator('details.vbh-mandate-history summary').click()
		const history = p.locator('details.vbh-mandate-history')
		await expect(history).toContainText('Entwurf korrigiert: IBAN DE12')
		await expect(history).toContainText(`Verein (${USERS.buchhalter})`)
		await expect(history).not.toContainText(IBAN)
		await expect(history).not.toContainText(IBAN_TYPO)

		// Direkt am Entwurf geändert, kein Amendment, dasselbe Mandat.
		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].id).toBe(draft.id)
		expect(mandates[0].iban).toBe(IBAN)
		expect(mandates[0].status).toBe('entwurf')
		const detail = await api.getJson(request, `/mandates/${draft.id}`)
		expect(detail.amendments).toHaveLength(0)

		// Der korrigierte Entwurf ist ein ganz normaler Entwurf: Unterschriftsdatum, aktivieren.
		await p.getByRole('button', { name: 'Aktivieren', exact: true }).click()
		await p.getByLabel('Mandat unterschrieben am').fill(today())
		await p.getByRole('button', { name: 'Mandat aktivieren' }).click()
		await expect(status(dialog)).toHaveText('Aktiv')
		await expect(field(dialog, 'IBAN')).toHaveText(IBAN_GROUPED)
	})

	test('Elektronischer Entwurf: die Korrektur macht den ausgesendeten Einmal-Link ungültig, ein neuer funktioniert', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Elias', 'Einmallinkkorrektur')
		const { mandate, activationUrl } = await createElectronicDraft(request, member, { iban: IBAN_TYPO, withLink: true })

		// Vor der Korrektur ist die Zustimmungsseite des Links erreichbar.
		const before = await page.goto(activationUrl)
		expect(before.status()).toBe(200)

		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)
		await expect(status(dialog)).toHaveText('Entwurf')
		await expect(field(dialog, 'Einmal-Link')).toContainText('gesendet am')

		await p.getByRole('button', { name: 'Entwurf korrigieren' }).click()
		const correct = page.getByRole('dialog', { name: 'Entwurf korrigieren' })
		await expect(correct).toBeVisible()
		// Der Dialog warnt vor der Folge (und nennt hier nicht das Papierformular).
		await expect(correct).toContainText('Der bereits verschickte Einmal-Link wird mit der Korrektur ungültig')
		await expect(correct).not.toContainText('unterschriebenen Formular')
		await correct.getByLabel('IBAN', { exact: true }).fill(IBAN)
		await correct.getByRole('button', { name: 'Entwurf korrigieren' }).click()
		await expect(correct).toBeHidden()

		await expect(successToast(page, 'Entwurf korrigiert. Der bisherige Einmal-Link ist ungültig – senden Sie dem Mitglied einen neuen.')).toBeVisible()
		await expect(field(dialog, 'Einmal-Link')).toHaveText('noch nicht versendet')
		await expect(p.getByRole('button', { name: 'Einmal-Link senden' })).toBeVisible()
		await expect(p.getByLabel('Einmal-Link', { exact: true })).toHaveCount(0) // die zuletzt gezeigte Adresse gilt nicht mehr

		// Der alte Link ist tot – weder ansehen noch zustimmen, der Entwurf bleibt ein Entwurf.
		const oldView = await page.request.get(activationUrl)
		expect(oldView.status()).toBe(404)
		expect(await oldView.text()).toContain('ungültig')
		const oldConsent = await page.request.post(activationUrl)
		expect(oldConsent.status()).toBe(404)
		const afterAttempt = await api.getJson(request, `/mandates/${mandate.id}`)
		expect(afterAttempt.status).toBe('entwurf')
		expect(afterAttempt.consentAt).toBeNull()
		expect(afterAttempt.iban).toBe(IBAN)
		expect(afterAttempt.activationLink).toBeNull()

		// Ein neuer Link funktioniert; der alte bleibt ungültig.
		await p.getByRole('button', { name: 'Einmal-Link senden' }).click()
		const fresh = p.getByLabel('Einmal-Link', { exact: true })
		await expect(fresh).toHaveValue(/\/mandate-consent\//)
		const freshUrl = await fresh.inputValue()
		expect(freshUrl).not.toBe(activationUrl)
		expect((await page.request.get(freshUrl)).status()).toBe(200)
		expect((await page.request.get(activationUrl)).status()).toBe(404)
	})

	test('Papier-Entwurf verwerfen: Pflicht-Notiz, Endgrund „verworfen“ im Verlauf, danach ein neues Mandat', async ({ page, request }) => {
		const member = await createMember(request, 'Vera', 'Verwerfen')
		const draft = await createPaperDraft(request, member, { iban: IBAN_TYPO })
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)

		await p.getByRole('button', { name: 'Entwurf verwerfen' }).click()
		const discard = page.getByRole('dialog', { name: 'Mandats-Entwurf verwerfen' })
		await expect(discard).toBeVisible()
		// Kein Widerruf: der Entwurf war nie wirksam.
		await expect(discard).toContainText('Eingezogen wurde über ihn nie etwas')
		await expect(discard.getByRole('button', { name: 'Entwurf stattdessen korrigieren' })).toBeVisible()
		// Die Notiz ist Pflicht – leer und nur Leerzeichen lassen den Knopf gesperrt.
		const confirm = discard.getByRole('button', { name: 'Entwurf endgültig verwerfen' })
		await expect(confirm).toBeDisabled()
		await discard.getByLabel('Grund des Verwerfens (Pflicht)').fill('   ')
		await expect(confirm).toBeDisabled()
		await discard.getByLabel('Grund des Verwerfens (Pflicht)').fill('Tippfehler, Mitglied meldet sich neu')
		await expect(confirm).toBeEnabled()
		await confirm.click()
		await expect(discard).toBeHidden()

		await expect(successToast(page, 'Entwurf verworfen.')).toBeVisible()
		// Kein lebendes Mandat mehr: Störfall „neues Mandat einholen“, der Entwurf steht unter „Frühere Mandate“.
		await expect(p).toContainText('neues Mandat einholen')
		const past = p.locator('details.vbh-mandate-past')
		await expect(past).toHaveCount(1)
		await expect(past.locator('summary')).toContainText('Entwurf verworfen')
		await past.locator('summary').click()
		await expect(past).toContainText('Entwurf verworfen: Tippfehler, Mitglied meldet sich neu')
		await expect(past).toContainText(`Verein (${USERS.buchhalter})`)

		const [ended] = await api.mandatesByMember(request, member.id)
		expect(ended.id).toBe(draft.id)
		expect(ended.status).toBe('erloschen')
		expect(ended.endReason).toBe('verworfen')
		expect(ended.endedAt).toBeTruthy()

		// Danach ist ein neues Mandat möglich – der verworfene Entwurf blockiert es nicht mehr.
		await p.getByRole('button', { name: 'Mandat anlegen' }).click()
		await p.getByLabel('IBAN', { exact: true }).fill(IBAN)
		await p.getByRole('button', { name: 'Mandat anlegen' }).click()
		await expect(status(dialog)).toHaveText('Entwurf')
		await expect(field(dialog, 'IBAN')).toHaveText(IBAN_GROUPED)
		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(2)
		expect(mandates[0].status).toBe('entwurf') // neueste zuerst
		expect(mandates[1].endReason).toBe('verworfen')
	})

	test('Elektronischen Entwurf verwerfen: der Dialog nennt den Einmal-Link, der Link ist danach ungültig', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Emil', 'Elektroverwerfen')
		const { mandate, activationUrl } = await createElectronicDraft(request, member, { withLink: true })
		expect((await page.goto(activationUrl)).status()).toBe(200)

		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)
		await p.getByRole('button', { name: 'Entwurf verwerfen' }).click()
		const discard = page.getByRole('dialog', { name: 'Mandats-Entwurf verwerfen' })
		await expect(discard).toContainText('Ein bereits verschickter Einmal-Link wird ungültig')
		await discard.getByLabel('Grund des Verwerfens (Pflicht)').fill('Mitglied will kein Lastschriftmandat')
		await discard.getByRole('button', { name: 'Entwurf endgültig verwerfen' }).click()
		await expect(successToast(page, 'Entwurf verworfen.')).toBeVisible()

		const [ended] = await api.mandatesByMember(request, member.id)
		expect(ended.id).toBe(mandate.id)
		expect(ended.endReason).toBe('verworfen')
		// Weder ansehen noch zustimmen: die Zustimmungsseite zeigt zu einem beendeten Mandat nichts mehr.
		expect((await page.request.get(activationUrl)).status()).toBe(404)
		expect((await page.request.post(activationUrl)).status()).toBe(404)
		expect((await api.getJson(request, `/mandates/${mandate.id}`)).status).toBe('erloschen')
	})

	test('Der Ausweg im Verwerfen-Dialog führt zur Korrektur, ohne etwas zu verwerfen', async ({ page, request }) => {
		const member = await createMember(request, 'Anke', 'Ausweg')
		await createPaperDraft(request, member, { iban: IBAN_TYPO })
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)

		await p.getByRole('button', { name: 'Entwurf verwerfen' }).click()
		const discard = page.getByRole('dialog', { name: 'Mandats-Entwurf verwerfen' })
		await discard.getByRole('button', { name: 'Entwurf stattdessen korrigieren' }).click()
		const correct = page.getByRole('dialog', { name: 'Entwurf korrigieren' })
		await expect(correct).toBeVisible()
		await expect(discard).toBeHidden()
		await correct.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(correct).toBeHidden()

		// Nichts verworfen, nichts geändert.
		await expect(status(dialog)).toHaveText('Entwurf')
		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].status).toBe('entwurf')
		expect(mandates[0].iban).toBe(IBAN_TYPO)
	})

	test('Aktive und ausgesetzte Mandate bieten keine Entwurf-Aktionen an', async ({ page, request }) => {
		const member = await createMember(request, 'Anton', 'Aktivohneentwurf')
		await createActiveMandate(request, member)
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)

		await expect(status(dialog)).toHaveText('Aktiv')
		await expect(p.getByRole('button', { name: 'Mandat widerrufen' })).toBeVisible()
		await expect(p.getByRole('button', { name: 'Entwurf korrigieren' })).toHaveCount(0)
		await expect(p.getByRole('button', { name: 'Entwurf verwerfen' })).toHaveCount(0)
	})

	test('Backend-Regeln: Korrektur und Verwerfen gelten nur für Entwürfe, mit gültigen Angaben und Pflicht-Notiz', async ({ request }) => {
		const member = await createMember(request, 'Bernd', 'Backendregeln')
		const draft = await createPaperDraft(request, member)
		const rejected = async (promise) => (await promise).status()

		// Korrektur: ungültige IBAN, leerer Kontoinhaber, unverändert – jeweils 400, der Entwurf bleibt wie er war.
		expect(await rejected(api.correctMandateDraft(request, draft.id, { iban: 'kaputt', accountHolder: draft.accountHolder, expectOk: false }))).toBe(400)
		expect(await rejected(api.correctMandateDraft(request, draft.id, { iban: IBAN_NEW, accountHolder: '   ', expectOk: false }))).toBe(400)
		expect(await rejected(api.correctMandateDraft(request, draft.id, { iban: IBAN, bic: draft.bic, accountHolder: draft.accountHolder, expectOk: false }))).toBe(400)
		expect((await api.getJson(request, `/mandates/${draft.id}`)).iban).toBe(IBAN)

		// Verwerfen ohne Notiz: 400, das Mandat bleibt ein Entwurf.
		expect(await rejected(api.discardMandateDraft(request, draft.id, { note: '', expectOk: false }))).toBe(400)
		expect(await rejected(api.discardMandateDraft(request, draft.id, { note: '  \n ', expectOk: false }))).toBe(400)
		expect((await api.getJson(request, `/mandates/${draft.id}`)).status).toBe('entwurf')

		// Eine echte Korrektur klappt und verändert den Zustand nicht.
		const corrected = await (await api.correctMandateDraft(request, draft.id, { iban: IBAN_NEW, bic: 'cobadeffxxx', accountHolder: ' Bernd Neu ' })).json()
		expect(corrected.iban).toBe(IBAN_NEW)
		expect(corrected.bic).toBe('COBADEFFXXX')
		expect(corrected.accountHolder).toBe('Bernd Neu')
		expect(corrected.status).toBe('entwurf')

		// Ist das Mandat aktiv, gilt wieder das Amendment: Korrektur und Verwerfen werden abgewiesen.
		await api.activateMandate(request, draft.id, { signedAt: today() })
		expect(await rejected(api.correctMandateDraft(request, draft.id, { iban: IBAN, accountHolder: 'Bernd Neu', expectOk: false }))).toBe(400)
		expect(await rejected(api.discardMandateDraft(request, draft.id, { note: 'zu spät', expectOk: false }))).toBe(400)
		await api.suspendMandate(request, draft.id, { note: 'Rückfrage' })
		expect(await rejected(api.discardMandateDraft(request, draft.id, { note: 'auch gesperrt nicht', expectOk: false }))).toBe(400)
		const [unchanged] = await api.mandatesByMember(request, member.id)
		expect(unchanged.status).toBe('ausgesetzt')
		expect(unchanged.iban).toBe(IBAN_NEW)

		// Ein verworfener Entwurf ist erloschen: ein zweites Verwerfen geht nicht, ein neues Mandat dagegen schon.
		const other = await createMember(request, 'Berta', 'Backendregeln')
		const second = await createPaperDraft(request, other)
		const discarded = await (await api.discardMandateDraft(request, second.id, { note: 'Doppelt angelegt' })).json()
		expect(discarded.status).toBe('erloschen')
		expect(discarded.endReason).toBe('verworfen')
		expect(await rejected(api.discardMandateDraft(request, second.id, { note: 'noch mal', expectOk: false }))).toBe(400)
		const replacement = await api.createMandate(request, { memberId: other.id, iban: IBAN })
		expect(replacement.status()).toBe(201)
	})

	test('Rollen: nur Buchhalter korrigieren und verwerfen – Revisor und Konto ohne Rolle bekommen 403', async ({ request }) => {
		const member = await createMember(request, 'Rosa', 'Rollenprobe')
		const draft = await createPaperDraft(request, member, { iban: IBAN_TYPO })

		for (const user of [USERS.revisor, USERS.ohneRolle]) {
			const correct = await api.correctMandateDraft(request, draft.id, { iban: IBAN, accountHolder: draft.accountHolder, user, expectOk: false })
			expect(correct.status(), `Korrektur als ${user}`).toBe(403)
			const discard = await api.discardMandateDraft(request, draft.id, { note: 'unbefugt', user, expectOk: false })
			expect(discard.status(), `Verwerfen als ${user}`).toBe(403)
		}
		const [unchanged] = await api.mandatesByMember(request, member.id)
		expect(unchanged.status).toBe('entwurf')
		expect(unchanged.iban).toBe(IBAN_TYPO)

		// Der Buchhalter darf beides.
		const corrected = await api.correctMandateDraft(request, draft.id, { iban: IBAN, accountHolder: draft.accountHolder, user: USERS.buchhalter })
		expect((await corrected.json()).iban).toBe(IBAN)
		const discarded = await api.discardMandateDraft(request, draft.id, { note: 'Rollenprobe', user: USERS.buchhalter })
		expect((await discarded.json()).endReason).toBe('verworfen')
	})
})

test.describe('Entwurf-Mandat im Self-Service (Mein Beitrag)', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.updateSettings(request, { self_service_enabled: '1' })
	})

	test.afterAll(async ({ request }) => {
		const members = await api.listMembers(request)
		const linked = members.find((m) => m.ncUserId === USERS.ohneRolle)
		if (linked) {
			await api.unlinkMember(request, linked.id)
		}
		await api.updateSettings(request, { self_service_enabled: '0' })
	})

	/** Löst eine evtl. bestehende Verknüpfung von USERS.ohneRolle und verknüpft stattdessen ein frisches Mitglied. */
	async function linkFreshMember(request, firstName, lastName) {
		const members = await api.listMembers(request)
		const existing = members.find((m) => m.ncUserId === USERS.ohneRolle)
		if (existing) {
			await api.unlinkMember(request, existing.id)
		}
		const created = await createMember(request, firstName, lastName)
		await api.linkMember(request, created.id, USERS.ohneRolle)
		return created
	}

	test('Das Mitglied verwirft seinen eigenen Entwurf – korrigieren kann es ihn nicht', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await linkFreshMember(request, 'Mira', 'Mitglied')
		const { mandate } = await createElectronicDraft(request, member, { iban: IBAN_TYPO })

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Beitrag').click()
		const section = visibleSection(page)
		await expect(section.getByRole('button', { name: 'Jetzt bestätigen' })).toBeVisible()
		await expect(section.getByRole('button', { name: 'Entwurf verwerfen' })).toBeVisible()
		// Korrigieren ist Sache der Verwaltung (Spec §3.4: kein Teil des Aktionskatalogs).
		await expect(section.getByRole('button', { name: 'Entwurf korrigieren' })).toHaveCount(0)

		await section.getByRole('button', { name: 'Entwurf verwerfen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Mandats-Entwurf verwerfen' })
		await expect(dialog).toBeVisible()
		// Für das Mitglied weder Pflicht-Notiz noch Korrektur-Ausweg, dafür der Hinweis aufs Neuerteilen.
		await expect(dialog.locator('textarea')).toHaveCount(0)
		await expect(dialog.getByRole('button', { name: 'Entwurf stattdessen korrigieren' })).toHaveCount(0)
		await expect(dialog).toContainText('erteilen Sie das Mandat danach neu')
		await dialog.getByRole('button', { name: 'Entwurf endgültig verwerfen' }).click()

		await expect(successToast(page, 'Entwurf verworfen.')).toBeVisible()
		await expect(section.getByText('Kein Mandat hinterlegt.')).toBeVisible()
		await expect(section.getByRole('button', { name: 'Mandat jetzt erteilen' })).toBeVisible()

		// Die Verwaltung sieht es: erloschen/verworfen, in der Historie vom Kanal „Mitglied“.
		const [ended] = await api.mandatesByMember(request, member.id)
		expect(ended.id).toBe(mandate.id)
		expect(ended.status).toBe('erloschen')
		expect(ended.endReason).toBe('verworfen')
		const detail = await api.getJson(request, `/mandates/${mandate.id}`)
		expect(detail.history[0].actorType).toBe('member')
		expect(detail.history[0].message).toContain('Entwurf verworfen')
	})

	test('Auch ein Papier-Entwurf lässt sich im Self-Service verwerfen – ein neues Mandat ist danach möglich', async ({ page, request }) => {
		const member = await linkFreshMember(request, 'Paul', 'Papiermitglied')
		await createPaperDraft(request, member)

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Beitrag').click()
		const section = visibleSection(page)
		await expect(section.getByRole('button', { name: 'Jetzt bestätigen' })).toHaveCount(0) // kein elektronischer Entwurf
		await section.getByRole('button', { name: 'Entwurf verwerfen' }).click()
		await page.getByRole('dialog', { name: 'Mandats-Entwurf verwerfen' }).getByRole('button', { name: 'Entwurf endgültig verwerfen' }).click()
		await expect(successToast(page, 'Entwurf verworfen.')).toBeVisible()
		await expect(section.getByText('Kein Mandat hinterlegt.')).toBeVisible()

		// Das Mitglied erteilt direkt ein neues Mandat, nichts steht mehr im Weg.
		const granted = await api.raw(request, 'POST', '/self/mandate', { user: USERS.ohneRolle, data: { iban: IBAN, accountHolder: member.displayName } })
		expect(granted.status()).toBe(201)
		expect((await granted.json()).status).toBe('aktiv')
	})

	test('Der Endpunkt nimmt keine fremde ID an: es trifft nur den eigenen Entwurf, nie ein aktives oder fremdes Mandat', async ({ request }) => {
		const own = await linkFreshMember(request, 'Olga', 'Eigenes')
		const foreign = await createMember(request, 'Fritz', 'Fremdes')
		const ownDraft = await createPaperDraft(request, own)
		const foreignDraft = await createPaperDraft(request, foreign)

		// Mit fremder member_id und fremder Mandats-ID im Body – der Endpunkt liest keine davon.
		const first = await api.selfDiscardMandateDraft(request, { user: USERS.ohneRolle, data: { memberId: foreign.id, mandateId: foreignDraft.id, id: foreignDraft.id } })
		const discarded = await first.json()
		expect(discarded.id).toBe(ownDraft.id)
		expect(discarded.endReason).toBe('verworfen')
		expect((await api.mandatesByMember(request, foreign.id))[0].status).toBe('entwurf')

		// Jetzt hat das Mitglied kein lebendes Mandat mehr – derselbe Aufruf trifft nichts und erst recht kein fremdes.
		const second = await api.selfDiscardMandateDraft(request, { user: USERS.ohneRolle, data: { memberId: foreign.id, mandateId: foreignDraft.id }, expectOk: false })
		expect(second.status()).toBe(400)
		expect((await api.mandatesByMember(request, foreign.id))[0].status).toBe('entwurf')

		// Ein AKTIVES Mandat verwirft man nicht – es wird widerrufen.
		await createActiveMandate(request, own, { iban: IBAN_NEW })
		const third = await api.selfDiscardMandateDraft(request, { user: USERS.ohneRolle, expectOk: false })
		expect(third.status()).toBe(400)
		const [live] = await api.mandatesByMember(request, own.id)
		expect(live.status).toBe('aktiv')
	})

	test('Ohne Self-Service-Zugang (kein verknüpftes Mitglied) gibt es 403', async ({ request }) => {
		// USERS.revisor hat eine App-Rolle, aber keine Mitglieder-Verknüpfung – der Self-Service-Kanal braucht die Verknüpfung, keine Rolle.
		const resp = await api.selfDiscardMandateDraft(request, { user: USERS.revisor, expectOk: false })
		expect(resp.status()).toBe(403)
	})

	test('Ausgesetztes Mandat: der Widerrufsdialog bietet „IBAN ändern“ nicht an, bei einem aktiven schon', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await linkFreshMember(request, 'Sandra', 'Ausgesetzt')
		const mandate = await createActiveMandate(request, member)
		await api.suspendMandate(request, mandate.id, { note: 'Rückfrage beim Mitglied' })

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Beitrag').click()
		let section = visibleSection(page)
		// Es gibt für ein ausgesetztes Mandat keine IBAN-Änderung, nur den Widerruf.
		await expect(section.getByRole('button', { name: 'Bankverbindung ändern' })).toHaveCount(0)
		await section.getByRole('button', { name: 'Mandat widerrufen' }).click()
		let dialog = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await expect(dialog).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' })).toHaveCount(0)
		await expect(dialog).toContainText('wenden Sie sich an Ihren Verein')
		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(dialog).toBeHidden()

		// Nach dem Entsperren ist das Mandat aktiv, der Ausweg steht wieder im Dialog.
		await api.resumeMandate(request, mandate.id)
		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Beitrag').click()
		section = visibleSection(page)
		await section.getByRole('button', { name: 'Mandat widerrufen' }).click()
		dialog = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await expect(dialog.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' })).toBeVisible()
		await expect(dialog).not.toContainText('wenden Sie sich an Ihren Verein')
	})
})

test.describe('Entwurf-Mandat auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test.beforeAll(async ({ request }) => {
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test.afterAll(async () => {
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	test('Entwurf-Aktionen und beide Dialoge passen auf den Bildschirm, nichts läuft über den Rand', async ({ page, request }) => {
		const member = await createMember(request, 'Hanna', 'Handyentwurf')
		await createElectronicDraft(request, member, { withLink: true })

		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()
		const card = visibleSection(page).locator('.vbh-membercard', { hasText: member.displayName })
		await card.getByRole('button', { name: 'Aktionen' }).click()
		await page.getByRole('menuitem', { name: 'Mandat verwalten' }).click()
		const dialog = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
		await expect(status(dialog)).toHaveText('Entwurf')

		const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
		const p = panel(dialog)
		await expect(p.getByRole('button', { name: 'Entwurf korrigieren' })).toBeVisible()
		await expect(p.getByRole('button', { name: 'Entwurf verwerfen' })).toBeVisible()
		expect(await overflow()).toBeLessThanOrEqual(0)

		await p.getByRole('button', { name: 'Entwurf korrigieren' }).click()
		const correct = page.getByRole('dialog', { name: 'Entwurf korrigieren' })
		await expect(correct.getByLabel('IBAN', { exact: true })).toBeVisible()
		await expect(correct.getByRole('button', { name: 'Entwurf korrigieren' })).toBeVisible()
		expect(await overflow()).toBeLessThanOrEqual(0)
		await correct.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(correct).toBeHidden()

		await p.getByRole('button', { name: 'Entwurf verwerfen' }).click()
		const discard = page.getByRole('dialog', { name: 'Mandats-Entwurf verwerfen' })
		await expect(discard.getByLabel('Grund des Verwerfens (Pflicht)')).toBeVisible()
		await expect(discard.getByRole('button', { name: 'Entwurf endgültig verwerfen' })).toBeVisible()
		expect(await overflow()).toBeLessThanOrEqual(0)
	})
})
