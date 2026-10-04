import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, tabButton, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Generische Offene-Posten-Wege und die Regeln der Forderungen (Issue #121,
// Spec §2.2/§3.6/§11): Beitragsforderungen liegen in derselben Tabelle wie die
// freien Posten (Handwerkerrechnung u. Ä.), ihre Regeln setzt aber nur der
// ClaimService im Einzug-Reiter durch (Erledigungsvermerk mit Urheber, Storno
// nur mit Begründung und vor der Einreichung, Erlass statt Löschen). Die vier
// schreibenden Wege der generischen Sicht - Bezahlt, Stornieren, Wieder öffnen,
// Löschen - lehnen eine Forderung deshalb mit 400 ab, und die Oberfläche zeigt
// an ihr keine dieser Aktionen, sondern den Sprung in den Einzug-Reiter. Ein
// freier Posten verhält sich unverändert (siehe auch 12-open-items).
//
// Der Bestand ist NICHT leer: `api.resetBook()` räumt Buchungen, Konten und
// offene Posten (Forderungen), nicht aber Mitglieder, Mandate, Zuweisungen und
// Läufe früherer Specs. Jeder Test legt deshalb Mitglieder mit eindeutigen
// Namen an (find-or-create) und seedet seine Posten selbst (beforeEach räumt
// sie weg). Der freigegebene Lauf des Lauf-Tests wird am Ende verworfen, damit
// er keinem späteren Spec in die Quere kommt.

const IBAN = 'DE02120300000000202051'
const MEMBER = 'Regeln Offeneposten'
const OTHER_MEMBER = 'Andere Offeneposten'
const FREE_DEBTOR = 'Schreinerei Holzwurm'

const iso = (date) => date.toISOString().slice(0, 10)
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))

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

async function ensureMember(request, displayName) {
	const [firstName, lastName] = displayName.split(' ')
	const existing = (await api.listMembers(request)).find((m) => m.displayName === displayName)
	return existing ?? api.createMember(request, { firstName, lastName, email: `${firstName}.${lastName}@example.org`.toLowerCase() })
}

/** Mitglied mit aktivem Mandat (find-or-create) – seine Forderungen sind einzugsfähig. */
async function ensureMemberWithMandate(request, displayName) {
	const member = await ensureMember(request, displayName)
	const mandates = await api.mandatesByMember(request, member.id)
	if (!mandates.some((m) => m.status === 'aktiv')) {
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: IBAN, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
	}
	return member
}

async function createClaim(request, memberId, dueDate, { amount = 12.5, label = 'Testbeitrag Offene Posten', type = 'beitrag' } = {}) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type, amount, label, dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

async function claimById(request, id) {
	return (await api.getJson(request, '/claims')).find((c) => c.id === id)
}

const writePaths = (id) => [
	['bezahlt', 'POST', `/open-items/${id}/pay`],
	['storniert', 'POST', `/open-items/${id}/cancel`],
	['wieder geöffnet', 'POST', `/open-items/${id}/reopen`],
	['gelöscht', 'DELETE', `/open-items/${id}`],
]

/** Der Willkommenshinweis für Kassenprüfer erscheint je Browserprofil einmal (siehe 08-roles). */
async function dismissRevisorIntro(page) {
	const intro = page.getByText('Willkommen als Kassenprüfer/in')
	await intro.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
	if (await intro.isVisible()) {
		await page.getByRole('button', { name: 'Verstanden' }).click()
	}
}

/** Öffnet Buchungen → Offene Posten (Filter „Offen“ ist die Vorgabe). */
async function openOpenItems(page, user = USERS.buchhalter) {
	await openApp(page, user)
	if (user === USERS.revisor) { await dismissRevisorIntro(page) }
	await switchTab(page, 'Buchungen')
	await visibleSection(page).getByRole('button', { name: 'Offene Posten' }).click()
	await expect(chip(page, 'Offen')).toBeVisible()
}

/** Die Filter-Chips der Offene-Posten-Sicht (die Suchleiste darüber trägt dieselbe Klasse, daher über .vbh-chip). */
function chip(page, label) {
	return visibleSection(page).locator('.vbh-chip', { hasText: new RegExp(`^${label}$`) })
}

function rowOf(page, text) {
	return visibleSection(page).locator('tbody tr', { hasText: text }).first()
}

test.describe('Offene Posten: Regeln der Forderungen', () => {
	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('API: eine Beitragsforderung lässt sich über die generischen Wege nicht ändern (400)', async ({ request }) => {
		const member = await ensureMember(request, MEMBER)
		const claim = await createClaim(request, member.id, plusDays(20))

		for (const [what, method, path] of writePaths(claim.id)) {
			const resp = await api.raw(request, method, path, { user: USERS.buchhalter })
			expect(resp.status(), `Forderung ${what}`).toBe(400)
			// Die Meldung sagt, wo die Forderung zu bearbeiten ist.
			expect((await resp.json()).message, `Forderung ${what}`).toContain('Einzug-Reiter')
		}

		// Nichts davon hat etwas verändert: offen, ohne Vermerk, ohne Storno, noch da.
		const stored = await claimById(request, claim.id)
		expect(stored.status).toBe('open')
		expect(stored.settledBy).toBeNull()
		expect(stored.settledAt).toBeNull()
		expect(stored.cancelledAt).toBeNull()
	})

	test('API: eine erlassene, bezahlte oder stornierte Forderung bleibt, wie sie ist (kein Wiedereröffnen)', async ({ request }) => {
		const member = await ensureMember(request, MEMBER)
		const waived = await createClaim(request, member.id, plusDays(20), { label: 'Erlassen' })
		const paid = await createClaim(request, member.id, plusDays(21), { label: 'Bezahlt' })
		const cancelled = await createClaim(request, member.id, plusDays(22), { label: 'Storniert' })
		expect((await api.raw(request, 'POST', `/claims/${waived.id}/settle`, { data: { settlementType: 'waived', note: 'Härtefall' } })).ok()).toBeTruthy()
		expect((await api.raw(request, 'POST', `/claims/${paid.id}/settle`, { data: { settlementType: 'paid', note: 'bar' } })).ok()).toBeTruthy()
		expect((await api.raw(request, 'POST', `/claims/${cancelled.id}/cancel`, { data: { reason: 'doppelt angelegt' } })).ok()).toBeTruthy()

		for (const [id, status] of [[waived.id, 'waived'], [paid.id, 'paid'], [cancelled.id, 'cancelled']]) {
			const reopen = await api.raw(request, 'POST', `/open-items/${id}/reopen`, { user: USERS.buchhalter })
			expect(reopen.status(), status).toBe(400)
			expect((await claimById(request, id)).status, status).toBe(status)
		}
		// Erledigungsvermerk samt Urheber und Begründung sind unangetastet.
		const stored = await claimById(request, waived.id)
		expect(stored.settlementNote).toBe('Härtefall')
		expect(stored.settledBy).toBe('admin')
	})

	test('API: eine Forderung im freigegebenen Lauf lässt sich nicht löschen', async ({ request }) => {
		const dueDate = plusDays(50)
		const member = await ensureMemberWithMandate(request, MEMBER)
		const claim = await createClaim(request, member.id, dueDate, { label: 'Beitrag im Lauf' })
		const batch = await api.releaseDebitBatch(request, dueDate)
		try {
			const inRun = (await api.getDebitBatch(request, batch.id)).items.find((i) => i.openItemId === claim.id)
			expect(inRun, 'die Forderung steckt als Einzugsposten im Lauf').toBeTruthy()

			const resp = await api.raw(request, 'DELETE', `/open-items/${claim.id}`, { user: USERS.buchhalter })
			expect(resp.status()).toBe(400)
			expect((await resp.json()).message).toContain('Einzug-Reiter')

			// Forderung und Einzugsposten bestehen weiter: kein Posten, der ins Leere zeigt.
			expect((await claimById(request, claim.id)).status).toBe('open')
			const after = await api.getDebitBatch(request, batch.id)
			expect(after.items.some((i) => i.openItemId === claim.id)).toBe(true)
		} finally {
			await api.raw(request, 'POST', `/debit-batches/${batch.id}/discard`, { data: { reason: 'Spec 50 aufräumen' } })
		}
	})

	test('API: ein freier Posten verhält sich wie bisher; die Rollen bleiben', async ({ request }) => {
		const paid = await api.createOpenItem(request, { debtor: FREE_DEBTOR, description: 'Rechnung 1', amount: 80, dueDate: plusDays(10) })
		const cancelled = await api.createOpenItem(request, { debtor: FREE_DEBTOR, description: 'Rechnung 2', amount: 90, dueDate: plusDays(10) })
		const removed = await api.createOpenItem(request, { debtor: FREE_DEBTOR, description: 'Rechnung 3', amount: 70, dueDate: plusDays(10) })

		expect((await api.raw(request, 'POST', `/open-items/${paid.id}/pay`, { user: USERS.buchhalter })).status()).toBe(200)
		expect((await api.raw(request, 'POST', `/open-items/${cancelled.id}/cancel`, { user: USERS.buchhalter })).status()).toBe(200)
		expect((await api.raw(request, 'POST', `/open-items/${cancelled.id}/reopen`, { user: USERS.buchhalter })).status()).toBe(200)
		expect((await api.raw(request, 'DELETE', `/open-items/${removed.id}`, { user: USERS.buchhalter })).status()).toBe(200)

		const items = await api.getJson(request, '/open-items')
		expect(items.find((i) => i.id === paid.id).status).toBe('paid')
		expect(items.find((i) => i.id === cancelled.id).status).toBe('open')
		expect(items.some((i) => i.id === removed.id)).toBe(false)

		// Schreiben bleibt Buchhaltern vorbehalten: ein Revisor bekommt 403, nicht 400 oder 200.
		for (const [what, method, path] of writePaths(paid.id)) {
			expect((await api.raw(request, method, path, { user: USERS.revisor })).status(), what).toBe(403)
		}
	})

	test('Oberfläche: an einer Beitragsforderung stehen keine schreibenden Aktionen, an einem freien Posten schon', async ({ page, request }) => {
		const member = await ensureMember(request, MEMBER)
		await createClaim(request, member.id, plusDays(20), { label: 'Beitrag 2026', type: 'beitrag' })
		await api.createOpenItem(request, { debtor: FREE_DEBTOR, description: 'Rechnung', amount: 80, dueDate: plusDays(10) })

		await openOpenItems(page)
		const claimRow = rowOf(page, MEMBER)
		const freeRow = rowOf(page, FREE_DEBTOR)
		await expect(claimRow).toBeVisible()
		await expect(freeRow).toBeVisible()

		// Der freie Posten wie bisher
		await expect(freeRow.getByRole('button', { name: 'Bezahlt', exact: true })).toBeVisible()
		await expect(freeRow.getByRole('button', { name: 'Stornieren', exact: true })).toBeVisible()
		await expect(freeRow.locator('.vbh-typetag', { hasText: 'Beitrag' })).toHaveCount(0)

		// Die Forderung: als Beitrag gekennzeichnet, mit dem Sprung statt der Aktionen
		await expect(claimRow.locator('.vbh-typetag', { hasText: 'Beitrag' })).toBeVisible()
		await expect(claimRow.getByRole('button', { name: 'Bezahlt', exact: true })).toHaveCount(0)
		await expect(claimRow.getByRole('button', { name: 'Stornieren', exact: true })).toHaveCount(0)
		await expect(claimRow.getByRole('button', { name: 'Wieder öffnen', exact: true })).toHaveCount(0)
		await expect(claimRow.getByRole('button', { name: /^Im Einzug bearbeiten/ })).toBeVisible()
		await expect(visibleSection(page).getByText('Forderungen an Mitglieder (Beitrag oder Gebühr) sehen Sie hier nur.')).toBeVisible()
	})

	test('Oberfläche: eine erledigte Forderung bietet kein „Wieder öffnen“, der Erlass trägt seinen Namen', async ({ page, request }) => {
		const member = await ensureMember(request, MEMBER)
		const waived = await createClaim(request, member.id, plusDays(20), { label: 'Beitrag erlassen' })
		expect((await api.raw(request, 'POST', `/claims/${waived.id}/settle`, { data: { settlementType: 'waived', note: 'Härtefall' } })).ok()).toBeTruthy()

		await openOpenItems(page)
		await chip(page, 'Alle').click()
		const row = rowOf(page, MEMBER)
		await expect(row.locator('.vbh-typetag', { hasText: 'Erlassen' })).toBeVisible()
		await expect(row.getByRole('button', { name: 'Wieder öffnen', exact: true })).toHaveCount(0)
		await expect(row.getByRole('button', { name: /^Im Einzug bearbeiten/ })).toBeVisible()
	})

	test('Oberfläche: der Sprung führt in das Segment „Forderungen“, eingegrenzt auf das Mitglied', async ({ page, request }) => {
		const member = await ensureMember(request, MEMBER)
		const other = await ensureMember(request, OTHER_MEMBER)
		await createClaim(request, member.id, plusDays(20), { label: 'Beitrag gesucht' })
		await createClaim(request, other.id, plusDays(20), { label: 'Beitrag fremd' })

		await openOpenItems(page)
		await rowOf(page, MEMBER).getByRole('button', { name: /^Im Einzug bearbeiten/ }).click()

		await expect(tabButton(page, 'Beiträge')).toHaveClass(/active/)
		const einzug = visibleSection(page)
		await expect(einzug.getByRole('tab', { name: 'Forderungen', exact: true })).toHaveAttribute('aria-selected', 'true')
		const panel = einzug.getByRole('tabpanel', { name: 'Forderungen' })
		await expect(panel.getByRole('heading', { name: 'Forderungen', exact: true })).toBeVisible()
		await expect(panel.locator('.vbh-claims-memberchip')).toContainText(MEMBER)
		await expect(panel.locator('tbody tr', { hasText: 'Beitrag gesucht' })).toBeVisible()
		await expect(panel.locator('tbody tr', { hasText: 'Beitrag fremd' })).toHaveCount(0)

		// Dort geht, was hier nicht ging: eine Forderung als bezahlt vermerken (mit Urheber).
		await panel.locator('tbody tr', { hasText: 'Beitrag gesucht' }).getByRole('button', { name: /^Details zur Forderung/ }).click()
		await expect(panel.locator('.vbh-claimdetail').getByRole('button', { name: 'Als bezahlt markieren' })).toBeVisible()
	})

	test('Oberfläche: ein Revisor sieht die Forderung, ohne Aktion, mit Sprung zum Ansehen', async ({ page, request }) => {
		const member = await ensureMember(request, MEMBER)
		await createClaim(request, member.id, plusDays(20), { label: 'Beitrag Revisor' })

		await openOpenItems(page, USERS.revisor)
		const row = rowOf(page, MEMBER)
		await expect(row).toBeVisible()
		await expect(row.getByRole('button', { name: 'Bezahlt', exact: true })).toHaveCount(0)
		await expect(row.getByRole('button', { name: 'Stornieren', exact: true })).toHaveCount(0)
		await row.getByRole('button', { name: /^Im Einzug ansehen/ }).click()

		await expect(tabButton(page, 'Beiträge')).toHaveClass(/active/)
		const panel = visibleSection(page).getByRole('tabpanel', { name: 'Forderungen' })
		await expect(panel.locator('tbody tr', { hasText: 'Beitrag Revisor' })).toBeVisible()
	})

	test('Oberfläche: ein freier Posten lässt sich weiter bezahlen und stornieren', async ({ page, request }) => {
		const paid = await api.createOpenItem(request, { debtor: FREE_DEBTOR, description: 'Rechnung bezahlt', amount: 80, dueDate: plusDays(10) })
		const cancelled = await api.createOpenItem(request, { debtor: 'Gärtnerei Grün', description: 'Rechnung storniert', amount: 40, dueDate: plusDays(10) })

		await openOpenItems(page)
		await rowOf(page, FREE_DEBTOR).getByRole('button', { name: 'Bezahlt', exact: true }).click()
		await expect(page.getByText('Als bezahlt markiert.').first()).toBeVisible()
		await rowOf(page, 'Gärtnerei Grün').getByRole('button', { name: 'Stornieren', exact: true }).click()
		await expect(page.getByText('Storniert.').first()).toBeVisible()

		const items = await api.getJson(request, '/open-items')
		expect(items.find((i) => i.id === paid.id).status).toBe('paid')
		expect(items.find((i) => i.id === cancelled.id).status).toBe('cancelled')
	})
})
