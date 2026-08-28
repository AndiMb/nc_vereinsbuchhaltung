import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Das Beitragsmodul: Modul aktivieren, Mitglied mit Mandat und Beitrag,
// fällige Beiträge werden offene Posten, und der SEPA-Sammeleinzug
// erzeugt daraus eine pain.008-Datei.

const MEMBER = 'Erika Beispiel'

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	// Das einziehende Konto braucht eine IBAN, sonst weist die
	// Einstellungs-Prüfung das Konto als SEPA-Einzugskonto ab.
	await api.raw(request, 'PUT', `/accounts/${bank.id}`, {
		data: { iban: 'DE12500105170648489890' },
		expectOk: true,
	})
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

/** Mitglied samt Mandat und Jahresbeitrag – nur anlegen, was noch fehlt. */
async function ensureMemberWithFee(request) {
	let mandate = (await api.getJson(request, '/sepa/mandates')).find((m) => m.memberLabel === MEMBER)
	if (!mandate) {
		mandate = await (await api.raw(request, 'POST', '/sepa/mandates', {
			expectOk: true,
			data: {
				memberUid: null,
				memberLabel: MEMBER,
				iban: 'DE02120300000000202051',
				bic: null,
				mandateType: 'RCUR',
				signedDate: '2026-01-15',
			},
		})).json()
	}

	let fee = (await api.getJson(request, '/sepa/fees')).find((f) => f.memberLabel === MEMBER)
	if (!fee) {
		const income = await api.accountByNumber(request, INCOME_ACCOUNT)
		fee = await (await api.raw(request, 'POST', '/sepa/fees', {
			expectOk: true,
			data: {
				memberUid: null,
				memberLabel: MEMBER,
				amount: 60,
				frequency: 'yearly',
				startDate: '2026-01-01',
				accountId: income.id,
				mandateId: mandate.id,
			},
		})).json()
	}
	return { mandate, fee }
}

test.describe('Beiträge und SEPA-Lastschrift', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('Beiträge-Tab erscheint und zeigt das Mitglied', async ({ page, request }) => {
		await ensureMemberWithFee(request)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Beiträge')
		await expect(visibleSection(page).getByText(MEMBER).first()).toBeVisible()
	})

	test('fällige Beiträge werden offene Posten (Aufholen)', async ({ request }) => {
		const { fee } = await ensureMemberWithFee(request)

		const resp = await api.raw(request, 'POST', `/sepa/fees/${fee.id}/catch-up`)
		expect(resp.status()).toBe(200)

		const items = await api.getJson(request, '/open-items')
		expect(items.some((i) => i.debtor === MEMBER)).toBe(true)
	})

	test('SEPA-Sammeleinzug erzeugt eine pain.008-Datei', async ({ request }) => {
		const { fee } = await ensureMemberWithFee(request)
		await api.raw(request, 'POST', `/sepa/fees/${fee.id}/catch-up`)

		const preview = await api.getJson(request, '/sepa/export/preview')
		expect(preview.rows.length).toBeGreaterThan(0)

		const created = await api.raw(request, 'POST', '/sepa/export/batches', {
			data: { executionDate: preview.executionDate },
		})
		expect(created.status()).toBe(201)
		const batch = await created.json()

		const xmlResp = await api.raw(request, 'GET', `/sepa/export/batches/${batch.id}/xml`)
		expect(xmlResp.status()).toBe(200)
		const xml = await xmlResp.text()
		expect(xml).toContain('pain.008')
		expect(xml).toContain(MEMBER)
		expect(xml).toContain('60.00')
	})
})
