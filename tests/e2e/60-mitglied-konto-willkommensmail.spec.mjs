import { test, expect } from '@playwright/test'
import { api, authHeaders, BASE_URL, openApp, openSettingsPage, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'
import { capturedMails, clearCapturedMails, startMailCapture, stopMailCapture } from './fixtures/mail-capture.mjs'

// Nextcloud-Konto und Willkommens-Mail rund um die Mitgliederliste:
//   - Spalte „Nextcloud-Konto?" (Ja / Vorschlag zum Verknüpfen mit Rückfrage / Nein)
//   - „Mitglied aufnehmen" legt auf Wunsch ein Nextcloud-Konto an (über die OCS-Schnittstelle
//     von Nextcloud) und verknüpft es, und schickt eine Willkommens-Mail
//   - dieselben Wege aus der Akte, die Vorlage in den Einstellungen
//
// Konten legt nur ein Nextcloud-Administrator an (Benutzerverwaltung) – die Spec läuft dafür als admin.
// Alle Mails gehen an einen sendmail-Ersatz im Container (fixtures/mail-capture.mjs): geprüft wird, WAS
// verschickt wurde.

const CLUB_NAME = 'Testverein e.V.'
const PASSWORD = 'E2e-Passwort-12345!'

/** Konten, die diese Spec anlegt; sie räumt sie am Ende ab (Datenbank-Snapshots enthalten sie sonst für alle). */
const CREATED_USERS = ['mona.konto', 'e2e.nachholer', 'e2e.verknuepft']

const ocs = (path) => `${BASE_URL}/ocs/v2.php/cloud/users${path}?format=json`

async function createUser(request, uid) {
	const resp = await request.fetch(ocs(''), { method: 'POST', headers: authHeaders('admin'), data: { userid: uid, password: PASSWORD } })
	if (!resp.ok()) {
		throw new Error(`Konto ${uid} anlegen fehlgeschlagen: HTTP ${resp.status()} – ${(await resp.text()).slice(0, 300)}`)
	}
}

async function deleteUser(request, uid) {
	await request.fetch(ocs(`/${uid}`), { method: 'DELETE', headers: authHeaders('admin') })
}

async function userInfo(request, uid) {
	const resp = await request.fetch(ocs(`/${uid}`), { headers: authHeaders('admin') })
	return resp.ok() ? (await resp.json()).ocs.data : null
}

/**
 * Wartet auf die Mail mit diesem Betreff an den Empfänger. Neben der Willkommens-Mail des Vereins schickt
 * Nextcloud beim Anlegen eines Kontos eine eigene Einladung an dieselbe Adresse – es zählt der Betreff.
 */
async function waitForSubject(recipient, subject, timeoutMs = 20000) {
	const deadline = Date.now() + timeoutMs
	let seen = []
	while (Date.now() < deadline) {
		seen = (await capturedMails()).filter((m) => m.to.includes(recipient))
		const hit = seen.find((m) => m.subject === subject)
		if (hit) {
			return hit
		}
		await new Promise((resolve) => setTimeout(resolve, 500))
	}
	throw new Error(`Keine Mail „${subject}“ an ${recipient} abgegriffen; gefunden: ${seen.map((m) => m.subject).join(' | ') || 'keine'}`)
}

async function ensureMember(request, firstName, lastName, email) {
	const displayName = `${firstName} ${lastName}`
	const existing = (await api.listMembers(request)).find((m) => m.displayName === displayName)
	return existing ?? api.createMember(request, { firstName, lastName, email })
}

/** Zeile in der sichtbaren Mitglieder-Tabelle (nicht in der versteckten Zuweisungs-Tabelle der Beitragsgruppen). */
function memberRow(page, name) {
	return visibleSection(page).locator('table.vbh-table:visible tr', { hasText: name })
}

/** Die Spalte „Nextcloud-Konto?" ist die siebte (nach „Zuweisung"). */
const accountCell = (page, name) => memberRow(page, name).locator('td').nth(6)

async function openAdmitDialog(page) {
	await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()
	const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
	await expect(dialog).toBeVisible()
	return dialog
}

/** Das Fenster der Akte eines Mitglieds, geöffnet über den Namen in der Liste. */
async function openAkte(page, displayName) {
	await memberRow(page, displayName).getByRole('button', { name: `Akte öffnen: ${displayName}` }).click()
	const akte = page.getByRole('dialog', { name: `Mitglied: ${displayName}` })
	await expect(akte).toBeVisible()
	return akte
}

test.describe('Nextcloud-Konto und Willkommens-Mail', () => {
	test.beforeAll(async ({ request }) => {
		await startMailCapture()
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.updateSettings(request, { membership_enabled: '1', club_name: CLUB_NAME })
		// Vorlage zurück auf den Standardtext, falls ein früherer Lauf sie verstellt hat.
		await api.raw(request, 'PUT', '/welcome-mail/template', { expectOk: true, data: { subject: '', body: '' } })
	})

	test.afterAll(async ({ request }) => {
		for (const uid of CREATED_USERS) {
			await deleteUser(request, uid)
		}
		await api.raw(request, 'PUT', '/welcome-mail/template', { data: { subject: '', body: '' } })
		await stopMailCapture()
	})

	test.beforeEach(async () => {
		await clearCapturedMails()
	})

	test('Spalte „Nextcloud-Konto?“: Ja, Vorschlag zum Verknüpfen (mit Rückfrage) und Nein', async ({ page, request }) => {
		await createUser(request, 'e2e.verknuepft')
		await api.setUserEmail(request, USERS.ohneRolle, 'spalte.vorschlag@example.org')
		const vorschlag = await ensureMember(request, 'Vera', 'Vorschlag', 'spalte.vorschlag@example.org')
		const nein = await ensureMember(request, 'Nils', 'Ohnekonto', 'nils.ohnekonto@example.org')
		const ja = await ensureMember(request, 'Jana', 'Verknuepft', 'jana.verknuepft@example.org')
		await api.linkMember(request, ja.id, 'e2e.verknuepft')

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await expect(visibleSection(page).locator('table.vbh-table:visible thead').getByText('Nextcloud-Konto?')).toBeVisible()

		await expect(accountCell(page, 'Jana Verknuepft')).toHaveText('Ja')
		await expect(accountCell(page, 'Nils Ohnekonto')).toHaveText('Nein')
		const suggestion = accountCell(page, 'Vera Vorschlag').getByRole('button', { name: 'Vorschlag spalte.vorschlag@example.org verknüpfen' })
		await expect(suggestion).toBeVisible()

		// Verknüpft wird erst nach Rückfrage – „Abbrechen" ändert nichts.
		await suggestion.click()
		const confirm = page.getByRole('dialog', { name: 'Mit Nextcloud-Konto verknüpfen' })
		await expect(confirm).toContainText('Vera Vorschlag')
		await confirm.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(suggestion).toBeVisible()
		expect((await api.listMembers(request)).find((m) => m.id === vorschlag.id).ncUserId).toBeFalsy()

		await suggestion.click()
		await confirm.getByRole('button', { name: 'Verknüpfen', exact: true }).click()
		await expect(accountCell(page, 'Vera Vorschlag')).toHaveText('Ja')
		expect((await api.listMembers(request)).find((m) => m.id === vorschlag.id).ncUserId).toBe(USERS.ohneRolle)

		await api.unlinkMember(request, vorschlag.id)
		await api.unlinkMember(request, ja.id)
		expect(nein.id).toBeTruthy()
	})

	test('Aufnehmen mit Nextcloud-Konto und Willkommens-Mail: Konto angelegt, verknüpft, Mail verschickt', async ({ page, request }) => {
		await openApp(page, USERS.admin)
		await switchTab(page, 'Beiträge')
		const dialog = await openAdmitDialog(page)

		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Mona')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Konto')
		await dialog.getByLabel('E-Mail', { exact: true }).fill('mona.konto@example.org')

		// Das Konto: Angaben wie in der Benutzerverwaltung, Kontoname aus dem Namen vorgeschlagen.
		await dialog.getByText('Nextcloud-Konto für dieses Mitglied anlegen').click()
		await expect(dialog.getByRole('textbox', { name: 'Kontoname', exact: true })).toHaveValue('mona.konto')
		await expect(dialog.getByRole('textbox', { name: 'Anzeigename', exact: true })).toHaveValue('Mona Konto')
		await dialog.getByLabel('Passwort (optional)').fill(PASSWORD)

		// Die Willkommens-Mail: Vorlage ist eingeklappt, für diesen Fall anpassbar.
		await dialog.getByText('Willkommens-Mail an die Adresse des Mitglieds senden').click()
		const subject = dialog.getByRole('textbox', { name: 'Betreff', exact: true })
		await expect(subject).toBeHidden()
		await dialog.getByRole('button', { name: 'Vorlage anzeigen und für dieses Mitglied anpassen' }).click()
		await expect(subject).toBeVisible()
		await subject.fill('Hallo {vorname}, willkommen bei {verein}')

		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		const member = (await api.listMembers(request)).find((m) => m.displayName === 'Mona Konto')
		expect(member).toBeTruthy()
		expect(member.ncUserId).toBe('mona.konto')
		const info = await userInfo(request, 'mona.konto')
		expect(info).toBeTruthy()
		expect(info.email).toBe('mona.konto@example.org')
		expect(info.displayname ?? info['display-name']).toBe('Mona Konto')
		await expect(accountCell(page, 'Mona Konto')).toHaveText('Ja')

		const welcome = await waitForSubject('mona.konto@example.org', `Hallo Mona, willkommen bei ${CLUB_NAME}`)
		expect(welcome.text).toContain('Guten Tag Mona Konto,')
		expect(welcome.text).not.toContain('{name}')
	})

	test('Aufnehmen ohne Nextcloud-Konto: Willkommens-Mail geht trotzdem raus, die Liste sagt „Nein“', async ({ page, request }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const dialog = await openAdmitDialog(page)

		// Ohne Mailadresse bleibt der Schalter gesperrt und sagt warum.
		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Willi')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Willkommen')
		await expect(dialog.getByRole('switch', { name: 'Willkommens-Mail an die Adresse des Mitglieds senden' })).toBeDisabled()
		await expect(dialog.getByText('Dafür wird eine E-Mail-Adresse gebraucht.')).toBeVisible()
		await dialog.getByLabel('E-Mail', { exact: true }).fill('willi.willkommen@example.org')
		await dialog.getByText('Willkommens-Mail an die Adresse des Mitglieds senden').click()

		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		// Der Standardtext der Vorlage, Platzhalter ersetzt.
		const welcome = await waitForSubject('willi.willkommen@example.org', `Willkommen bei ${CLUB_NAME}`)
		expect(welcome.text).toContain('Guten Tag Willi Willkommen,')
		const member = (await api.listMembers(request)).find((m) => m.displayName === 'Willi Willkommen')
		expect(member.ncUserId).toBeFalsy()
		await expect(accountCell(page, 'Willi Willkommen')).toHaveText('Nein')
	})

	test('Für Buchhalter ohne Nextcloud-Adminrechte fehlt der Abschnitt „Nextcloud-Konto“', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const dialog = await openAdmitDialog(page)

		await expect(dialog.getByRole('heading', { name: 'Willkommens-Mail (optional)' })).toBeVisible()
		await expect(dialog.getByText('Nextcloud-Konto für dieses Mitglied anlegen')).toHaveCount(0)
	})

	test('Akte: Konto nachholen und Willkommens-Mail senden', async ({ page, request }) => {
		const member = await ensureMember(request, 'Nora', 'Nachholer', 'nora.nachholer@example.org')

		await openApp(page, USERS.admin)
		await switchTab(page, 'Beiträge')
		const akte = await openAkte(page, 'Nora Nachholer')

		await akte.getByRole('button', { name: 'Neues Nextcloud-Konto anlegen' }).click()
		const account = page.getByRole('dialog', { name: 'Nextcloud-Konto anlegen' })
		await expect(account.getByRole('textbox', { name: 'Kontoname', exact: true })).toHaveValue('nora.nachholer')
		await account.getByRole('textbox', { name: 'Kontoname', exact: true }).fill('e2e.nachholer')
		await account.getByLabel('Passwort (optional)').fill(PASSWORD)
		await account.getByRole('button', { name: 'Konto anlegen und verknüpfen' }).click()
		await expect(page.getByText('Nextcloud-Konto angelegt und verknüpft.').first()).toBeVisible()
		expect((await api.listMembers(request)).find((m) => m.id === member.id).ncUserId).toBe('e2e.nachholer')

		await clearCapturedMails()
		await akte.getByRole('button', { name: 'Willkommens-Mail senden' }).click()
		const mail = page.getByRole('dialog', { name: 'Willkommens-Mail senden' })
		await expect(mail.getByRole('textbox', { name: 'Betreff', exact: true })).toHaveValue('Willkommen bei {verein}')
		await mail.getByRole('button', { name: 'Mail senden' }).click()
		await expect(page.getByText('Willkommens-Mail gesendet.').first()).toBeVisible()

		await waitForSubject('nora.nachholer@example.org', `Willkommen bei ${CLUB_NAME}`)
	})

	test('Einstellungen: Vorlage speichern, für den nächsten Dialog wirksam, Standardtext wiederherstellen', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_willkommens-mail')
		await expect(section.getByRole('textbox', { name: 'Betreff', exact: true })).toHaveValue('Willkommen bei {verein}')

		await section.getByRole('textbox', { name: 'Betreff', exact: true }).fill('Schön, dass du da bist, {vorname}!')
		await section.getByRole('textbox', { name: 'Text', exact: true }).fill('Hallo {name}, willkommen im Chor.')
		await section.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(page.getByText('Einstellungen gespeichert.').first()).toBeVisible()

		const template = await api.getJson(request, '/welcome-mail/template')
		expect(template.subject).toBe('Schön, dass du da bist, {vorname}!')
		expect(template.isDefault).toBe(false)

		await section.getByRole('button', { name: 'Standardtext wiederherstellen' }).click()
		// Der Toast vom ersten Speichern steht noch; erst der zurückgesetzte
		// Betreff zeigt, dass die Antwort da ist.
		await expect(section.getByRole('textbox', { name: 'Betreff', exact: true })).toHaveValue('Willkommen bei {verein}')
		expect((await api.getJson(request, '/welcome-mail/template')).isDefault).toBe(true)
	})
})
