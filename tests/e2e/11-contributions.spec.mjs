import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Das Beitragsmodul im Überblick: Modul aktivieren, Mitglied mit Mandat und
// Beitragsgruppe, eine fällige Forderung ist zugleich ein offener Posten, und
// der Lastschriftlauf erzeugt daraus eine pain.008-Datei. Die einzelnen
// Stationen haben ihre tieferen Specs (23 Mitglieder, 24 Mandate, 25
// Beitragsgruppen, 30 und 41-44 Einzug); diese hier hält den Weg vom Mitglied
// bis zur Bankdatei in einem Zug zusammen.
//
// Seit dem Cutover (Issue #107) läuft er über das Mandats-/Zuweisungs-/
// Forderungsmodell: früher über das flache Alt-Modul (Mandat und Beitrag unter
// /sepa/mandates und /sepa/fees, „Nachholen“, Einzug unter /sepa/export). Der
// Alt-Weg „Rückstand nachholen“ hat keine Entsprechung – Forderungen entstehen
// aus dem Terminplan (Cron) oder als manuelle Einzelforderung.

const MEMBER = 'Erika Beispiel'
const GROUP_NAME = 'Jahresbeitrag Beitragsmodul'
const IBAN = 'DE02120300000000202051'

const today = () => new Date().toISOString().slice(0, 10)
/** Heute in 30 Tagen: ein Einzugstermin, der nie in der Vergangenheit liegt, unabhängig vom Tag des Testlaufs. */
const inThirtyDays = () => new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString().slice(0, 10)

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	// Das einziehende Konto braucht eine IBAN, sonst weist die
	// Einstellungs-Prüfung das Konto als SEPA-Einzugskonto ab.
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

async function ensureGroup(request) {
	const groups = await api.getJson(request, '/contribution-groups')
	return groups.find((g) => g.name === GROUP_NAME) ?? (await api.raw(request, 'POST', '/contribution-groups', {
		expectOk: true,
		data: { name: GROUP_NAME, minMonthlyAmount: 5, defaultMonthlyAmount: 5, allowedIntervals: [1, 3, 12], defaultInterval: 12, isActive: true },
	})).json()
}

/**
 * Das Mitglied samt aktivem Mandat und Jahresbeitrag (5 € im Monat, Turnus 12,
 * also 60 € je Periode) – nur anlegen, was noch fehlt: nach einem Fehlschlag
 * läuft beforeAll erneut, und /reset räumt die Mitglieder-Tabellen nicht ab.
 */
async function ensureMemberWithFee(request) {
	let member = (await api.listMembers(request)).find((m) => m.displayName === MEMBER)
	if (!member) {
		member = await api.createMember(request, { firstName: 'Erika', lastName: 'Beispiel', email: 'erika.beispiel@example.org' })
	}

	// Höchstens ein lebendes Mandat je Mitglied: einen übrig gebliebenen Entwurf
	// (Anlegen gelang, Aktivieren nicht) aktivieren statt ein zweites anzulegen.
	const mandates = await api.mandatesByMember(request, member.id)
	let mandate = mandates.find((m) => m.status === 'aktiv')
	if (!mandate) {
		mandate = mandates.find((m) => m.status === 'entwurf')
			?? await (await api.createMandate(request, { memberId: member.id, iban: IBAN, accountHolder: MEMBER, signedAt: '2026-01-15' })).json()
		await api.activateMandate(request, mandate.id, { signedAt: '2026-01-15' })
	}

	const assignments = await api.getJson(request, '/assignments')
	if (!assignments.some((a) => a.memberId === member.id)) {
		const group = await ensureGroup(request)
		await api.raw(request, 'POST', '/assignments', {
			expectOk: true,
			data: { memberId: member.id, groupId: group.id, intervalMonths: 12, monthlyAmount: 5, paymentMethod: 'direct_debit', validFrom: today() },
		})
	}
	return { member, mandate }
}

/** Eine neue Forderung über 60 € – jeder Aufruf legt eine eigene an, damit ein Wiederholungslauf nicht an einer schon eingereichten hängt. */
async function createClaim(request, memberId, dueDate) {
	const resp = await api.raw(request, 'POST', '/claims', {
		expectOk: true,
		data: { memberId, type: 'beitrag', amount: 60, label: `Jahresbeitrag ${Date.now()}`, dueDate },
	})
	return resp.json()
}

test.describe('Beiträge und SEPA-Lastschrift', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('Beiträge-Tab erscheint und zeigt das Mitglied mit Mandat und Beitrag', async ({ page, request }) => {
		await ensureMemberWithFee(request)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('table.vbh-table:visible tr', { hasText: MEMBER })
		await expect(row).toContainText(IBAN)
		await expect(row).toContainText('60,00')
		await expect(row).toContainText('jährlich')
	})

	test('eine fällige Forderung ist zugleich ein offener Posten', async ({ request }) => {
		const { member } = await ensureMemberWithFee(request)
		const claim = await createClaim(request, member.id, inThirtyDays())

		const items = await api.getJson(request, '/open-items')
		const item = items.find((i) => i.id === claim.id)
		expect(item).toBeTruthy()
		expect(item.debtor).toBe(MEMBER)
		expect(item.amount).toBe(60)
		expect(item.status).toBe('open')
	})

	test('die nächste Fälligkeit der Forderung steht in der Mitgliederliste', async ({ page, request }) => {
		const { member } = await ensureMemberWithFee(request)
		const dueDate = inThirtyDays()
		await createClaim(request, member.id, dueDate)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('table.vbh-table:visible tr', { hasText: MEMBER })
		// Spalte „Nächste Fälligkeit“ (die fünfte): alle Forderungen dieses Mitglieds in dieser
		// Spec haben denselben Termin, /reset hat die aus früheren Specs entfernt.
		await expect(row.locator('td').nth(4)).toHaveText(dueDate)
	})

	test('Der Lastschriftlauf erzeugt eine pain.008-Datei', async ({ request }) => {
		const { member } = await ensureMemberWithFee(request)
		const dueDate = inThirtyDays()
		const claim = await createClaim(request, member.id, dueDate)

		const preview = await api.getJson(request, `/debit-batches/preview?dueDate=${dueDate}`)
		expect(preview.claims.some((c) => c.id === claim.id)).toBe(true)

		const batch = await api.releaseDebitBatch(request, dueDate)
		expect(batch.status).toBe('freigegeben')

		const xmlResp = await api.raw(request, 'GET', `/debit-batches/${batch.id}/xml`)
		expect(xmlResp.status()).toBe(200)
		const xml = await xmlResp.text()
		expect(xml).toContain('pain.008')
		expect(xml).toContain(MEMBER)
		expect(xml).toContain('60.00')
	})
})
