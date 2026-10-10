import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, INCOME_ACCOUNT, openApp, returnEntry, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Self-Service „Mein Beitrag“: eigene Rücklastschriften im Klartext (Issue #122,
// Spec §3.4 Pflicht-UI „Rücklastschriften als Klartext (nie Code)“ und „niemals
// andere Mitglieder sichtbar“). Bis dahin stand dort unbedingt „Bisher keine
// Rücklastschrift.“ – auch wenn welche vorlagen.
//
// Ein Mitglied mit zwei Rücklastschriften (Deckung fehlt, technischer Fehler)
// sieht beide in Klartext, ohne Bankcode und ohne den Freitext der Bank; ein
// zweites Mitglied ohne Rücklastschrift sieht den Leer-Hinweis und nichts vom
// anderen. Die Rücklastschriften entstehen wie in 44-einzug-bankabgleich über
// den echten Weg: Forderung, Lauf freigeben und einreichen, camt-Rückgabe
// importieren, Zeile bestätigen, verbuchen – erst das Verbuchen legt die
// Rücklastschrift an.
//
// Beide Mitglieder werden nacheinander mit demselben Konto (USERS.ohneRolle)
// verknüpft: ein Konto gehört zu höchstens einem Mitglied. `api.resetBook()`
// räumt Mitglieder und Verknüpfungen nicht – die Verknüpfung wird deshalb vor
// jedem Test gesetzt und im afterAll gelöst (siehe 26-self-service).
//
// Die Zahlungsaufforderung nach „Deckung fehlt“ geht aus einem Web-Request
// raus; der Testserver hat keinen Mailserver. Mail-Modus „null“ (Mails werden
// angenommen und verworfen), danach wieder entfernt – config.php gehört nicht
// zum Datenbank-Snapshot (siehe 44-einzug-bankabgleich).

const container = getContainer()

const IBAN = 'DE02120300000000202051'
const FEE_ACCOUNT = '5400'

const TEXT_INSUFFICIENT_FUNDS = 'Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.'
const TEXT_TECHNICAL = 'Die Lastschrift konnte aus technischen Gründen nicht eingezogen werden.'
const EMPTY_HINT = 'Bisher keine Rücklastschrift.'

const iso = (date) => date.toISOString().slice(0, 10)
const today = () => iso(new Date())
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const germanDate = (isoDate) => isoDate.split('-').reverse().join('.')
/** Kurzes Kennzeichen dieses Durchlaufs für Mitgliedsnamen. */
const runTag = () => Date.now().toString(36)

/** Frischer Bestand mit Kontenrahmen, einziehendem Konto und den Konten der Verbuchung. */
async function setUp(request) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		self_service_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
	const [income, fee] = await api.accountsByNumber(request, INCOME_ACCOUNT, FEE_ACCOUNT)
	await api.setSepaImportSettings(request, {
		returnFeeAccountId: fee.id,
		returnFeeRechargeEnabled: '0',
		contributionDefaultAccountId: income.id,
	})
}

/** Löst eine evtl. übrig gebliebene Verknüpfung dieses Kontos (abgebrochener Lauf, Retry, übersprungenes afterAll). */
async function unlinkExisting(request, ncUserId) {
	const members = await api.listMembers(request)
	for (const member of members.filter((m) => m.ncUserId === ncUserId)) {
		await api.unlinkMember(request, member.id)
	}
}

/** Das Konto gehört ab jetzt (nur) diesem Mitglied. */
async function linkOnly(request, memberId) {
	await unlinkExisting(request, USERS.ohneRolle)
	await api.linkMember(request, memberId, USERS.ohneRolle)
}

async function createClaim(request, memberId, dueDate, { amount, label }) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type: 'beitrag', amount, label, dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

/** Die Rücklastschrift eines Postens über den echten Weg: Rückgabe importieren, Zeile bestätigen, verbuchen. */
async function seedReturn(request, item, { reasonCode, reasonText }) {
	await api.importCamtStatement(request, [returnEntry({ bookingDate: today(), item, reasonCode, reasonText })])
	const worklist = await api.findReconciliationItem(request, `Rücklastschrift ${item.memberDisplayName}`)
	await api.decideSepaDetail(request, worklist.details[0].id, 'assign', { debitItemId: item.id })
	await api.settleSepaImport(request, worklist.bankTx.id)
}

/** Die Karte „Mein SEPA-Lastschriftmandat“ – darin steht der Bereich „Rücklastschriften“. */
function mandateCard(page) {
	return visibleSection(page).locator('.vbh-card', { hasText: 'Mein SEPA-Lastschriftmandat' })
}

async function openMeinBeitrag(page) {
	await openApp(page, USERS.ohneRolle)
	await switchTab(page, 'Mein Beitrag')
	const card = mandateCard(page)
	await expect(card.getByRole('heading', { name: 'Rücklastschriften', exact: true })).toBeVisible()
	return card
}

test.describe('Self-Service: eigene Rücklastschriften im Klartext', () => {
	let withReturns
	let withoutReturns
	let labelJan
	let labelFeb

	test.beforeAll(async ({ request }) => {
		test.setTimeout(180000)
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container })
		// Apache liest config.php über opcache (Standard: Zeitstempel alle 2 s prüfen): ohne Pause sähe der erste
		// Request noch den alten Mail-Modus und das Mahnwesen vermerkte keine Stufe.
		await new Promise((resolve) => setTimeout(resolve, 4000))

		await setUp(request)
		await unlinkExisting(request, USERS.ohneRolle)
		const tag = runTag()
		const dueDate = plusDays(41)

		// Mitglied mit Mandat und zwei Forderungen, beide im selben Lauf – und beide kommen zurück
		withReturns = await api.createMember(request, { firstName: 'Ruecklast', lastName: `Anna ${tag}`, email: `ruecklast.anna.${tag}@example.org` })
		const mandate = await (await api.createMandate(request, { memberId: withReturns.id, iban: IBAN, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
		labelJan = `Beitrag Januar ${tag}`
		labelFeb = `Beitrag Februar ${tag}`
		await createClaim(request, withReturns.id, dueDate, { amount: 12.5, label: labelJan })
		await createClaim(request, withReturns.id, dueDate, { amount: 20, label: labelFeb })
		const batch = await api.releaseAndSubmitDebitBatch(request, dueDate)
		const jan = batch.items.find((i) => i.remittanceInfo === labelJan)
		const feb = batch.items.find((i) => i.remittanceInfo === labelFeb)

		// Erst „Deckung fehlt“, danach der technische Fehler: beide am selben Bankdatum, die zuletzt verbuchte steht oben
		await seedReturn(request, jan, { reasonCode: 'AM04', reasonText: 'Insufficient funds' })
		await seedReturn(request, feb, { reasonCode: 'AM05', reasonText: 'Duplicate collection' })

		// Mitglied ohne Rücklastschrift – neben dem anderen, dessen Rücklastschriften in derselben Tabelle liegen
		withoutReturns = await api.createMember(request, { firstName: 'Ruecklast', lastName: `Bruno ${tag}`, email: `ruecklast.bruno.${tag}@example.org` })
	})

	test.afterAll(async ({ request }) => {
		await unlinkExisting(request, USERS.ohneRolle)
		await api.updateSettings(request, { self_service_enabled: '0', membership_enabled: '0' })
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container })
	})

	test('Mitglied mit Rücklastschriften sieht sie im Klartext, neueste zuerst – ohne Bankcode', async ({ page, request }) => {
		await linkOnly(request, withReturns.id)
		const card = await openMeinBeitrag(page)

		const rows = card.locator('.vbh-selfservice-return')
		await expect(rows).toHaveCount(2)
		await expect(card.getByText(EMPTY_HINT)).toHaveCount(0)

		// Zuletzt verbucht steht oben (gleiches Bankdatum): Februar mit technischem Fehler, dann Januar mit fehlender Deckung
		await expect(rows.nth(0)).toContainText(germanDate(today()))
		await expect(rows.nth(0)).toContainText(/20,00\s*€/)
		await expect(rows.nth(0)).toContainText(labelFeb)
		await expect(rows.nth(0)).toContainText(TEXT_TECHNICAL)
		await expect(rows.nth(1)).toContainText(germanDate(today()))
		await expect(rows.nth(1)).toContainText(/12,50\s*€/)
		await expect(rows.nth(1)).toContainText(labelJan)
		await expect(rows.nth(1)).toContainText(TEXT_INSUFFICIENT_FUNDS)

		// Nie der Code und nie der Freitext der Bank
		const text = await visibleSection(page).innerText()
		for (const forbidden of ['AM04', 'AM05', 'Insufficient funds', 'Duplicate collection']) {
			expect(text, `„${forbidden}“ darf in „Mein Beitrag“ nie stehen`).not.toContain(forbidden)
		}
	})

	test('Die Antwort des Servers trägt nur Klartext: kein Rückgabecode, kein Freitext der Bank, keine Kontodaten', async ({ request }) => {
		await linkOnly(request, withReturns.id)

		const returned = await api.selfReturnedDebits(request, { user: USERS.ohneRolle })

		expect(returned).toHaveLength(2)
		expect(returned.map((r) => r.description)).toEqual([labelFeb, labelJan])
		expect(returned.map((r) => r.reason)).toEqual([TEXT_TECHNICAL, TEXT_INSUFFICIENT_FUNDS])
		for (const row of returned) {
			expect(Object.keys(row).sort()).toEqual(['amountCents', 'description', 'periodEnd', 'periodStart', 'reason', 'receivedAt'])
			expect(row.receivedAt).toBe(today())
		}
		expect(returned.map((r) => r.amountCents)).toEqual([2000, 1250])
		expect(JSON.stringify(returned)).not.toMatch(/AM04|AM05|Insufficient|Duplicate|DE02|reasonCode|reasonText/)
	})

	test('Mitglied ohne Rücklastschrift sieht den Leer-Hinweis – und nichts vom anderen Mitglied', async ({ page, request }) => {
		await linkOnly(request, withoutReturns.id)
		const card = await openMeinBeitrag(page)

		await expect(card.getByText(EMPTY_HINT)).toBeVisible()
		await expect(card.locator('.vbh-selfservice-return')).toHaveCount(0)
		await expect(visibleSection(page)).not.toContainText(labelJan)
		await expect(visibleSection(page)).not.toContainText(labelFeb)
		await expect(visibleSection(page)).not.toContainText(TEXT_INSUFFICIENT_FUNDS)

		expect(await api.selfReturnedDebits(request, { user: USERS.ohneRolle })).toEqual([])
	})

	test('Schlägt das Laden fehl, steht kein falscher Leer-Hinweis da', async ({ page, request }) => {
		await linkOnly(request, withoutReturns.id)
		await page.route('**/api/self/returned-debits', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }))
		const card = await openMeinBeitrag(page)

		await expect(card.getByText('Rücklastschriften konnten nicht geladen werden.')).toBeVisible()
		await expect(card.getByText(EMPTY_HINT)).toHaveCount(0)
	})
})
