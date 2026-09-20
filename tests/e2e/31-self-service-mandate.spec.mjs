import { expect, test } from '@playwright/test'
import { api, openApp, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Self-Service-Mandats-Aktionskatalog (Issue #75, Spec §3.4): UI-Tests für
// die drei Reibungspunkte, die dieses Ticket einführt - elektronische
// Erteilung direkt in der Self-Service-Sitzung (kein E-Mail-Umweg mehr nötig,
// anders als 27-electronic-mandate.spec.mjs), Kontoinhaberwechsel-Dialog
// (Ausweg = Umschalten auf den IBAN-Modus, ersetzt einen separaten
// Dialogwechsel), Widerruf-Dialog mit Ausweg-Option. Setup wie
// 26-self-service.spec.mjs (self_service_enabled, Kontoverknüpfung über
// USERS.ohneRolle) - anders als dort verknüpft dieser Spec für JEDEN
// Testfall ein FRISCHES Mitglied: "höchstens ein lebendes Mandat je
// Mitglied" (Spec §2.2) braucht für Kontoinhaberwechsel/Widerruf einen
// bereits aktiven Ausgangszustand, den ein vorheriger Testfall (der ja
// gerade ein Mandat erteilt oder widerrufen hat) sonst schon verbraucht
// hätte.
//
// Kontoinhaberwechsel/Widerruf setzen ihren Ausgangszustand über ein PAPIER-
// Mandat auf (api.createMandate + api.activateMandate) statt über den
// elektronischen Weg: die betroffenen Zustandsprüfungen
// (MandateStateMachine::assertCanAmend()/assertCanReplace()/assertCanRevoke())
// verlangen nur den Status, nicht den Erteilungsweg - das spart den Umweg
// über den öffentlichen Einmal-Link, den 27-electronic-mandate.spec.mjs
// bereits abdeckt.

/**
 * Erfolgs-Toast (showSuccess). Der Toast steht doppelt im DOM: als sichtbares
 * Toast-Element und als Bildschirmleser-Ansage "Erfolg: <Text>" in einer
 * Live-Region - ein Teilstring-Locator träfe beide (strict mode violation),
 * deshalb der exakte Text.
 */
function successToast(page, text) {
	return page.getByText(text, { exact: true })
}

/** Löst eine evtl. bestehende Verknüpfung von USERS.ohneRolle und verknüpft stattdessen ein frisches Mitglied. */
async function linkFreshMember(request, memberData) {
	const members = await api.listMembers(request)
	const existing = members.find((m) => m.ncUserId === USERS.ohneRolle)
	if (existing) {
		await api.unlinkMember(request, existing.id)
	}
	const created = await api.createMember(request, memberData)
	await api.linkMember(request, created.id, USERS.ohneRolle)
	return created.id
}

/** Aktives Papier-Mandat als Ausgangszustand - der Erteilungsweg ist für IBAN-Änderung/Kontoinhaberwechsel/Widerruf irrelevant (siehe Dateikopf). */
async function activePaperMandate(request, memberId, iban, accountHolder) {
	const created = await api.createMandate(request, { memberId, iban, accountHolder, signedAt: '2026-01-01' })
	const mandate = await created.json()
	await api.activateMandate(request, mandate.id, { signedAt: '2026-01-01' })
	return mandate
}

test.describe('Self-Service: Mandats-Aktionskatalog (Issue #75)', () => {
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

	test('Mandat erfassen und elektronisch erteilen, direkt in der Self-Service-Sitzung', async ({ page, request }) => {
		await linkFreshMember(request, { memberType: 'person', firstName: 'Nora', lastName: 'Fischer', email: 'nora.fischer@example.org' })

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Beitrag').click()
		const section = visibleSection(page)

		await expect(section.getByText('Kein Mandat hinterlegt.')).toBeVisible()
		await section.getByRole('button', { name: 'Mandat jetzt erteilen' }).click()

		const dialog = page.getByRole('dialog', { name: 'Mandat erfassen und erteilen' })
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('IBAN', { exact: true }).fill('DE89370400440532013000')
		await dialog.getByLabel('Kontoinhaber', { exact: true }).fill('Nora Fischer')
		// Pflicht-UI "Vorschau vor jedem Speichern" (Spec §3.4): der Mandatstext
		// ist Teil desselben Dialogs, keine separate Fläche.
		// Gezielt der geladene Rechtstext, nicht irgendein Text mit dem Wort: der
		// Vorschau-Hinweis im Dialog nennt "SEPA-Lastschriftmandat" ebenfalls -
		// ein Textlocator wäre je nach Ladezustand einmal eindeutig, einmal
		// eine strict mode violation.
		const legalText = dialog.locator('.vbh-mandate-legaltext')
		await expect(legalText).toContainText('SEPA-Lastschriftmandat')
		await expect(legalText).not.toContainText('vbh:rahmen')
		await dialog.getByRole('button', { name: 'Jetzt erteilen' }).click()

		await expect(successToast(page, 'Mandat erteilt.')).toBeVisible()
		await expect(section.getByText('Aktiv')).toBeVisible()
		// IBAN immer maskiert (Spec §3.4 Pflicht-UI) - die volle IBAN darf nach
		// der Erteilung nirgends auf der Seite auftauchen, nur maskiert
		// (Mandate::maskedIban(): erste/letzte 4 Stellen, Rest durch • ersetzt).
		await expect(page.getByText('DE89370400440532013000')).toHaveCount(0)
		await expect(section.getByText(/DE89•+3000/)).toBeVisible()
	})

	test('Kontoinhaberwechsel: Ausweg schaltet auf den IBAN-Modus zurück, die Bestätigung erteilt ein neues Mandat', async ({ page, request }) => {
		const memberId = await linkFreshMember(request, { memberType: 'person', firstName: 'Otto', lastName: 'Weber' })
		await activePaperMandate(request, memberId, 'DE12500105170648489890', 'Otto Weber')

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Beitrag').click()
		const section = visibleSection(page)
		await expect(section.getByText('Aktiv')).toBeVisible()

		await section.getByRole('button', { name: 'Kontoinhaber wechseln' }).click()
		const dialog = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await expect(dialog).toBeVisible()

		await dialog.getByRole('radio', { name: 'Der Kontoinhaber wechselt' }).check()
		await expect(dialog.getByText('Das bisherige Mandat wird endgültig beendet', { exact: false })).toBeVisible()

		// Ausweg als Primäraktion (Spec §3.4): schaltet zurück auf den
		// IBAN-Modus, statt das Mandat zu ersetzen.
		await dialog.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' }).click()
		await expect(dialog.getByRole('radio', { name: 'Gleiches Konto, nur die IBAN hat sich geändert' })).toBeChecked()

		// Tatsächlicher Kontoinhaberwechsel, erneut in den Modus gewechselt.
		await dialog.getByRole('radio', { name: 'Der Kontoinhaber wechselt' }).check()
		await dialog.getByLabel('Neue IBAN', { exact: true }).fill('DE89370400440532013000')
		await dialog.getByLabel('Neuer Kontoinhaber', { exact: true }).fill('Neue Person')
		await dialog.getByRole('button', { name: 'Kontoinhaber wechseln' }).click()

		await expect(successToast(page, 'Kontoinhaber gewechselt, neues Mandat erteilt.')).toBeVisible()
		await expect(section.getByText('Neue Person')).toBeVisible()
	})

	test('Widerruf-Dialog: Ausweg-Option verhindert den Widerruf, der eigentliche Widerruf bleibt endgültig', async ({ page, request }) => {
		const memberId = await linkFreshMember(request, { memberType: 'person', firstName: 'Petra', lastName: 'Klein' })
		await activePaperMandate(request, memberId, 'DE44500105175407324931', 'Petra Klein')

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Beitrag').click()
		const section = visibleSection(page)

		await section.getByRole('button', { name: 'Mandat widerrufen' }).click()
		const revokeDialog = page.getByRole('dialog', { name: 'Mandat widerrufen' })
		await expect(revokeDialog).toBeVisible()
		await expect(revokeDialog.getByText('Der Widerruf ist endgültig', { exact: false })).toBeVisible()

		// Ausweg als Primäraktion (Spec §3.4): öffnet den Konto-Dialog im
		// IBAN-Modus, OHNE zu widerrufen.
		await revokeDialog.getByRole('button', { name: 'Ich habe nur ein neues Konto → IBAN ändern' }).click()
		const accountDialog = page.getByRole('dialog', { name: 'Bankverbindung ändern' })
		await expect(accountDialog).toBeVisible()
		await expect(accountDialog.getByRole('radio', { name: 'Gleiches Konto, nur die IBAN hat sich geändert' })).toBeChecked()
		await accountDialog.getByRole('button', { name: 'Abbrechen' }).click()

		// Ausgangszustand unverändert - kein Widerruf durch den Ausweg ausgelöst.
		await expect(section.getByText('Aktiv')).toBeVisible()

		// Derselbe Dialog, diesmal tatsächlich widerrufen - keine
		// Zweitfaktor-Bestätigung (Spec §3.4 "Widerruf ist ein Recht").
		await section.getByRole('button', { name: 'Mandat widerrufen' }).click()
		await page.getByRole('dialog', { name: 'Mandat widerrufen' }).getByRole('button', { name: 'Mandat endgültig widerrufen' }).click()

		await expect(successToast(page, 'Mandat widerrufen.')).toBeVisible()
		await expect(section.getByText('Kein Mandat hinterlegt.')).toBeVisible()
	})
})
