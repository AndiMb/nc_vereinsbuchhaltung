import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Lücken des Testprotokolls (Durchlauf vom 09.10.2026): Schritte, die im
// Browser der Dev-Instanz nicht prüfbar waren und für die es noch keinen Test
// gab. Die Schrittnummern stehen vor den Testnamen.
//
//  - Mobil (15.1–15.4): Kopf und Navigation, Mitgliederkarten, Einzug-Segmente,
//    die Dialoge „Mitglied aufnehmen“, „Mitgliederliste einlesen“ und „Einzug
//    freigeben“ sowie das Klemmbrett bei 375 × 812.
//  - Tastatur (15.5): Escape schließt Dialoge. Das Klemmbrett deckt Spec 38 ab,
//    den Fokus beim Öffnen von „Mitglied aufnehmen“ Spec 22.
//  - Namen der Symbol-Knöpfe (15.6).
//  - Serverfehler und Offline ohne Rohtext (16.5), Zwei Personen zugleich (16.6).
//  - Reibungsdialoge Widerruf und Kontoinhaberwechsel (4.7, 4.8) im Detail, die
//    Warnung „Unterschriftsdatum liegt mehr als 36 Monate zurück“ (4.2).
//
// Bewusst nicht hier (Begründungen im Bericht zum Testprotokoll):
//  - 9.12/9.13 (Zahlungserinnerung, Mahnung, Eskalation): Die Stufen hängen am
//    Mahnabstand in Tagen und am echten Kalendertag, der Server kennt keine
//    Zeitreise. Abgedeckt durch PHPUnit (DunningLadderServiceTest,
//    DunningTaskServiceTest).
//  - 7.15 (XML-Ablage im Nextcloud-Ordner): schon Spec 42, „XML-Ablage
//    eingeschaltet …“ (inklusive WebDAV-Prüfung der Kopie).
//  - Fokusrahmen und Zoom auf 200 % (15.6) sowie die Fokus-Rückkehr nach dem
//    Schließen eines Dialogs (15.5): rein visuell bzw. nicht eindeutig
//    festgelegt.
//
// Der Bestand ist NICHT leer: `api.resetBook()` räumt Buchungen, Forderungen
// und Läufe, aber keine Mitglieder, Mandate und Zuweisungen früherer Specs.
// Jeder Test legt deshalb Mitglieder mit eindeutigem Nachnamen an und sucht
// sie in der Oberfläche gezielt; Termine liegen relativ zu heute, je Test an
// einem eigenen Tag.

const IBAN = 'DE12500105170648489890'
const IBAN_NEW_HOLDER = 'DE44500105175407324931'

/** Rohtext, den die Oberfläche nie zeigen darf (Testprotokoll 16.3 und 16.5). */
const ROHTEXT = /Request failed|Network Error|status code|\[object Object\]|AxiosError/

const iso = (date) => date.toISOString().slice(0, 10)
const today = () => iso(new Date())
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const germanDate = (isoDate) => isoDate.split('-').reverse().join('.')
const currentYear = () => new Date().getUTCFullYear()
const suffix = () => Math.random().toString(36).slice(2, 6)

/** Der 15. des Monats vor `months` Monaten (der 15. kennt keinen Monatsüberlauf). */
function midMonthAgo(months) {
	const d = new Date(`${today()}T00:00:00Z`)
	d.setUTCDate(15)
	d.setUTCMonth(d.getUTCMonth() - months)
	return iso(d)
}

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

/** Mitglied mit eindeutigem Nachnamen: /reset räumt Mitglieder nicht ab, ein Wiederholungslauf fände sonst zwei gleichnamige Zeilen. */
async function createMember(request, firstName, lastName) {
	const surname = `${lastName}-${suffix()}`
	return api.createMember(request, { firstName, lastName: surname, email: `${firstName}.${surname}@example.org`.toLowerCase() })
}

/** Aktives Papier-Mandat über die API (Unterschrift heute: kein Verfall in Sicht, anders als ein festes Datum). */
async function createActiveMandate(request, member, { iban = IBAN } = {}) {
	const mandate = await (await api.createMandate(request, { memberId: member.id, iban, signedAt: today() })).json()
	await api.activateMandate(request, mandate.id)
	return mandate
}

async function createClaim(request, memberId, dueDate, { amount = 12.5, label = 'Beitrag Lückenspec' } = {}) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type: 'beitrag', amount, label, dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

/** Öffnet Beiträge → Einzug; mobil über die untere Leiste. */
async function openEinzug(page, { user = USERS.buchhalter, mobil = false } = {}) {
	await openApp(page, user)
	if (mobil) {
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()
	} else {
		await switchTab(page, 'Beiträge')
	}
	await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
	await expect(visibleSection(page).getByRole('tab', { name: 'Zeitstrahl & Läufe' })).toBeVisible()
}

/** Öffnet die Akte eines Mitglieds über „Mandat verwalten“ im Zeilenmenü (Desktop). */
async function openAkte(page, user, name) {
	await openApp(page, user)
	await switchTab(page, 'Beiträge')
	const row = visibleSection(page).locator('tr', { hasText: name })
	await row.getByRole('button', { name: 'Aktionen' }).click()
	await page.getByRole('menuitem', { name: 'Mandat verwalten' }).click()
	const dialog = page.getByRole('dialog', { name: `Mitglied: ${name}` })
	await expect(dialog).toBeVisible()
	return dialog
}

const panel = (akte) => akte.locator('section.vbh-mandate-panel')
const status = (akte) => panel(akte).locator('.vbh-mandate-status')
/** Der Wert zu einer Bezeichnung der Definitionsliste des lebenden Mandats. */
const field = (akte, label) => panel(akte).locator('.vbh-mandate-card').first().locator('dt', { hasText: label }).locator('xpath=following-sibling::dd[1]')

/** Hat der NcButton diese Variante? (NcButton trägt sie als Klasse `button-vue--<variante>`.) */
const variante = (name) => new RegExp(`(^|\\s)button-vue--${name}(\\s|$)`)

/**
 * Escape drücken, bis der Dialog zu ist. Escape wirkt erst, wenn der Fokus im Dialog
 * liegt (auch auf der Maske selbst, siehe src/lib/modalEscape.js); davor wird die
 * Taste wiederholt, bis die Öffnen-Animation durch ist.
 */
async function mitEscapeSchliessen(page, dialog) {
	await expect(page.locator('.modal-mask:focus-within')).toHaveCount(1)
	await expect(async () => {
		await page.keyboard.press('Escape')
		await expect(dialog).toBeHidden({ timeout: 1500 })
	}).toPass({ timeout: 10000 })
}

// ---------------------------------------------------------------------------

test.describe('Tastatur und Namen', () => {
	test('15.5 · „Mitglied aufnehmen“ schließt mit Escape, ohne zu speichern', async ({ page, request }) => {
		const nachname = `Escape-${suffix()}`

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()
		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill(nachname)

		// Escape zählt, wo der Fokus auf einem Knopf steht (nach Tab durch das Formular) – siehe den Verdachtstest darunter für das Textfeld.
		await dialog.getByRole('button', { name: 'Abbrechen' }).focus()
		await mitEscapeSchliessen(page, dialog)

		expect((await api.listMembers(request)).some((m) => m.displayName.includes(nachname))).toBe(false)
	})

	// NcModal (@nextcloud/vue 9.11) ignoriert Escape, solange der Fokus in einem <input>, <textarea> oder <select> steht
	// (useHotKey/shouldIgnoreEvent). „Mitglied aufnehmen“ öffnet aber mit dem Cursor im Feld „Vorname“; die Brücke in
	// src/lib/modalEscape.js klickt dann den Schließen-Knopf des Dialogs. Am laufenden System bestätigt (Testprotokoll 15.5).
	test('15.5 · Escape schließt „Mitglied aufnehmen“ auch mit dem Cursor im Feld „Vorname“', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()
		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog.getByRole('textbox', { name: 'Vorname', exact: true })).toBeFocused()

		await page.keyboard.press('Escape')
		await expect(dialog).toBeHidden()
	})

	test('15.5 · „Mitgliederliste einlesen“ schließt mit Escape', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Liste einlesen', exact: true }).click()
		const dialog = page.getByRole('dialog', { name: 'Mitgliederliste einlesen' })
		await expect(dialog).toBeVisible()

		await mitEscapeSchliessen(page, dialog)
	})

	test('15.5 · „Einzug freigeben“ schließt mit Escape, es entsteht kein Lauf', async ({ page, request }) => {
		test.setTimeout(60000)
		const dueDate = plusDays(12)
		const member = await createMember(request, 'Freigabe', 'Escape')
		await createActiveMandate(request, member)
		await createClaim(request, member.id, dueDate, { label: `Beitrag Freigabe-Escape ${suffix()}` })

		await openEinzug(page)
		const section = visibleSection(page)
		const marker = section.getByRole('button', { name: new RegExp(`^Einzugstermin ${germanDate(dueDate).replaceAll('.', '\\.')}`) })
		if (Number(dueDate.slice(0, 4)) > currentYear() && await marker.count() === 0) {
			await section.getByRole('button', { name: 'Nächstes Beitragsjahr' }).click()
		}
		await marker.dispatchEvent('click') // Marker können sich überdecken, ein Mausklick träfe den falschen
		await expect(marker).toHaveAttribute('aria-pressed', 'true')
		const ghost = section.getByRole('region', { name: `Vorschau des Einzugs am ${germanDate(dueDate)}` })
		await ghost.getByRole('button', { name: 'Freigeben & Datei erzeugen' }).click()
		const dialog = page.getByRole('dialog', { name: `Einzug am ${germanDate(dueDate)} freigeben` })
		await expect(dialog).toBeVisible()

		await mitEscapeSchliessen(page, dialog)
		expect((await api.getJson(request, '/debit-batches')).filter((batch) => batch.dueDate === dueDate)).toHaveLength(0)
	})

	test('15.6 · Symbol-Knöpfe haben Namen und Tooltip: Hilfe, Klemmbrett, Forderungen und Bankabgleich aktualisieren', async ({ page }) => {
		await openEinzug(page)
		const kopf = page.locator('.vbh-navright')
		await expect(kopf.getByRole('button', { name: 'Hilfe' })).toHaveAttribute('title', 'Hilfe')
		const klemmbrett = kopf.getByRole('button', { name: /^Aufgaben/ })
		await expect(klemmbrett).toBeVisible()
		await expect(klemmbrett).toHaveAttribute('title', /^Aufgaben/)

		const section = visibleSection(page)
		await section.getByRole('tab', { name: 'Forderungen', exact: true }).click()
		await expect(section.getByRole('button', { name: 'Forderungen aktualisieren' })).toHaveAttribute('title', 'Forderungen aktualisieren')
		await section.getByRole('tab', { name: 'Bankabgleich', exact: true }).click()
		await expect(section.getByRole('button', { name: 'Bankabgleich aktualisieren' })).toHaveAttribute('title', 'Bankabgleich aktualisieren')
	})
})

// ---------------------------------------------------------------------------
// 16.5 Serverfehler und Offline ohne Rohtext
// ---------------------------------------------------------------------------

test.describe('Serverfehler und Offline ohne Rohtext', () => {
	/** Eine API-Adresse (Pfadende) gezielt ausfallen lassen: 500 mit leerem Rumpf, solange `ausfall.an` gilt. */
	async function ausfallen(page, pfadende) {
		const ausfall = { an: true }
		await page.route((url) => url.pathname.endsWith(pfadende), (route) => (
			ausfall.an
				? route.fulfill({ status: 500, contentType: 'application/json', body: '{}' })
				: route.continue()
		))
		return ausfall
	}

	test('16.5 · Einzug: fällt der Server aus, steht eine deutsche Meldung mit „Erneut versuchen“ da, danach der Zeitstrahl', async ({ page }) => {
		const ausfall = await ausfallen(page, '/api/debit-batches/timeline')
		await openEinzug(page)
		const section = visibleSection(page)

		await expect(section.getByText('Der Einzug konnte nicht geladen werden.')).toBeVisible()
		await expect(section.getByRole('heading', { name: /^Beitragsjahr / })).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText(ROHTEXT)

		ausfall.an = false
		await section.getByRole('button', { name: 'Erneut versuchen' }).click()
		await expect(section.getByRole('heading', { name: /^Beitragsjahr / })).toBeVisible()
		await expect(section.getByText('Der Einzug konnte nicht geladen werden.')).toHaveCount(0)
	})

	test('16.5 · Forderungen: fällt der Server aus, steht „Die Forderungen konnten nicht geladen werden.“ da, danach die Liste', async ({ page }) => {
		const ausfall = await ausfallen(page, '/api/claims/overview')
		await openEinzug(page)
		const section = visibleSection(page)
		await section.getByRole('tab', { name: 'Forderungen', exact: true }).click()
		const forderungen = section.getByRole('tabpanel', { name: 'Forderungen' })

		await expect(forderungen.getByText('Die Forderungen konnten nicht geladen werden.')).toBeVisible()
		await expect(forderungen.getByRole('group', { name: 'Filter' })).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText(ROHTEXT)

		ausfall.an = false
		await forderungen.getByRole('button', { name: 'Erneut versuchen' }).click()
		await expect(forderungen.getByRole('group', { name: 'Filter' })).toBeVisible()
		await expect(forderungen.getByText('Die Forderungen konnten nicht geladen werden.')).toHaveCount(0)
	})

	test('16.5 · Bankabgleich: fällt der Server aus, steht „Der Bankabgleich konnte nicht geladen werden.“ da, danach die Ansicht', async ({ page }) => {
		const ausfall = await ausfallen(page, '/api/bank-reconciliation')
		await openEinzug(page)
		const section = visibleSection(page)
		await section.getByRole('tab', { name: 'Bankabgleich', exact: true }).click()
		const bank = section.getByRole('tabpanel', { name: 'Bankabgleich' })

		await expect(bank.getByText('Der Bankabgleich konnte nicht geladen werden.')).toBeVisible()
		await expect(page.locator('body')).not.toContainText(ROHTEXT)

		ausfall.an = false
		await bank.getByRole('button', { name: 'Erneut versuchen' }).click()
		await expect(bank.getByText('Der Bankabgleich konnte nicht geladen werden.')).toHaveCount(0)
		await expect(bank.getByRole('button', { name: 'Erneut versuchen' })).toHaveCount(0)
	})

	test('16.5 · Offline: scheitert nur die Aktualisierung, bleibt die Forderungsliste stehen und ein deutscher Hinweis sagt es', async ({ page, context, request }) => {
		test.setTimeout(60000)
		const label = `Beitrag Offline ${suffix()}`
		const member = await createMember(request, 'Offline', 'Forderung')
		await createClaim(request, member.id, plusDays(25), { label })

		await openEinzug(page)
		const section = visibleSection(page)
		await section.getByRole('tab', { name: 'Forderungen', exact: true }).click()
		const forderungen = section.getByRole('tabpanel', { name: 'Forderungen' })
		await expect(forderungen).toContainText(label)
		const aktualisieren = forderungen.getByRole('button', { name: 'Forderungen aktualisieren' })
		const hinweis = forderungen.getByText('Die Ansicht konnte nicht aktualisiert werden')

		await context.setOffline(true)
		try {
			await aktualisieren.click()
			await expect(hinweis).toBeVisible()
			await expect(hinweis).toContainText('Die Forderungen konnten nicht geladen werden.')
			await expect(forderungen).toContainText(label) // der zuletzt geladene Stand bleibt
			await expect(page.locator('body')).not.toContainText(ROHTEXT)
		} finally {
			await context.setOffline(false)
		}

		await aktualisieren.click()
		await expect(hinweis).toHaveCount(0)
		await expect(forderungen).toContainText(label)
	})

	test('16.5 · Klemmbrett: scheitert nur die Aktualisierung, bleibt der letzte Stand stehen und ein Hinweis sagt es', async ({ page }) => {
		let antwort = 'ok'
		await page.route((url) => url.pathname.endsWith('/apps/vereinsbuchhaltung/api/tasks'), (route) => (
			antwort === 'fehler'
				? route.fulfill({ status: 500, contentType: 'application/json', body: '{}' })
				: route.fulfill({ status: 200, contentType: 'application/json', body: '[]' })
		))
		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-navright').getByRole('button', { name: /^Aufgaben/ }).click()
		const dialog = page.getByRole('dialog', { name: 'Aufgaben', exact: true })
		await expect(dialog.getByText('Nichts zu tun')).toBeVisible()

		antwort = 'fehler'
		await dialog.getByRole('button', { name: 'Aufgaben aktualisieren' }).click()
		await expect(dialog.getByRole('alert')).toContainText('Die Liste konnte nicht aktualisiert werden. Angezeigt wird der zuletzt geladene Stand.')
		await expect(dialog.getByText('Nichts zu tun')).toBeVisible()
		await expect(page.locator('body')).not.toContainText(ROHTEXT)

		antwort = 'ok'
		await dialog.getByRole('button', { name: 'Aufgaben aktualisieren' }).click()
		await expect(dialog.getByRole('alert')).toHaveCount(0)
	})
})

// ---------------------------------------------------------------------------
// 16.6 Zwei Personen zugleich
// ---------------------------------------------------------------------------

test.describe('Zwei Personen zugleich', () => {
	test('16.6 · Das zweite „Sperren“ aus veraltetem Stand scheitert mit einer verständlichen Meldung, das Mandat bleibt einmal gesperrt', async ({ browser, request }) => {
		test.setTimeout(90000)
		const member = await createMember(request, 'Zwei', 'Fenster')
		const mandate = await createActiveMandate(request, member)

		const contextA = await browser.newContext()
		const contextB = await browser.newContext()
		try {
			const pageA = await contextA.newPage()
			const pageB = await contextB.newPage()
			const akteA = await openAkte(pageA, USERS.verwalter, member.displayName)
			const akteB = await openAkte(pageB, USERS.buchhalter, member.displayName)
			await expect(status(akteA)).toHaveText('Aktiv')
			await expect(status(akteB)).toHaveText('Aktiv')

			// Fenster A sperrt …
			await panel(akteA).getByRole('button', { name: 'Sperren', exact: true }).click()
			await panel(akteA).getByLabel('Grund der Sperre (Pflicht)').fill('Fenster A')
			await panel(akteA).getByRole('button', { name: 'Mandat sperren' }).click()
			await expect(status(akteA)).toHaveText('Ausgesetzt')

			// … Fenster B kennt nur den alten Stand („Aktiv“) und versucht dasselbe.
			await expect(status(akteB)).toHaveText('Aktiv')
			await panel(akteB).getByRole('button', { name: 'Sperren', exact: true }).click()
			await panel(akteB).getByLabel('Grund der Sperre (Pflicht)').fill('Fenster B')
			await panel(akteB).getByRole('button', { name: 'Mandat sperren' }).click()

			await expect(pageB.getByText('Nur ein aktives Mandat lässt sich aussetzen.')).toBeVisible()
			await expect(pageB.locator('body')).not.toContainText(ROHTEXT)

			// Das Mandat bleibt gesperrt, mit der Notiz aus Fenster A, und die Historie hat nur einen Eintrag „gesperrt“.
			const detail = await api.getJson(request, `/mandates/${mandate.id}`)
			expect(detail.status).toBe('ausgesetzt')
			expect(detail.suspensionNote).toBe('Fenster A')
			expect(detail.history.filter((event) => event.message.startsWith('Mandat gesperrt'))).toHaveLength(1)
		} finally {
			await contextA.close()
			await contextB.close()
			await api.resumeMandate(request, mandate.id, { expectOk: false }) // die Sperre soll dem Rest der Suite nicht als Aufgabe im Klemmbrett stehen
		}
	})

	test('16.6 · Zurück im Fenster lädt der Einzug neu und zeigt, was inzwischen anderswo geschah', async ({ page, request }) => {
		test.setTimeout(60000)
		const dueDate = plusDays(14)
		const member = await createMember(request, 'Fensterwechsel', 'Einzug')
		await createActiveMandate(request, member)
		await createClaim(request, member.id, dueDate, { label: `Beitrag Fensterwechsel ${suffix()}` })

		await openEinzug(page)
		const section = visibleSection(page)
		await expect(section.getByRole('heading', { name: /^Beitragsjahr / })).toBeVisible()

		// Eine andere Person gibt den Lauf frei und reicht ihn ein (hier über die API) …
		await api.releaseAndSubmitDebitBatch(request, dueDate)

		// … dieses Fenster zieht nach, sobald es wieder den Fokus bekommt (das Fenster-Ereignis „focus“).
		await page.evaluate(() => window.dispatchEvent(new Event('focus')))
		await expect(section.getByRole('row', { name: new RegExp(`^${germanDate(dueDate).replaceAll('.', '\\.')} eingereicht`) }).first()).toBeVisible()
	})
})

// ---------------------------------------------------------------------------
// 4.2, 4.7, 4.8 Mandate: Alterswarnung und Reibungsdialoge im Detail
// ---------------------------------------------------------------------------

test.describe('Mandate: Warnung und Reibungsdialoge', () => {
	test('4.2 · Unterschriftsdatum vor mehr als 36 Monaten: Warnung ohne Sperre, ein jüngeres Datum nimmt sie zurück', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Willi', 'Altdatum')
		await api.createMandate(request, { memberId: member.id, iban: IBAN }) // Papier-Entwurf ohne Unterschriftsdatum

		const akte = await openAkte(page, USERS.buchhalter, member.displayName)
		const p = panel(akte)
		await expect(status(akte)).toHaveText('Entwurf')
		await p.getByRole('button', { name: 'Aktivieren', exact: true }).click()

		const datum = p.getByLabel('Mandat unterschrieben am')
		const aktivieren = p.getByRole('button', { name: 'Mandat aktivieren' })
		const warnung = p.getByText('Das Unterschriftsdatum liegt mehr als 36 Monate zurück')
		await expect(aktivieren).toBeDisabled() // ohne Datum kein aktives Mandat
		await expect(warnung).toHaveCount(0)

		// Vor vier Jahren: die 36 Monate sind lange um – Warnung, aber die Entscheidung bleibt beim Menschen.
		await datum.fill(midMonthAgo(48))
		await expect(warnung).toBeVisible()
		await expect(warnung).toContainText('das Mandat würde nach der Aktivierung sofort verfallen')
		await expect(aktivieren).toBeEnabled()

		// Vor 35 Monaten: noch gut einen Monat Restlaufzeit – keine Warnung.
		await datum.fill(midMonthAgo(35))
		await expect(warnung).toHaveCount(0)

		await datum.fill(midMonthAgo(48))
		await expect(warnung).toBeVisible()

		// Mit dem heutigen Datum geht es wie in 4.2 Schritt 6 weiter.
		await datum.fill(today())
		await expect(warnung).toHaveCount(0)
		await aktivieren.click()
		await expect(status(akte)).toHaveText('Aktiv')
	})

	test('4.8 · Widerruf: roter Kasten mit offener Summe, der Ausweg ist der Primärknopf, Abbrechen und Escape widerrufen nichts', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Wilma', 'Widerruf')
		const mandate = await createActiveMandate(request, member)
		await createClaim(request, member.id, plusDays(30), { amount: 12.5, label: `Beitrag Widerruf ${suffix()}` })

		const akte = await openAkte(page, USERS.buchhalter, member.displayName)
		const p = panel(akte)
		await expect(status(akte)).toHaveText('Aktiv')

		await p.getByRole('button', { name: 'Mandat widerrufen' }).click()
		const widerruf = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		const kasten = widerruf.locator('.vbh-card--danger')
		await expect(kasten).toContainText('Der Widerruf ist endgültig')
		await expect(kasten).toContainText('lässt sich nicht wieder aktivieren')
		await expect(kasten).toContainText(`braucht ${member.displayName} danach ein neues Mandat mit neuer Unterschrift`)
		await expect(kasten).toContainText(/Noch offen:\s*12,50\s*€/)
		await expect(kasten).toContainText('dafür geht dem Mitglied eine Zahlungsaufforderung zu')

		const ausweg = widerruf.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' })
		await expect(ausweg).toHaveClass(variante('primary'))
		await expect(widerruf.getByRole('button', { name: 'Mandat endgültig widerrufen' })).toHaveClass(variante('error'))
		await expect(widerruf.getByRole('button', { name: 'Abbrechen' })).toHaveClass(variante('tertiary'))
		await expect(widerruf.getByRole('textbox')).toHaveCount(0) // keine Zweitfaktor-Abfrage

		// Der Ausweg öffnet „Bankverbindung ändern“ im IBAN-Modus; „Abbrechen“ dort ändert nichts.
		await ausweg.click()
		await expect(widerruf).toBeHidden()
		const konto = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await expect(konto).toBeVisible()
		await expect(konto.getByLabel('Gleicher Kontoinhaber, nur die IBAN hat sich geändert')).toBeChecked()
		await konto.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(konto).toBeHidden()
		await expect(status(akte)).toHaveText('Aktiv')

		// Escape schließt den Reibungsdialog, ohne zu widerrufen, und lässt die Akte dahinter offen.
		await p.getByRole('button', { name: 'Mandat widerrufen' }).click()
		await expect(widerruf).toBeVisible()
		await mitEscapeSchliessen(page, widerruf)
		await expect(akte).toBeVisible()
		await expect(status(akte)).toHaveText('Aktiv')

		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].status).toBe('aktiv')
		expect((await api.getJson(request, `/mandates/${mandate.id}`)).amendments).toHaveLength(0)
	})

	test('4.7 · Kontoinhaberwechsel: roter Kasten, der Ausweg ist der Primärknopf und schaltet zurück, der neue Entwurf lässt sich aktivieren', async ({ page, request }) => {
		test.setTimeout(60000)
		const member = await createMember(request, 'Karla', 'Inhaberwechsel')
		const old = await createActiveMandate(request, member)
		await createClaim(request, member.id, plusDays(30), { amount: 12.5, label: `Beitrag Inhaberwechsel ${suffix()}` })

		const akte = await openAkte(page, USERS.buchhalter, member.displayName)
		const p = panel(akte)
		await p.getByRole('button', { name: 'Bankverbindung ändern' }).click()
		const konto = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await konto.getByText('Der Kontoinhaber wechselt (andere Person)', { exact: true }).click()

		const kasten = konto.locator('.vbh-card--danger')
		await expect(kasten).toContainText('Das bisherige Mandat wird endgültig beendet')
		await expect(kasten).toContainText('eine eigene Unterschrift')
		await expect(kasten).toContainText('Das lässt sich nicht rückgängig machen')
		await expect(kasten).toContainText(/Noch offen:\s*12,50\s*€/)
		const ausweg = konto.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' })
		const wechseln = konto.getByRole('button', { name: 'Kontoinhaber wechseln' })
		await expect(ausweg).toHaveClass(variante('primary'))
		await expect(wechseln).toHaveClass(variante('secondary'))
		await expect(wechseln).toBeDisabled() // erst mit neuer IBAN und neuem Kontoinhaber

		// Der Ausweg schaltet auf „nur die IBAN“ zurück – ohne etwas zu beenden.
		await ausweg.click()
		await expect(konto.getByLabel('Gleicher Kontoinhaber, nur die IBAN hat sich geändert')).toBeChecked()
		await expect(kasten).toHaveCount(0)
		await expect(konto.getByLabel('Neue IBAN')).toBeVisible()
		expect(await api.mandatesByMember(request, member.id)).toHaveLength(1)

		// Dann doch der Wechsel: neuer Entwurf, das alte Mandat ist ersetzt.
		await konto.getByText('Der Kontoinhaber wechselt (andere Person)', { exact: true }).click()
		await konto.getByLabel('Neue IBAN').fill(IBAN_NEW_HOLDER)
		await konto.getByLabel('Neuer Kontoinhaber').fill('Maria Neuinhaberin')
		await wechseln.click()
		await expect(status(akte)).toHaveText('Entwurf')
		await expect(field(akte, 'Kontoinhaber')).toHaveText('Maria Neuinhaberin')
		await expect(p.locator('details.vbh-mandate-past summary')).toContainText('Ersetzt')

		// Der neue Entwurf wird wie in 4.2 mit dem heutigen Datum aktiviert.
		await p.getByRole('button', { name: 'Aktivieren', exact: true }).click()
		await p.getByLabel('Mandat unterschrieben am').fill(today())
		await p.getByRole('button', { name: 'Mandat aktivieren' }).click()
		await expect(status(akte)).toHaveText('Aktiv')

		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(2)
		expect(mandates[0].status).toBe('aktiv') // neueste zuerst
		expect(mandates[1].id).toBe(old.id)
		expect(mandates[1].endReason).toBe('ersetzt')
	})
})
