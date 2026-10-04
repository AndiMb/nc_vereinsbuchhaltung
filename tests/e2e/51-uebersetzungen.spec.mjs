import { expect, test } from '@playwright/test'
import { api, authHeaders, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BASE_URL, openApp, tabButton, USERS, visibleSection, waitForAppLoaded } from './fixtures/nextcloud.mjs'
import { startMailCapture, stopMailCapture, waitForMailsTo, clearCapturedMails } from './fixtures/mail-capture.mjs'

// Übersetzungen des Beiträge/SEPA-Moduls (Issue #106, Spec §1.4/§3.11):
//
// - Ein Nutzer mit englischer Sprache sieht den Einzug-Reiter und „Mein Beitrag"
//   auf Englisch, ohne deutsche Reste.
// - Nutzerdaten (hier „Echo & Söhne <b>GmbH</b>") stehen unverändert auf dem
//   Bildschirm: @nextcloud/l10n würde sie als Variable in t() als HTML escapen
//   („Echo &amp; Söhne"); die betroffenen Sätze laufen deshalb über tRaw().
// - Du oder Sie: die Quelltexte sind Sie, l10n/de.json liefert die Du-Fassung für
//   informelles Deutsch (`de`), förmliches Deutsch (`de_DE`) bleibt beim Quelltext.
//   Mails an Mitglieder folgen der Sprache des verknüpften NC-Kontos – nicht der
//   des auslösenden Verwalters –, ohne Konto gilt Sie (RecipientL10n).
//
// FALLE Sprache: das CI-Serverimage erzwingt per `force_language` Englisch;
// tests/e2e/setup/server.mjs löscht das und stellt die Nutzer auf Deutsch (de),
// test5 auf Englisch. Diese Spec setzt die Sprachen der Nutzer, die sie braucht,
// zu Beginn ausdrücklich (OCS-Benutzereinstellung `language`) und im afterAll
// wieder auf den Stand des Setups zurück – sonst kippten nachfolgende Specs auf
// die falsche Sprache (test4 läuft ab hier womöglich auf de_DE).
//
// Rollen: test5 braucht für die Akte (Mandat verwalten) die Buchhalter-Rolle;
// 16-l10n vergibt ihm Revisor, danach setzt diese Spec es wieder darauf zurück.
// Die Verknüpfungen Mitglied ↔ NC-Konto räumt jeder Test selbst frei (26/46
// verknüpfen test4, `unlinkMember` zuerst, wie linkFreshMember in 46).

const IBAN = 'DE12500105170648489890'
const NAME_WITH_SPECIAL_CHARS = 'Echo & Söhne <b>GmbH</b>'

/** Wörter, die in der englischen Oberfläche nichts verloren haben (nur eigene Beispieldaten, keine Nutzereingaben anderer Specs). */
const GERMAN_REMNANTS = /\b(Zeitstrahl|Bankabgleich|Forderungen|Läufe|Beitragsjahr|Rücklastschrift|Rücklastschriften|Störfälle|Zahlungsaufforderung|Mahnstand|Mandat|Lastschrift|Kontoinhaber|Stammdaten|Mitgliedsnummer|Datenübersicht|Beitragsbestätigung)\b/

const today = () => new Date().toISOString().slice(0, 10)
const unique = (base) => `${base}-${Math.random().toString(36).slice(2, 6)}`

/** Sprache eines NC-Kontos über die Provisioning-API setzen (Verwalter dürfen das für andere Konten). */
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

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		self_service_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

async function createMember(request, firstName, lastName, extra = {}) {
	const surname = unique(lastName)
	return api.createMember(request, { firstName, lastName: surname, email: `${firstName}.${surname}@example.org`.toLowerCase(), ...extra })
}

/** Verknüpft das NC-Konto mit dem Mitglied – ein vorher verknüpftes Mitglied wird freigegeben. */
async function relink(request, uid, member) {
	const existing = (await api.listMembers(request)).find((m) => m.ncUserId === uid)
	if (existing) {
		await api.unlinkMember(request, existing.id)
	}
	await api.linkMember(request, member.id, uid)
}

async function activeMandate(request, member, { accountHolder } = {}) {
	const mandate = await (await api.createMandate(request, { memberId: member.id, iban: IBAN, accountHolder, signedAt: today() })).json()
	await api.activateMandate(request, mandate.id)
	return mandate
}

async function ensureGroup(request) {
	const name = 'Test group'
	const groups = await api.getJson(request, '/contribution-groups')
	return groups.find((g) => g.name === name) ?? (await api.raw(request, 'POST', '/contribution-groups', {
		data: { name, minMonthlyAmount: 5, defaultMonthlyAmount: 10, allowedIntervals: [1, 3, 12], defaultInterval: 1, isActive: true },
	})).json()
}

test.describe('Übersetzungen (Issue #106)', () => {
	test.beforeAll(async ({ request }) => {
		// Stand des Setups ausdrücklich herstellen (auch nach einem Wiederholungslauf).
		await setUserLanguage(request, USERS.englisch, 'en')
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
		await api.setRole(request, USERS.englisch, 'buchhalter')
	})

	test.afterAll(async ({ request }) => {
		// Sprachen und Rolle zurück auf den Stand, den das Setup bzw. 16-l10n hinterlässt.
		await setUserLanguage(request, USERS.englisch, 'en')
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await api.setRole(request, USERS.englisch, 'revisor')
		for (const uid of [USERS.englisch, USERS.ohneRolle]) {
			const linked = (await api.listMembers(request)).find((m) => m.ncUserId === uid)
			if (linked) { await api.unlinkMember(request, linked.id) }
		}
	})

	test('der Übersetzungs-Endpunkt liefert für „de" die Du-Fassung und für „de_DE" nichts', async ({ request }) => {
		const informal = await api.raw(request, 'GET', '/l10n/de', { user: USERS.ohneRolle })
		expect(informal.status()).toBe(200)
		const du = (await informal.json()).translations
		expect(du['Ihre Stammdaten konnten nicht geladen werden.']).toBe('Deine Stammdaten konnten nicht geladen werden.')

		const formal = await api.raw(request, 'GET', '/l10n/de_DE', { user: USERS.ohneRolle })
		expect(formal.status()).toBe(200)
		expect(Object.keys((await formal.json()).translations)).toHaveLength(0)

		const english = (await (await api.raw(request, 'GET', '/l10n/en', { user: USERS.englisch })).json()).translations
		expect(english['Ihre Stammdaten konnten nicht geladen werden.']).toBe('Your details could not be loaded.')
		expect(english['Zustand\u0004Aktiv']).toBe('Active')
		expect(english.Aktiv).toBe('Asset') // die Kontoart bleibt davon unberührt
	})

	test('Einzug-Reiter: Reiter, Segmente und Hinweise erscheinen auf Englisch', async ({ page, request }) => {
		test.setTimeout(60000)
		const group = await ensureGroup(request)
		const member = await createMember(request, 'Emma', 'Collection')
		await api.raw(request, 'POST', '/assignments', {
			data: { memberId: member.id, groupId: group.id, intervalMonths: 1, monthlyAmount: 10, paymentMethod: 'ueberweisung', validFrom: today() },
		})

		await openApp(page, USERS.englisch)
		await expect(tabButton(page, 'Contributions')).toBeVisible()
		await tabButton(page, 'Contributions').click()
		const section = visibleSection(page)

		const subtabs = section.locator('.vbh-subtabs')
		await expect(subtabs.getByRole('button', { name: 'Members', exact: true })).toBeVisible()
		await expect(subtabs.getByRole('button', { name: 'Contribution groups', exact: true })).toBeVisible()
		await subtabs.getByRole('button', { name: 'Collection', exact: true }).click()

		const segments = section.getByRole('tablist', { name: 'View in Collection' })
		await expect(segments.getByRole('tab', { name: 'Timeline & runs' })).toBeVisible()
		await expect(segments.getByRole('tab', { name: 'Claims' })).toBeVisible()
		await expect(segments.getByRole('tab', { name: /^Bank reconciliation/ })).toBeVisible()

		// Zeitstrahl: Überschrift mit Beitragsjahr
		await expect(section.locator('.vbh-tl h4')).toContainText('Contribution year')

		await segments.getByRole('tab', { name: 'Claims' }).click()
		await expect(section.getByText('Claims against members with state, exceptions and dunning status.')).toBeVisible()

		await segments.getByRole('tab', { name: /^Bank reconciliation/ }).click()
		await expect(section.getByText('The bank statement is the truth:')).toBeVisible()

		// Alle drei Segmente stehen im DOM (v-show): kein deutscher Rest in keinem davon.
		await expect(section.locator('.vbh-einzug')).not.toContainText(GERMAN_REMNANTS)
	})

	test('„Mein Beitrag": Stammdaten, Beitrag, Mandat, Bestätigung und Daten erscheinen auf Englisch', async ({ page, request }) => {
		test.setTimeout(60000)
		const group = await ensureGroup(request)
		const member = await createMember(request, 'Emma', 'Englisch')
		await activeMandate(request, member)
		await api.raw(request, 'POST', '/assignments', {
			data: { memberId: member.id, groupId: group.id, intervalMonths: 1, monthlyAmount: 10, paymentMethod: 'direct_debit', validFrom: today() },
		})
		await relink(request, USERS.englisch, member)

		await openApp(page, USERS.englisch)
		await tabButton(page, 'My contribution').click()
		const section = visibleSection(page)

		for (const heading of ['My details', 'My contribution', 'My SEPA direct debit mandate', 'Returned direct debits', 'My contribution confirmation', 'My data']) {
			await expect(section.getByRole('heading', { name: heading, exact: true })).toBeVisible()
		}
		await expect(section.getByText('No returned direct debit so far.')).toBeVisible()
		await expect(section.getByRole('button', { name: 'Change bank details' })).toBeVisible()
		await expect(section.getByRole('button', { name: 'Change account holder' })).toBeVisible()
		await expect(section.getByRole('button', { name: 'Revoke mandate' })).toBeVisible()
		await expect(section.getByText('Print-ready information under Art. 15 GDPR on all data stored about your membership')).toBeVisible()
		await expect(section.getByRole('link', { name: 'Open data overview' })).toBeVisible()

		// Der Widerrufsdialog: englischer Text, keine förmliche oder informelle deutsche Anrede.
		await section.getByRole('button', { name: 'Revoke mandate' }).click()
		const dialog = page.getByRole('dialog', { name: 'Revoke mandate' })
		await expect(dialog).toContainText('For future collections you will then need a new mandate.')
		await dialog.getByRole('button', { name: 'Cancel' }).click()

		await expect(section).not.toContainText(GERMAN_REMNANTS)
	})

	test('Nutzerdaten mit „&" und „<" stehen unverändert da, nicht als „&amp;"', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await api.createMember(request, { memberType: 'organisation', organizationName: unique(NAME_WITH_SPECIAL_CHARS), email: `echo.${Math.random().toString(36).slice(2, 6)}@example.org` })
		const shown = member.displayName
		const mandate = await activeMandate(request, member, { accountHolder: shown })

		await openApp(page, USERS.englisch)
		await tabButton(page, 'Contributions').click()
		const section = visibleSection(page)
		await section.locator('.vbh-subtabs').getByRole('button', { name: 'Members', exact: true }).click()

		const row = section.locator('tr', { hasText: shown })
		await row.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Manage mandate' }).click()
		const akte = page.getByRole('dialog', { name: `Member: ${shown}` })
		await expect(akte).toBeVisible()

		// Bankverbindungs-Dialog: „Mandat {referenz} von {inhaber}, bisherige IBAN {iban}."
		await akte.getByRole('button', { name: 'Change bank details' }).click()
		const change = page.getByRole('dialog', { name: 'Change bank details' })
		await expect(change).toContainText(`Mandate ${mandate.mandateReference} of ${shown}, previous IBAN`)
		await expect(change).not.toContainText('&amp;')
		await expect(change).not.toContainText('&lt;')
		await change.getByRole('button', { name: 'Cancel' }).click()

		// Widerrufsdialog der Verwaltung: „… braucht {name} danach ein neues Mandat …" (jetzt englisch, mit dem Namen)
		await akte.getByRole('button', { name: 'Revoke mandate' }).click()
		const revoke = page.getByRole('dialog', { name: 'Revoke mandate' })
		await expect(revoke).toContainText(`${shown} will then need a new mandate with a new signature.`)
		await expect(revoke).not.toContainText('&amp;')
	})

	test('„Mein Beitrag" duzt auf informellem und siezt auf förmlichem Deutsch', async ({ page, request }) => {
		test.setTimeout(90000)
		const member = await createMember(request, 'Dora', 'Deutsch')
		await activeMandate(request, member)
		await relink(request, USERS.ohneRolle, member)

		const openOwnArea = async () => {
			await openApp(page, USERS.ohneRolle)
			await tabButton(page, 'Mein Beitrag').click()
			return visibleSection(page)
		}

		// Informelles Deutsch (de): Du-Fassung aus l10n/de.json
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		let section = await openOwnArea()
		await expect(section.getByText('zu deiner Mitgliedschaft gespeicherten Daten')).toBeVisible()
		await section.getByRole('button', { name: 'Mandat widerrufen' }).click()
		let dialog = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await expect(dialog).toContainText('brauchst du danach ein neues Mandat')
		await dialog.getByRole('button', { name: 'Abbrechen' }).click()

		// Förmliches Deutsch (de_DE): der Quelltext, l10n/de_DE.json ist leer
		await setUserLanguage(request, USERS.ohneRolle, 'de_DE')
		section = await openOwnArea()
		await expect(section.getByText('zu Ihrer Mitgliedschaft gespeicherten Daten')).toBeVisible()
		await section.getByRole('button', { name: 'Mandat widerrufen' }).click()
		dialog = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await expect(dialog).toContainText('brauchen Sie danach ein neues Mandat')
		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(section).not.toContainText(/\b(du|dich|dein\w*|deine\w*)\b/i)

		// zurück auf den Stand des Setups, damit andere Specs nicht auf förmlichem Deutsch laufen
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await waitForAppLoaded(page)
	})
})

test.describe('Mail an das Mitglied folgt der Sprache seines Kontos (Issue #106)', () => {
	test.beforeAll(async ({ request }) => {
		test.setTimeout(60000)
		await setUserLanguage(request, USERS.englisch, 'en')
		await setUserLanguage(request, USERS.ohneRolle, 'de')
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
		await startMailCapture()
	})

	test.afterAll(async ({ request }) => {
		await stopMailCapture()
		for (const uid of [USERS.englisch, USERS.ohneRolle]) {
			const linked = (await api.listMembers(request)).find((m) => m.ncUserId === uid)
			if (linked) { await api.unlinkMember(request, linked.id) }
		}
	})

	/** Einmal-Link-Mail für ein Mitglied, ausgelöst von der (deutschsprachigen) Verwaltung; liefert die abgegriffene Mail. */
	async function activationMail(request, member) {
		await clearCapturedMails()
		const mandate = await (await api.createElectronicMandate(request, { memberId: member.id, iban: IBAN })).json()
		await api.sendActivationLink(request, mandate.id)
		const [mail] = await waitForMailsTo(member.email)
		return mail
	}

	test('englisches Konto: Englisch – auch wenn die Verwaltung den Versand auf Deutsch auslöst', async ({ request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Emma', 'Mail')
		await relink(request, USERS.englisch, member)

		const mail = await activationMail(request, member)

		expect(mail.subject).toBe('Please confirm your SEPA direct debit mandate')
		expect(mail.text).toContain('asks you to confirm the SEPA direct debit mandate')
		expect(mail.text).toContain('With a click on the button you see the complete mandate text and can consent.')
		expect(mail.text).not.toContain('bittet')
		expect(mail.text).not.toContain('Bitte')
	})

	test('Konto auf informellem Deutsch: Du-Fassung', async ({ request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Dora', 'Mail')
		await relink(request, USERS.ohneRolle, member)

		const mail = await activationMail(request, member)

		expect(mail.subject).toBe('Bitte bestätige dein SEPA-Lastschriftmandat')
		expect(mail.text).toContain('bittet dich, das SEPA-Lastschriftmandat')
		expect(mail.text).toContain('siehst du den vollständigen Mandatstext')
		expect(mail.text).not.toContain('bittet Sie')
		expect(mail.text).not.toContain('sehen Sie')
	})

	test('Mitglied ohne Konto: Sie-Form', async ({ request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Sven', 'Mail')

		const mail = await activationMail(request, member)

		expect(mail.subject).toBe('Bitte bestätigen Sie Ihr SEPA-Lastschriftmandat')
		expect(mail.text).toContain('bittet Sie, das SEPA-Lastschriftmandat')
		expect(mail.text).toContain('sehen Sie den vollständigen Mandatstext')
		expect(mail.text).not.toContain('bittet dich')
		expect(mail.text).not.toContain('siehst du')
	})
})
