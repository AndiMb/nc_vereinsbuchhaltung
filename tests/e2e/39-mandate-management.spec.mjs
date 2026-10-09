import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, tabButton, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Mandat-Verwaltung in der Mitglieder-Akte (Issue #100, Spec §2.2/§3.2/§3.4):
// der ganze Lebenszyklus eines Mandats über die Oberfläche statt über die API –
// Papier-Mandat anlegen → aktivieren → sperren → entsperren → widerrufen, dazu
// IBAN ändern (Amendment), Kontoinhaberwechsel, Einmal-Link-Status, Nachweis
// und die Rollenlinie aus Spec §3.9 (Schreiben ab Buchhalter, Revisor liest nur
// mit maskierter IBAN und hat die Akte nicht).
//
// Jeder Test legt sein eigenes Mitglied an und stellt seine Vorbedingungen
// selbst her (nach einem Fehlschlag läuft beforeAll erneut, /reset räumt die
// Mitglieder-Tabellen aber nicht ab).

const IBAN = 'DE12500105170648489890'
const IBAN_GROUPED = 'DE12 5001 0517 0648 4898 90'
const IBAN_NEW = 'DE89370400440532013000'
const IBAN_NEW_GROUPED = 'DE89 3704 0044 0532 0130 00'

const today = () => new Date().toISOString().slice(0, 10)

/** Heute plus 36 Monate als dd.mm.yyyy – das Ablaufdatum eines heute unterschriebenen Mandats. */
function expiryOfTodaysSignature() {
	const d = new Date(`${today()}T00:00:00Z`)
	d.setUTCMonth(d.getUTCMonth() + 36)
	const [y, m, day] = d.toISOString().slice(0, 10).split('-')
	return `${day}.${m}.${y}`
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

/**
 * Legt ein Mitglied mit eindeutigem Nachnamen an: /reset räumt die Mitglieder nicht ab, und ein
 * wiederholter Lauf (Retry, beforeAll nach einem Fehlschlag) fände sonst zwei gleichnamige Zeilen
 * (strict-mode-Verstoß) und ein schon belegtes Mandat vor.
 */
async function createMember(request, firstName, lastName) {
	const unique = `${lastName}-${Math.random().toString(36).slice(2, 6)}`
	return api.createMember(request, { firstName, lastName: unique, email: `${firstName}.${unique}@example.org`.toLowerCase() })
}

/** Aktives Papier-Mandat über die API (wie der Aufnahme-Assistent: Unterschriftsdatum → sofort aktiv). */
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

test.describe('Mandat-Verwaltung in der Akte', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Papier-Mandat: anlegen → aktivieren → sperren → entsperren → widerrufen', async ({ page, request }) => {
		const member = await createMember(request, 'Lena', 'Lebenszyklus')
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)

		await expect(p.getByRole('heading', { name: 'SEPA-Mandat' })).toBeVisible()
		await expect(p).toContainText('Noch kein Mandat hinterlegt')

		// Anlegen ohne Unterschriftsdatum: Entwurf, „Unterschrift fehlt“. Der erste
		// Klick öffnet das Formular, der zweite legt an.
		await p.getByRole('button', { name: 'Mandat anlegen' }).click()
		await p.getByLabel('IBAN', { exact: true }).fill(IBAN)
		await p.getByRole('button', { name: 'Mandat anlegen' }).click()
		await expect(status(dialog)).toHaveText('Entwurf')
		await expect(p).toContainText('Unterschrift fehlt')
		await expect(field(dialog, 'Kontoinhaber')).toHaveText(member.displayName) // vorbefüllt mit dem Anzeigenamen
		// Die Akte zeigt die IBAN unmaskiert (der Buchhalter darf, Spec §3.9).
		await expect(field(dialog, 'IBAN')).toHaveText(IBAN_GROUPED)

		// Aktivieren: das Unterschriftsdatum ist Pflicht und das Gate.
		await p.getByRole('button', { name: 'Aktivieren' }).click()
		await expect(p.getByRole('button', { name: 'Mandat aktivieren' })).toBeDisabled()
		await p.getByLabel('Mandat unterschrieben am').fill(today())
		await p.getByRole('button', { name: 'Mandat aktivieren' }).click()
		await expect(status(dialog)).toHaveText('Aktiv')
		await expect(field(dialog, 'Läuft ab')).toContainText(expiryOfTodaysSignature())
		await expect(field(dialog, 'Nachweis')).toHaveText('fehlt') // der einzige Ort, der es sagt: kein zusätzlicher Hinweiskasten
		await expect(p.getByRole('button', { name: 'Nachweis hochladen' })).toBeVisible()

		// Sperren: ohne Notiz nicht möglich.
		await p.getByRole('button', { name: 'Sperren', exact: true }).click()
		await expect(p.getByRole('button', { name: 'Mandat sperren' })).toBeDisabled()
		await p.getByLabel('Grund der Sperre (Pflicht)').fill('Rückfrage beim Mitglied')
		await p.getByRole('button', { name: 'Mandat sperren' }).click()
		await expect(status(dialog)).toHaveText('Ausgesetzt')
		await expect(p).toContainText('Klärung offen')
		await expect(field(dialog, 'Notiz zur Sperre')).toHaveText('Rückfrage beim Mitglied')

		// Entsperren: ebenfalls nur mit Notiz.
		await p.getByRole('button', { name: 'Entsperren' }).click()
		await expect(p.getByRole('button', { name: 'Mandat entsperren' })).toBeDisabled()
		await p.getByLabel('Notiz zur Klärung (Pflicht)').fill('Konto telefonisch bestätigt')
		await p.getByRole('button', { name: 'Mandat entsperren' }).click()
		await expect(status(dialog)).toHaveText('Aktiv')

		// Widerruf mit Reibungsdialog: Endgültigkeit, Ausweg „nur neues Konto“ als Primäraktion.
		await p.getByRole('button', { name: 'Mandat widerrufen' }).click()
		const revoke = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await expect(revoke).toContainText('endgültig')
		await expect(revoke.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' })).toBeVisible()
		await revoke.getByRole('button', { name: 'Mandat endgültig widerrufen' }).click()

		// Kein lebendes Mandat mehr: Störfall „neues Mandat einholen“, neues anlegbar, das alte steht unter „Frühere Mandate“.
		await expect(p).toContainText('neues Mandat einholen')
		await expect(p.getByRole('button', { name: 'Mandat anlegen' })).toBeVisible()
		const past = p.locator('details.vbh-mandate-past')
		await expect(past).toHaveCount(1)
		await expect(past.locator('summary')).toContainText('Widerrufen')

		// Die Ereignishistorie hält jeden Schritt fest: wer, wann, was.
		await past.locator('summary').click()
		await expect(past).toContainText('Mandat gesperrt: Rückfrage beim Mitglied')
		await expect(past).toContainText('Mandat entsperrt: Konto telefonisch bestätigt')
		await expect(past).toContainText('Mandat widerrufen')
		await expect(past).toContainText(`Verein (${USERS.buchhalter})`)

		// … und die API bestätigt das Ergebnis: widerrufen, nicht gelöscht.
		const [mandate] = await api.mandatesByMember(request, member.id)
		expect(mandate.status).toBe('erloschen')
		expect(mandate.endReason).toBe('widerrufen')
	})

	test('Reibungsdialog: Ausweg „nur neues Konto“ führt zur IBAN-Änderung, dasselbe Mandat bekommt ein Amendment', async ({ page, request }) => {
		const member = await createMember(request, 'Ina', 'Ibanwechsel')
		const created = await createActiveMandate(request, member)
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)
		await expect(status(dialog)).toHaveText('Aktiv')

		await p.getByRole('button', { name: 'Mandat widerrufen' }).click()
		const revoke = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await revoke.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' }).click()

		const account = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await expect(account).toBeVisible()
		// Vorbelegt mit der bisherigen IBAN – unverändert lässt sich nichts speichern.
		await expect(account.getByLabel('Neue IBAN')).toHaveValue(IBAN_GROUPED)
		await expect(account.getByRole('button', { name: 'IBAN ändern' })).toBeDisabled()
		await account.getByLabel('Neue IBAN').fill(IBAN_NEW)
		await account.getByRole('button', { name: 'IBAN ändern' }).click()

		await expect(field(dialog, 'IBAN')).toHaveText(IBAN_NEW_GROUPED)
		await expect(status(dialog)).toHaveText('Aktiv') // kein Widerruf, kein neues Mandat
		await expect(p).toContainText('Änderungen der Bankverbindung')
		await expect(p).toContainText(`Bisherige IBAN ${IBAN_GROUPED}`)
		await expect(p).toContainText('noch nicht an die Bank gemeldet')

		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].id).toBe(created.id)
		expect(mandates[0].iban).toBe(IBAN_NEW)
		const detail = await api.getJson(request, `/mandates/${created.id}`)
		expect(detail.amendments).toHaveLength(1)
		expect(detail.amendments[0].status).toBe('open')
		expect(detail.amendments[0].oldIban).toBe(IBAN)
	})

	test('Kontoinhaberwechsel legt ein neues Mandat an und beendet das alte als ersetzt; reine Namenskorrektur bleibt ohne Amendment', async ({ page, request }) => {
		const member = await createMember(request, 'Karl', 'Kontowechsel')
		const old = await createActiveMandate(request, member)
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)
		const account = page.getByRole('dialog', { name: 'Bankverbindung ändern' })

		// Reine Namenskorrektur: stille Korrektur, kein Amendment.
		await p.getByRole('button', { name: 'Bankverbindung ändern' }).click()
		await account.getByLabel('Derselbe Kontoinhaber, nur der Name war falsch geschrieben').check()
		await account.getByLabel('Kontoinhaber', { exact: true }).fill('Karl Kontowechsler')
		await account.getByRole('button', { name: 'Name korrigieren' }).click()
		await expect(field(dialog, 'Kontoinhaber')).toHaveText('Karl Kontowechsler')
		await expect(p).not.toContainText('Änderungen der Bankverbindung')
		expect((await api.getJson(request, `/mandates/${old.id}`)).amendments).toHaveLength(0)

		// Kontoinhaberwechsel: Reibungsdialog mit dem Ausweg, dann das neue Mandat.
		await p.getByRole('button', { name: 'Bankverbindung ändern' }).click()
		await account.getByLabel('Der Kontoinhaber wechselt (andere Person)').check()
		await expect(account.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' })).toBeVisible()
		await account.getByLabel('Neue IBAN').fill(IBAN_NEW)
		await account.getByLabel('Neuer Kontoinhaber').fill('Maria Neuinhaber')
		await account.getByRole('button', { name: 'Kontoinhaber wechseln' }).click()

		// Das neue Mandat ist ein Entwurf (ohne Unterschriftsdatum), das alte steht als ersetzt unter „Frühere Mandate“.
		await expect(status(dialog)).toHaveText('Entwurf')
		await expect(field(dialog, 'Kontoinhaber')).toHaveText('Maria Neuinhaber')
		await expect(p.locator('details.vbh-mandate-past summary')).toContainText('Ersetzt')

		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(2)
		expect(mandates[0].status).toBe('entwurf') // neueste zuerst
		expect(mandates[1].id).toBe(old.id)
		expect(mandates[1].status).toBe('erloschen')
		expect(mandates[1].endReason).toBe('ersetzt')
	})

	test('Elektronischer Entwurf: Status des Einmal-Links ist sichtbar, erneut senden liefert die Link-Adresse', async ({ page, request }) => {
		const member = await createMember(request, 'Elke', 'Einmallink')
		const created = await (await api.createElectronicMandate(request, { memberId: member.id, iban: IBAN })).json()

		// Noch kein Link verschickt.
		const dialog = await openMandate(page, member.displayName)
		const p = panel(dialog)
		await expect(status(dialog)).toHaveText('Entwurf')
		await expect(field(dialog, 'Einmal-Link')).toHaveText('noch nicht versendet')
		await expect(p).toContainText('Es wurde noch kein Einmal-Link verschickt')
		// Elektronische Entwürfe aktivieren sich per Zustimmung selbst – kein manuelles Gate.
		await expect(p.getByRole('button', { name: 'Aktivieren', exact: true })).toHaveCount(0)

		await p.getByRole('button', { name: 'Einmal-Link senden' }).click()
		await expect(field(dialog, 'Einmal-Link')).toContainText('gesendet am')
		await expect(field(dialog, 'Einmal-Link')).toContainText(member.email)
		await expect(field(dialog, 'Einmal-Link')).toContainText('gültig bis')
		await expect(p.getByRole('button', { name: 'Einmal-Link erneut senden' })).toBeVisible()
		// Für den Fall, dass die Mail nicht ankommt: die Adresse zum Weitergeben.
		await expect(p.getByLabel('Einmal-Link', { exact: true })).toHaveValue(/\/mandate-consent\//)

		const detail = await api.getJson(request, `/mandates/${created.id}`)
		expect(detail.activationLink.email).toBe(member.email)
		expect(detail.activationLink.expired).toBe(false)
	})

	test.describe('Nachweis', () => {
		// Die Nachweis-Ablage nutzt den Nutzer der Belegablage (MandateDocumentService) -
		// ohne ihn weist das Backend den Upload mit einer Meldung ab. Vorbedingung selbst
		// herstellen und danach zurücksetzen (/reset räumt die App-Config nicht).
		test.beforeAll(async ({ request }) => {
			await api.updateSettings(request, { storage_user: 'admin', storage_path: 'Vereinsbuchhaltung/Belege' })
		})

		test.afterAll(async ({ request }) => {
			await api.updateSettings(request, { storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
		})

		test('hochladen und herunterladen; die Zeile „Nachweis“ wechselt auf „vorhanden“; Formular druckfertig öffnen', async ({ page, request }) => {
			const member = await createMember(request, 'Nora', 'Nachweis')
			const mandate = await createActiveMandate(request, member)
			const dialog = await openMandate(page, member.displayName)
			const p = panel(dialog)

			await expect(field(dialog, 'Nachweis')).toHaveText('fehlt')
			await expect(p.getByRole('button', { name: 'Nachweis hochladen' })).toBeVisible()
			await expect(p.getByRole('link', { name: 'Nachweis herunterladen' })).toHaveCount(0)

			await p.locator('input[type="file"]').setInputFiles({ name: 'mandat.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 Mandat') })
			await expect(field(dialog, 'Nachweis')).toHaveText('vorhanden')
			await expect(p.getByRole('button', { name: 'Nachweis ersetzen' })).toBeVisible()

			const download = p.getByRole('link', { name: 'Nachweis herunterladen' })
			await expect(download).toBeVisible()
			const href = await download.getAttribute('href')
			const file = await page.request.get(href)
			expect(file.ok()).toBe(true)
			expect(await file.text()).toContain('%PDF-1.4 Mandat')

			// Das druckfertige Formular ist ein eigener Tab mit dem versionierten Rechtstext.
			const form = p.getByRole('link', { name: 'Mandatsformular öffnen' })
			await expect(form).toHaveAttribute('target', '_blank')
			const formPage = await page.request.get(await form.getAttribute('href'))
			expect(formPage.ok()).toBe(true)
			const html = await formPage.text()
			expect(html).toContain('SEPA-Lastschriftmandat')
			expect(html).toContain(mandate.mandateReference)
		})
	})

	test('Rollen: Revisor liest nur mit maskierter IBAN und hat weder Schreibaktionen noch die Mitglieder-Akte', async ({ page, request }) => {
		const member = await createMember(request, 'Rudi', 'Rollenprobe')
		const mandate = await createActiveMandate(request, member)

		// Lesen ab Revisor, IBAN maskiert (Spec §3.9) …
		const asRevisor = await api.mandatesByMember(request, member.id, { user: USERS.revisor })
		expect(asRevisor[0].iban).toMatch(/^DE12•+9890$/)
		const detail = await api.getJson(request, `/mandates/${mandate.id}`, { user: USERS.revisor })
		expect(detail.iban).toMatch(/^DE12•+9890$/)
		expect(Array.isArray(detail.history)).toBe(true)

		// … jede Schreibaktion der Akte sowie Formular und Nachweis dagegen ab Buchhalter.
		for (const [method, path, data] of [
			['POST', `/mandates/${mandate.id}/suspend`, { note: 'x' }],
			['POST', `/mandates/${mandate.id}/revoke`, {}],
			['POST', `/mandates/${mandate.id}/amend-bank-details`, { iban: IBAN_NEW }],
			['POST', `/mandates/${mandate.id}/correct-name`, { accountHolder: 'Anders' }],
			['POST', `/mandates/${mandate.id}/replace`, { iban: IBAN_NEW, accountHolder: 'Anders' }],
			['GET', `/mandates/${mandate.id}/form`, undefined],
			['GET', `/mandates/${mandate.id}/document`, undefined],
		]) {
			const resp = await api.raw(request, method, path, { user: USERS.revisor, expectOk: false, ...(data !== undefined ? { data } : {}) })
			expect(resp.status(), `${method} ${path}`).toBe(403)
		}
		const unchanged = await api.mandatesByMember(request, member.id)
		expect(unchanged[0].status).toBe('aktiv')

		// Oberfläche: der Mitglieder-Unterreiter (mit der Akte) bleibt für den Revisor unsichtbar –
		// gleichgültig, ob er den Reiter „Beiträge“ (nur Einzug, lesend) überhaupt zu sehen bekommt.
		await openApp(page, USERS.revisor)
		if (await tabButton(page, 'Beiträge').count()) {
			await switchTab(page, 'Beiträge')
			await expect(visibleSection(page).getByRole('button', { name: 'Mitglieder', exact: true })).toHaveCount(0)
		}
		await expect(page.locator('section.vbh-mandate-panel')).toHaveCount(0)
	})
})

test.describe('Mandat-Verwaltung auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('die Akte erreicht den Mandat-Bereich über die Karte, nichts läuft über den Rand', async ({ page, request }) => {
		const member = await createMember(request, 'Hanna', 'Handy')
		await createActiveMandate(request, member)

		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()
		const card = visibleSection(page).locator('.vbh-membercard', { hasText: member.displayName })
		await card.getByRole('button', { name: 'Aktionen' }).click()
		await page.getByRole('menuitem', { name: 'Mandat verwalten' }).click()

		const dialog = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
		await expect(status(dialog)).toHaveText('Aktiv')
		await expect(field(dialog, 'IBAN')).toHaveText(IBAN_GROUPED)
		await expect(panel(dialog).getByRole('button', { name: 'Mandat widerrufen' })).toBeVisible()

		// Kein horizontales Scrollen: weder die Seite noch der Mandat-Bereich ist breiter als der Bildschirm.
		const overflow = await panel(dialog).evaluate((el) => ({ panel: el.scrollWidth - el.clientWidth, page: document.documentElement.scrollWidth - window.innerWidth }))
		expect(overflow.panel).toBeLessThanOrEqual(0)
		expect(overflow.page).toBeLessThanOrEqual(0)
	})
})
