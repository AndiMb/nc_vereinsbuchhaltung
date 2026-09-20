import { test, expect } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Freigabe & Einreichung des Einzugszyklus (Issue #71, Spec §2.2/§3.5): reine
// API-Specs wie 28-contribution-prenotification.spec.mjs - dieses Ticket hat
// bewusst keine eigene Oberflaeche (siehe PR-Beschreibung), die Faelle lassen
// sich vollstaendig ueber die REST-Schnittstelle pruefen.

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

/** Mitglied mit aktivem Mandat und einer manuellen Einzelforderung zu `dueDate`. */
async function memberWithClaimDue(request, { firstName, lastName, email, iban, dueDate, amount = 12.5 }) {
	const member = await api.createMember(request, { firstName, lastName, email })
	const mandateResp = await api.createMandate(request, { memberId: member.id, iban, signedAt: '2024-01-01' })
	const mandate = await mandateResp.json()
	await api.activateMandate(request, mandate.id)

	const claimResp = await api.raw(request, 'POST', '/claims', {
		data: { memberId: member.id, type: 'beitrag', amount, label: 'Testbeitrag Einzugslauf', dueDate },
	})
	expect(claimResp.ok()).toBeTruthy()
	const claim = await claimResp.json()

	return { member, mandate, claim }
}

test.describe('Lastschriftlauf: Freigabe & Einreichung', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('Vorschau, Freigabe, maskierte IBAN und Einreichung setzen last_presented_due_date', async ({ request }) => {
		const dueDate = '2026-11-01'
		const { mandate, claim } = await memberWithClaimDue(request, {
			firstName: 'Katrin', lastName: 'Einzug1', email: 'katrin.einzug1@example.org',
			iban: 'DE02120300000000202051', dueDate,
		})

		// Vor der Freigabe existiert kein Lauf-Datensatz - die Vorschau ist eine
		// reine Abfrage (Spec §3.5).
		const preview = await api.getJson(request, `/debit-batches/preview?dueDate=${dueDate}`)
		expect(preview.claims.some((c) => c.id === claim.id)).toBeTruthy()
		expect(preview.summary.count).toBeGreaterThanOrEqual(1)

		const releaseResp = await api.raw(request, 'POST', '/debit-batches', { data: { dueDate } })
		expect(releaseResp.ok()).toBeTruthy()
		const batch = await releaseResp.json()
		expect(batch.status).toBe('freigegeben')
		expect(batch.dueDate).toBe(dueDate)
		expect(batch.itemCount).toBeGreaterThanOrEqual(1)
		expect(batch.msgId).toBeTruthy()

		// Freigegeben: die Forderung ist jetzt aus einer erneuten Vorschau raus
		// ("hoechstens ein Einzugsposten je Forderung", Spec §2.2).
		const previewAfterRelease = await api.getJson(request, `/debit-batches/preview?dueDate=${dueDate}`)
		expect(previewAfterRelease.claims.some((c) => c.id === claim.id)).toBeFalsy()

		// Einzug-Unterreiter: IBAN maskiert, unabhaengig von der Rolle (Spec §3.9).
		const details = await api.getJson(request, `/debit-batches/${batch.id}`)
		const item = details.items.find((i) => i.openItemId === claim.id)
		expect(item).toBeTruthy()
		expect(item.iban).toContain('•')
		expect(item.iban).not.toBe('DE02120300000000202051')
		expect(item.sequenceType).toBe('RCUR')
		expect(item.amendmentIndicator).toBe(false)

		// Die XML-Datei traegt dagegen die volle IBAN (Bank-Einreichungsdatei).
		const xmlResp = await api.raw(request, 'GET', `/debit-batches/${batch.id}/xml`)
		expect(xmlResp.ok()).toBeTruthy()
		const xml = await xmlResp.text()
		expect(xml).toContain('DE02120300000000202051')
		expect(xml).toContain('RCUR')
		expect(xml).toContain(batch.msgId)

		// Schritt 2: Einreichung setzt last_presented_due_date am Mandat.
		const submitResp = await api.raw(request, 'POST', `/debit-batches/${batch.id}/submit`)
		expect(submitResp.ok()).toBeTruthy()
		const submitted = await submitResp.json()
		expect(submitted.status).toBe('eingereicht')

		const mandateAfter = await api.getJson(request, `/mandates/${mandate.id}`)
		expect(mandateAfter.lastPresentedDueDate).toBe(dueDate)

		// Kein Storno nach Einreichung (Spec §3.5).
		const discardAfterSubmit = await api.raw(request, 'POST', `/debit-batches/${batch.id}/discard`, {
			expectOk: false,
			data: { reason: 'zu spaet' },
		})
		expect(discardAfterSubmit.status()).toBe(400)
	})

	test('Verworfener Lauf behält die Historie und gibt die Forderung wieder frei', async ({ request }) => {
		const dueDate = '2026-11-08'
		const { claim } = await memberWithClaimDue(request, {
			firstName: 'Jonas', lastName: 'Einzug2', email: 'jonas.einzug2@example.org',
			iban: 'DE44500105175407324931', dueDate,
		})

		const batch = await (await api.raw(request, 'POST', '/debit-batches', { data: { dueDate } })).json()
		const firstEndToEndId = (await api.getJson(request, `/debit-batches/${batch.id}`)).items[0].endToEndId

		const discardResp = await api.raw(request, 'POST', `/debit-batches/${batch.id}/discard`, {
			data: { reason: 'Falsches Faelligkeitsdatum erwischt' },
		})
		expect(discardResp.ok()).toBeTruthy()
		const discarded = await discardResp.json()
		expect(discarded.status).toBe('verworfen')
		expect(discarded.discardReason).toBe('Falsches Faelligkeitsdatum erwischt')

		// Die Forderung ist wieder frei fuer einen neuen Lauf.
		const previewAfterDiscard = await api.getJson(request, `/debit-batches/preview?dueDate=${dueDate}`)
		expect(previewAfterDiscard.claims.some((c) => c.id === claim.id)).toBeTruthy()

		const secondBatch = await (await api.raw(request, 'POST', '/debit-batches', { data: { dueDate } })).json()
		const secondEndToEndId = (await api.getJson(request, `/debit-batches/${secondBatch.id}`)).items[0].endToEndId
		// EndToEndIds werden nie wiederverwendet (Spec §3.5).
		expect(secondEndToEndId).not.toBe(firstEndToEndId)

		// Der verworfene Lauf selbst bleibt als Historie stehen.
		const stillThere = await api.getJson(request, `/debit-batches/${batch.id}`)
		expect(stillThere.status).toBe('verworfen')
	})

	test('Termin nur nach hinten verschiebbar', async ({ request }) => {
		const dueDate = '2026-11-15'
		await memberWithClaimDue(request, {
			firstName: 'Petra', lastName: 'Einzug3', email: 'petra.einzug3@example.org',
			iban: 'DE68210501700012345678', dueDate,
		})
		const batch = await (await api.raw(request, 'POST', '/debit-batches', { data: { dueDate } })).json()

		const forward = await api.raw(request, 'POST', `/debit-batches/${batch.id}/reschedule`, { data: { dueDate: '2026-11-22' } })
		expect(forward.ok()).toBeTruthy()
		expect((await forward.json()).dueDate).toBe('2026-11-22')

		const backward = await api.raw(request, 'POST', `/debit-batches/${batch.id}/reschedule`, {
			expectOk: false,
			data: { dueDate: '2026-11-01' },
		})
		expect(backward.status()).toBe(400)
	})

	test('Freigabe/Einreichung ist Buchhalter vorbehalten, Lesen geht auch als Revisor', async ({ request }) => {
		const dueDate = '2026-11-29'
		await memberWithClaimDue(request, {
			firstName: 'Tom', lastName: 'Einzug4', email: 'tom.einzug4@example.org',
			iban: 'DE87200100200210953411', dueDate,
		})

		const releaseAsRevisor = await api.raw(request, 'POST', '/debit-batches', {
			user: USERS.revisor,
			expectOk: false,
			data: { dueDate },
		})
		expect(releaseAsRevisor.status()).toBe(403)

		const readAsRevisor = await api.raw(request, 'GET', `/debit-batches/preview?dueDate=${dueDate}`, { user: USERS.revisor })
		expect(readAsRevisor.ok()).toBeTruthy()

		const readIndexAsRevisor = await api.raw(request, 'GET', '/debit-batches', { user: USERS.revisor })
		expect(readIndexAsRevisor.ok()).toBeTruthy()
	})
})
