import { test, expect } from '@playwright/test'
import { api, USERS } from './fixtures/nextcloud.mjs'

// Mandats-Lifecycle-Kern, Papier-Weg (Issue #66, Spec §2.2/§3.2): Entwurf ->
// aktivieren -> sperren -> entsperren, plus die Rollenprüfung aus Spec §3.9
// (nur Buchhalter aktiviert/sperrt/entsperrt, Revisor liest nur mit
// maskierter IBAN). Es gibt für dieses neue Datenmodell (Member/Mandate,
// getrennt vom alten vbh_sepa_mandates-Bestand) noch keine Oberfläche –
// dieselbe API-first-Vorgehensweise wie in 08-roles.spec.mjs für reine
// Rollenprüfungen, hier für den kompletten Ablauf: „geprüft wird über die
// echte HTTP-API einer laufenden Instanz mit echter Rechteprüfung", nicht
// nur mit gemockten Unit-Tests.

test.describe('Mandats-Lifecycle (Papier-Weg)', () => {
	let memberId
	let mandateId

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		// api.createMember() (Issue #65) liefert das Mitglied bereits als
		// geparstes JSON, kein Response-Objekt - anders als die meisten
		// anderen api.*-Helfer in dieser Datei.
		const member = await api.createMember(request, {
			memberType: 'person',
			firstName: 'Katrin',
			lastName: 'Brunner',
			email: 'katrin.brunner@example.org',
		})
		memberId = member.id
		expect(memberId).toBeGreaterThan(0)
	})

	test('Anlegen (Papier) startet als Entwurf, nicht einzugsfähig', async ({ request }) => {
		const resp = await api.createMandate(request, {
			memberId,
			iban: 'DE12500105170648489890',
			bic: 'INGDDEFFXXX',
			signedAt: '2026-01-15',
		})
		expect(resp.status()).toBe(201)
		const mandate = await resp.json()

		expect(mandate.status).toBe('entwurf')
		expect(mandate.isCollectible).toBe(false)
		expect(mandate.accountHolder).toBe('Katrin Brunner') // vorbefüllt mit dem Anzeigenamen
		expect(mandate.sequenceType).toBe('RCUR')
		expect(mandate.storyText).toBe('Unterschrift fehlt')

		mandateId = mandate.id
	})

	test('Ein zweites lebendes Mandat für dasselbe Mitglied ist nicht erlaubt', async ({ request }) => {
		const resp = await api.createMandate(request, {
			memberId,
			iban: 'DE89370400440532013000',
			expectOk: false,
		})
		expect(resp.status()).toBe(400)
	})

	test('Revisor sieht das Mandat nur mit maskierter IBAN', async ({ request }) => {
		const mandates = await api.mandatesByMember(request, memberId, { user: USERS.revisor })
		expect(mandates).toHaveLength(1)
		expect(mandates[0].iban).not.toBe('DE12500105170648489890')
		expect(mandates[0].iban).toMatch(/^DE12•+9890$/)
	})

	test('Revisor darf ein Mandat weder aktivieren noch anlegen (403)', async ({ request }) => {
		const activateAsRevisor = await api.activateMandate(request, mandateId, { user: USERS.revisor, expectOk: false })
		expect(activateAsRevisor.status()).toBe(403)

		const createAsRevisor = await api.createMandate(request, { memberId, iban: 'DE89370400440532013000', user: USERS.revisor, expectOk: false })
		expect(createAsRevisor.status()).toBe(403)
	})

	test('Aktivieren durch Buchhalter macht das Mandat einzugsfähig', async ({ request }) => {
		const resp = await api.activateMandate(request, mandateId, { user: USERS.buchhalter })
		expect(resp.status()).toBe(200)
		const mandate = await resp.json()

		expect(mandate.status).toBe('aktiv')
		expect(mandate.isCollectible).toBe(true)
		expect(mandate.activatedAt).toBeTruthy()
		expect(mandate.iban).toBe('DE12500105170648489890') // Buchhalter sieht die volle IBAN
	})

	test('Sperren verlangt eine Notiz und setzt den Zustand auf ausgesetzt', async ({ request }) => {
		const ohneNotiz = await api.suspendMandate(request, mandateId, { note: '', user: USERS.buchhalter, expectOk: false })
		expect(ohneNotiz.status()).toBe(400)

		const resp = await api.suspendMandate(request, mandateId, { note: 'Rückfrage bei der Bank offen', user: USERS.buchhalter })
		expect(resp.status()).toBe(200)
		const mandate = await resp.json()

		expect(mandate.status).toBe('ausgesetzt')
		expect(mandate.isCollectible).toBe(false)
		expect(mandate.suspensionNote).toBe('Rückfrage bei der Bank offen')
		expect(mandate.suspensionOrigin).toBe('manuell')
		expect(mandate.storyText).toBe('Klärung offen')
	})

	test('Entsperren durch Buchhalter setzt das Mandat wieder auf aktiv', async ({ request }) => {
		const resp = await api.resumeMandate(request, mandateId, { user: USERS.buchhalter })
		expect(resp.status()).toBe(200)
		const mandate = await resp.json()

		expect(mandate.status).toBe('aktiv')
		expect(mandate.isCollectible).toBe(true)
		expect(mandate.suspensionNote).toBeNull()
		expect(mandate.suspendedAt).toBeNull()
	})

	test('Widerruf ist terminal – ein widerrufenes Mandat lässt sich nicht reaktivieren', async ({ request }) => {
		const revoked = await api.revokeMandate(request, mandateId, { user: USERS.buchhalter })
		expect(revoked.status()).toBe(200)
		const mandate = await revoked.json()
		expect(mandate.status).toBe('erloschen')
		expect(mandate.endReason).toBe('widerrufen')
		expect(mandate.storyText).toBe('neues Mandat einholen')

		const resumeAttempt = await api.resumeMandate(request, mandateId, { user: USERS.buchhalter, expectOk: false })
		expect(resumeAttempt.status()).toBe(400)
	})
})
