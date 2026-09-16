import { expect, test } from '@playwright/test'
import { api, BASE_URL, USERS } from './fixtures/nextcloud.mjs'

// Elektronische Mandatserteilung per E-Mail-Einmal-Link (Issue #67, Spec
// §2.2/§8): der zweite Aktivierungsweg neben dem Papier-Gate aus #66. Es gibt
// für das neue Mandats-Datenmodell noch keine Oberfläche (siehe
// 24-mandate-lifecycle.spec.mjs) - dieselbe API-first-Vorgehensweise, mit
// EINER echten UI-Fläche: der öffentlichen, login-losen Zustimmungsseite
// selbst. Die verschickte Mail lässt sich in dieser Suite nicht mitlesen (kein
// Mail-Catcher eingerichtet) - MandateController::sendActivationLink() liefert
// die volle Aktivierungs-URL deshalb bewusst auch im Response an die
// berechtigte, aufrufende Person zurück (siehe Klassendoc dort); das ist die
// Grundlage für diesen Test.

test.describe('Elektronische Mandatserteilung', () => {
	let memberOhneKontoId
	let memberSelfServiceId

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)

		const memberOhneKonto = await api.createMember(request, {
			memberType: 'person',
			firstName: 'Lena',
			lastName: 'Fischer',
			email: 'lena.fischer@example.org',
		})
		memberOhneKontoId = memberOhneKonto.id
		expect(memberOhneKontoId).toBeGreaterThan(0)

		// Für den Self-Service-Kanal: ein Mitglied, verknüpft mit einem
		// bestehenden Testkonto (dasselbe Muster wie 26-self-service.spec.mjs).
		await api.updateSettings(request, { self_service_enabled: '1' })
		const memberSelfService = await api.createMember(request, {
			memberType: 'person',
			firstName: 'Otto',
			lastName: 'Weber',
			email: 'otto.weber@example.org',
		})
		memberSelfServiceId = memberSelfService.id
		await api.linkMember(request, memberSelfServiceId, USERS.ohneRolle)
	})

	test.afterAll(async ({ request }) => {
		await api.unlinkMember(request, memberSelfServiceId, { expectOk: false })
		await api.updateSettings(request, { self_service_enabled: '0' })
	})

	test('Mitglied OHNE NC-Konto: elektronischer Entwurf, Versand, Zustimmung über den öffentlichen Link', async ({ request, page }) => {
		const created = await api.createElectronicMandate(request, {
			memberId: memberOhneKontoId,
			iban: 'DE89370400440532013000',
			bic: 'COBADEFFXXX',
		})
		expect(created.status()).toBe(201)
		const mandate = await created.json()
		expect(mandate.signatureType).toBe('elektronisch')
		expect(mandate.status).toBe('entwurf')
		expect(mandate.signedAt).toBeNull() // keine Unterschrift vor der Zustimmung

		const sent = await api.sendActivationLink(request, mandate.id)
		expect(sent.status()).toBe(200)
		const { activationUrl, sentTo } = await sent.json()
		expect(sentTo).toBe('lena.fischer@example.org')
		expect(activationUrl).toContain('/mandate-consent/')
		// Der Token selbst steht in der URL (das ist sein Zweck als
		// Bearer-Credential) - Bankdaten dürfen dort NIRGENDS auftauchen.
		expect(activationUrl).not.toContain('DE89370400440532013000')

		// Die Seite ist bewusst OHNE Login erreichbar - ein frischer,
		// nicht angemeldeter Browser-Kontext (page startet in diesem Test ohne
		// vorherigen login()/openApp()-Aufruf, siehe Spec-Kopf).
		await page.goto(activationUrl)
		await expect(page.getByText('SEPA-Lastschriftmandat', { exact: false }).first()).toBeVisible()
		await expect(page.getByText('Lena Fischer', { exact: false })).toBeVisible()
		await expect(page.getByText('DE89370400440532013000', { exact: false })).toBeVisible()

		const submit = page.getByRole('button', { name: 'Ich stimme zu und erteile das Mandat' })
		await expect(submit).toBeVisible()
		await submit.click()

		// Nach der Zustimmung zeigt dieselbe Seite den bestätigten Zustand -
		// kein Redirect auf eine andere, ggf. geschützte Fläche nötig.
		await expect(page.getByText('Bereits bestätigt', { exact: false })).toBeVisible()

		const afterConsent = await api.getJson(request, `/mandates/${mandate.id}`)
		expect(afterConsent.status).toBe('aktiv')
		expect(afterConsent.isCollectible).toBe(true)
		expect(afterConsent.consentActor).toBe('lena.fischer@example.org')
		expect(afterConsent.consentIp).toBeTruthy()
		expect(afterConsent.consentUserAgent).toBeTruthy()
		expect(afterConsent.mandateTextVersion).toBeTruthy()
		expect(afterConsent.signedAt).toBeTruthy() // die Zustimmung selbst wurde zur Unterschrift

		// Erneuter Aufruf desselben (jetzt verbrauchten) Links: weiterhin
		// "bereits bestätigt", kein Fehler, keine zweite Aktivierung.
		await page.goto(activationUrl)
		await expect(page.getByText('Bereits bestätigt', { exact: false })).toBeVisible()
	})

	test('Ungültiger Link zeigt eine Fehlerseite statt eines Serverfehlers', async ({ page }) => {
		const response = await page.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/mandate-consent/nichtvorhanden.garnichtvorhanden`)
		expect(response.status()).toBe(404)
		await expect(page.getByText('Link ungültig', { exact: false })).toBeVisible()
	})

	test('Nur Buchhalter/Verwalter dürfen einen Aktivierungslink versenden', async ({ request }) => {
		// Eigenes, frisches Mitglied statt memberOhneKontoId wiederzuverwenden:
		// das hat nach dem ersten Test bereits ein LEBENDES (jetzt aktives)
		// Mandat, ein zweites ließe sich gar nicht mehr anlegen (Spec §2.2,
		// höchstens ein lebendes Mandat je Mitglied).
		const member = await api.createMember(request, { memberType: 'person', firstName: 'Rollen', lastName: 'Test' })
		const created = await api.createElectronicMandate(request, { memberId: member.id, iban: 'DE12500105170648489890' })
		expect(created.status()).toBe(201)
		const mandate = await created.json()

		const asRevisor = await api.sendActivationLink(request, mandate.id, { user: USERS.revisor, expectOk: false })
		expect(asRevisor.status()).toBe(403)
	})

	test('Self-Service-Kanal: verknüpftes Mitglied fordert seinen eigenen Aktivierungslink an', async ({ request, page }) => {
		const created = await api.createElectronicMandate(request, {
			memberId: memberSelfServiceId,
			iban: 'DE44500105175407324931',
		})
		expect(created.status()).toBe(201)
		const mandate = await created.json()

		const requested = await api.requestOwnMandateLink(request, { user: USERS.ohneRolle })
		expect(requested.status()).toBe(200)
		const { activationUrl, sentTo } = await requested.json()
		expect(sentTo).toBe('otto.weber@example.org')

		await page.goto(activationUrl)
		await expect(page.getByText('Otto Weber', { exact: false })).toBeVisible()
		await page.getByRole('button', { name: 'Ich stimme zu und erteile das Mandat' }).click()
		await expect(page.getByText('Bereits bestätigt', { exact: false })).toBeVisible()

		const afterConsent = await api.getJson(request, `/mandates/${mandate.id}`)
		expect(afterConsent.status).toBe('aktiv')
	})

	test('Fremdes, nicht-elektronisches Mitglied darf keinen Self-Service-Link anfordern', async ({ request }) => {
		// USERS.buchhalter hat kein verknüpftes Mitglied - die Middleware
		// verweigert bereits den gesamten SelfController (Spec §3.4), nicht erst
		// diese Methode.
		const resp = await api.requestOwnMandateLink(request, { user: USERS.buchhalter, expectOk: false })
		expect(resp.status()).toBe(403)
	})
})
