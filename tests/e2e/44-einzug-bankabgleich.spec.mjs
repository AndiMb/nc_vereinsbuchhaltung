import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, collectionEntry, openApp, returnEntry, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Einzug-Unterreiter, Segment „Bankabgleich“ (Issue #105, Spec §3.6/§3.10/§5):
// „der Bankauszug ist die Wahrheit“ – die Zuordnungsvorschläge zu importierten
// Bankumsätzen (Sammeleinzug, Rücklastschrift, Zahlungseingang) prüfen und die
// Verbuchung selbst auslösen. Nichts bucht automatisch. Schreiben ab
// `buchhalter`, lesend ab `revisor`.
//
// Der Weg „Einzug → Kontoauszug → Zuordnung → Verbuchung“ läuft hier wirklich
// durch: Forderungen und Mandate über die API, Lauf freigeben und einreichen
// (api.releaseAndSubmitDebitBatch), ein camt.053-Auszug mit den End-to-End-IDs
// und Mandatsreferenzen genau dieses Laufs (collectionEntry(), returnEntry()
// aus fixtures/nextcloud.mjs), Import – und dann Urteil und Verbuchen in der
// Oberfläche.
//
// Der Bestand ist NICHT leer: `api.resetBook()` räumt Buchungen, Konten, offene
// Posten, SEPA-Detail-Zeilen, abgelehnte Vorschläge und seit Issue #123 auch
// Läufe, Posten, Rücklastschriften und Mahnstufen, nicht aber Mitglieder und
// Mandate früherer Specs. Jeder Test legt deshalb Mitglieder mit
// eigenem Namen an (mit Laufzeichen im Nachnamen, damit ein Wiederholungslauf
// nach einem Fehlschlag nicht auf ein gesperrtes Mandat stößt) und sucht in der
// Oberfläche gezielt nach seinen Umsätzen. Bankdaten tragen das heutige Datum:
// es gibt keine Zeitreise, und eine Buchung am heutigen Tag liegt immer im
// offenen Geschäftsjahr. Nur der Test zur geschlossenen Periode geht ins Vorjahr.
//
// Die Rücklastschrift löst eine Zahlungsaufforderung aus; der Testserver hat
// keinen Mailserver (NC-Standard: SMTP auf 127.0.0.1:25, Verbindung abgelehnt),
// ohne Zustellweg vermerkt das Mahnwesen keine Stufe. Für die Gruppen mit
// Rücklastschrift gilt deshalb der NC-Mail-Modus „null“ (Mails werden
// angenommen und verworfen) – danach wieder entfernt, config.php gehört nicht
// zum Datenbank-Snapshot (siehe 28-contribution-prenotification).

const IBAN = 'DE02120300000000202051'
const FEE_ACCOUNT = '5400'

const iso = (date) => date.toISOString().slice(0, 10)
const today = () => iso(new Date())
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const germanDate = (isoDate) => isoDate.split('-').reverse().join('.')
/** Kurzes Kennzeichen dieses Durchlaufs für Mitgliedsnamen. */
const runTag = () => Date.now().toString(36)

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

/** Frischer Bestand mit Kontenrahmen, einziehendem Konto und den Konten der Verbuchung (Erlöse 4000, Gebühren 5400). */
async function setUp(request, { recharge = false } = {}) {
	await api.resetBook(request)
	await api.seedDefaultAccounts(request)
	await enableModule(request)
	const [income, fee] = await api.accountsByNumber(request, INCOME_ACCOUNT, FEE_ACCOUNT)
	await api.setSepaImportSettings(request, {
		returnFeeAccountId: fee.id,
		returnFeeRechargeEnabled: recharge ? '1' : '0',
		contributionDefaultAccountId: income.id,
	})
	return { income, fee }
}

async function ensureMemberWithMandate(request, firstName, lastName) {
	const displayName = `${firstName} ${lastName}`
	const existing = (await api.listMembers(request)).find((m) => m.displayName === displayName)
	const member = existing ?? await api.createMember(request, { firstName, lastName, email: `${firstName}.${lastName}@example.org`.toLowerCase().replace(/\s+/g, '') })
	const mandates = await api.mandatesByMember(request, member.id)
	if (!mandates.some((m) => m.status === 'aktiv')) {
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: IBAN, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
	}
	return member
}

async function createClaim(request, memberId, dueDate, { amount, label, type = 'beitrag' }) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type, amount, label, dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

/** Ein Mitglied mit einer einzugsfähigen Forderung, im eingereichten Lauf – liefert den Posten (End-to-End-ID, Mandatsreferenz, Betrag …). */
async function submittedItem(request, tag, name, { amount, label }) {
	const dueDate = plusDays(41)
	const member = await ensureMemberWithMandate(request, 'Bankabgleich', `${name} ${tag}`)
	await createClaim(request, member.id, dueDate, { amount, label })
	const batch = await api.releaseAndSubmitDebitBatch(request, dueDate)
	return { member, batch, item: batch.items.find((i) => i.remittanceInfo === label) }
}

/** Öffnet Beiträge → Einzug → Bankabgleich und liefert den Bereich des Segments. */
async function openBankabgleich(page, user = USERS.buchhalter) {
	await openApp(page, user)
	await switchTab(page, 'Beiträge')
	if (user !== USERS.revisor) {
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
	}
	await visibleSection(page).getByRole('tab', { name: 'Bankabgleich', exact: true }).click()
	const panel = visibleSection(page).getByRole('tabpanel', { name: 'Bankabgleich' })
	await expect(panel.getByRole('heading', { name: 'Bankabgleich', exact: true })).toBeVisible()
	return panel
}

/** Der Umsatz mit diesem Text im Verwendungszweck – nicht seine aufgeklappten Zeilen. */
function txCard(panel, text) {
	return panel.locator('.vbh-bank-tx', { hasText: text })
}

/** Klappt den Umsatz auf, falls er zu ist (ein einzelner Umsatz öffnet sich beim Laden von selbst). */
async function expand(card) {
	await expect(card).toBeVisible()
	const toggle = card.getByRole('button', { name: /^Zeilen prüfen/ })
	if (await toggle.count()) {
		await toggle.click()
	}
	await expect(card.getByRole('button', { name: /^Zeilen ausblenden/ })).toBeVisible()
}

const verbuchen = (card) => card.getByRole('button', { name: 'Verbuchen…', exact: true })
const jetztVerbuchen = (dialog) => dialog.getByRole('button', { name: 'Jetzt verbuchen', exact: true })

test.describe('Einzug-Unterreiter: Bankabgleich der Sammelgutschrift', () => {
	test.beforeEach(async ({ request }) => {
		await setUp(request)
	})

	test('Einzug → Kontoauszug → Zuordnung → Verbuchung: Zeile für Zeile beurteilt, dann mit Vorschau gebucht', async ({ page, request }) => {
		test.setTimeout(120000)
		const tag = runTag()
		const dueDate = plusDays(41)
		const alma = await ensureMemberWithMandate(request, 'Bankabgleich', `Alma ${tag}`)
		const bruno = await ensureMemberWithMandate(request, 'Bankabgleich', `Bruno ${tag}`)
		const doppel = await ensureMemberWithMandate(request, 'Bankabgleich', `Doppel ${tag}`)
		await createClaim(request, alma.id, dueDate, { amount: 12.5, label: `Beitrag Alma ${tag}` })
		await createClaim(request, bruno.id, dueDate, { amount: 20, label: `Beitrag Bruno ${tag}` })
		// Zwei Forderungen desselben Mandats mit gleichem Betrag: findet die Bank sie ohne End-to-End-ID, passen beide Zeilen auf beide Posten.
		await createClaim(request, doppel.id, dueDate, { amount: 10, label: `Beitrag Doppel Jan ${tag}` })
		await createClaim(request, doppel.id, dueDate, { amount: 10, label: `Beitrag Doppel Feb ${tag}` })
		const batch = await api.releaseAndSubmitDebitBatch(request, dueDate)
		expect(batch.items).toHaveLength(4)
		const jan = batch.items.find((i) => i.remittanceInfo === `Beitrag Doppel Jan ${tag}`)
		const feb = batch.items.find((i) => i.remittanceInfo === `Beitrag Doppel Feb ${tag}`)

		const imported = await api.importCamtStatement(request, [collectionEntry({ bookingDate: today(), items: batch.items, omitEndToEndId: [jan.id, feb.id] })])
		expect(imported.new).toBe(1)

		const panel = await openBankabgleich(page)
		const card = txCard(panel, `Beitrag Alma ${tag}`)
		await expand(card)
		await expect(card.locator('.vbh-typetag', { hasText: 'Einzugsgutschrift' })).toBeVisible()
		await expect(card.getByText('wartet auf Urteil', { exact: true })).toBeVisible()
		await expect(card.getByText('0 von 4 beurteilt', { exact: true })).toBeVisible()

		// Verbuchen erst, wenn alle Zeilen beurteilt sind – und das Warum steht daneben
		await expect(verbuchen(card)).toBeDisabled()
		await expect(card.getByText('Verbuchen ist erst möglich, wenn alle 4 Zeilen beurteilt sind – noch 4 offen.')).toBeVisible()

		// Zeilen mit genau einem Treffer über End-to-End-ID werden gemeinsam bestätigt – gebucht wird dabei nichts
		// Die Reihenfolge der Zeilen ist die des Auszugs, nicht die der Anlage: Zeilen werden über ihren Inhalt gefunden.
		const rows = card.locator('.vbh-bank-detail')
		await expect(rows).toHaveCount(4)
		const almaRow = rows.filter({ hasText: `Bankabgleich Alma ${tag}` })
		await expect(almaRow).toContainText('Gleiche End-to-End-ID')
		// Die beiden Zeilen des Mandats „Doppel“ sind nicht zu unterscheiden – ihre Nummer („Zeile n“) hält sie auseinander.
		const doppelLabels = await rows.filter({ hasText: 'Mehrere Posten passen' }).evaluateAll((els) => els.map((el) => el.getAttribute('aria-label')))
		expect(doppelLabels).toHaveLength(2)
		await card.getByRole('button', { name: 'Eindeutige Vorschläge bestätigen (2)', exact: true }).click()
		await expect(page.getByText('2 Vorschläge bestätigt. Gebucht wurde noch nichts.').first()).toBeVisible()
		await expect(card.getByText('2 von 4 beurteilt', { exact: true })).toBeVisible()
		await expect(almaRow.getByText('Zugeordnet:')).toBeVisible()
		expect((await api.listJournal(request)).length).toBe(0)

		// Mehrdeutigkeit: alle Kandidaten zur Auswahl, keiner vorausgewählt, Begründung in Klartext
		const third = card.locator(`.vbh-bank-detail[aria-label="${doppelLabels[0]}"]`)
		await expect(third).toContainText('Mehrere Posten passen. Es ist keiner vorausgewählt')
		await expect(third.locator('.vbh-bank-candidate')).toHaveCount(2)
		await expect(third.locator('.vbh-bank-candidate--chosen')).toHaveCount(0)
		await expect(third).toContainText('Gleiche Mandatsreferenz und gleicher Betrag')
		await expect(third).not.toContainText(/\d\s?%/)
		await third.locator('.vbh-bank-candidate', { hasText: `Beitrag Doppel Jan ${tag}` }).getByRole('button', { name: /^Zuordnen/ }).click()
		await expect(card.getByText('3 von 4 beurteilt', { exact: true })).toBeVisible()

		// Ein Posten gehört zu höchstens einer Zeile: die vierte Zeile bietet „Jan“ nicht mehr an, und der Server lehnt es auch ab
		const fourth = card.locator(`.vbh-bank-detail[aria-label="${doppelLabels[1]}"]`)
		const fourthJan = fourth.locator('.vbh-bank-candidate', { hasText: `Beitrag Doppel Jan ${tag}` })
		await expect(fourthJan).toContainText('bereits einer anderen Zeile zugeordnet')
		await expect(fourthJan.getByRole('button')).toHaveCount(0)
		// Die beiden Doppel-Zeilen sind in der API die mit zwei Kandidaten, in der Reihenfolge der Oberfläche.
		const worklist = await api.findReconciliationItem(request, `Beitrag Alma ${tag}`)
		const [, secondDoppel] = worklist.details.filter((d) => d.candidates.length === 2)
		const refused = await api.decideSepaDetail(request, secondDoppel.id, 'assign', { debitItemId: jan.id, expectOk: false })
		expect(refused.status()).toBe(400)
		expect((await refused.json()).message).toContain('bereits einer anderen Zeile zugeordnet')

		await fourth.locator('.vbh-bank-candidate', { hasText: `Beitrag Doppel Feb ${tag}` }).getByRole('button', { name: /^Zuordnen/ }).click()
		await expect(card.getByText('4 von 4 beurteilt', { exact: true })).toBeVisible()
		await expect(card.getByText('bereit zum Verbuchen', { exact: true })).toBeVisible()
		await expect(verbuchen(card)).toBeEnabled()

		// Vorschau der Buchung: Bank im Soll, die Erlöse gruppiert im Haben, Buchungsdatum = Datum des Bankumsatzes
		await verbuchen(card).click()
		const dialog = page.getByRole('dialog', { name: 'Einzugsgutschrift verbuchen' })
		await expect(dialog.getByRole('heading', { name: 'Buchungsvorschau' })).toBeVisible()
		await expect(dialog).toContainText(`Buchungsdatum: ${germanDate(today())}`)
		await expect(dialog).toContainText('das Datum des Bankumsatzes')
		const bankRow = dialog.locator('.vbh-bank-bookingrow', { hasText: '1200' })
		await expect(bankRow).toContainText('Soll')
		await expect(bankRow).toContainText(/52,50\s*€/)
		const revenueRow = dialog.locator('.vbh-bank-bookingrow', { hasText: '4000' })
		await expect(revenueRow).toContainText('Haben')
		await expect(revenueRow).toContainText('Mitgliedsbeiträge')
		await expect(revenueRow).toContainText('4 Posten')
		await expect(revenueRow).toContainText(/52,50\s*€/)
		await expect(dialog).toContainText('4 Forderungen werden als bezahlt erledigt')
		await expect(jetztVerbuchen(dialog)).toBeEnabled()
		await jetztVerbuchen(dialog).click()
		await expect(page.getByText('Verbucht: 4 Forderungen als bezahlt erledigt.').first()).toBeVisible()
		await expect(card).toHaveCount(0)
		await expect(panel.getByText('Es warten keine Bankumsätze mit SEPA-Bezug auf ein Urteil.')).toBeVisible()

		// Gebucht ist nur, was bestätigt wurde: ein Buchungssatz mit dem Datum des Umsatzes, Forderungen bezahlt
		const journal = await api.listJournal(request)
		expect(journal).toHaveLength(1)
		expect(journal[0].journal.date).toBe(today())
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		expect(journal[0].lines.find((l) => l.accountId === bank.id)?.debitCents).toBe(5250)
		expect(journal[0].lines.find((l) => l.accountId === income.id)?.creditCents).toBe(5250)
		const overview = await api.getJson(request, '/claims/overview')
		for (const label of [`Beitrag Alma ${tag}`, `Beitrag Bruno ${tag}`, `Beitrag Doppel Jan ${tag}`, `Beitrag Doppel Feb ${tag}`]) {
			const claim = overview.claims.find((c) => c.description === label)
			expect(claim.state, label).toBe('erledigt')
			expect(claim.settlementType, label).toBe('paid')
		}
		expect((await api.listTransactions(request, { status: 'assigned' })).length).toBe(1)

		// Ein zweites Verbuchen derselben Gutschrift wird abgelehnt statt die Buchung still zu ersetzen
		const again = await api.settleSepaImport(request, worklist.bankTx.id, { expectOk: false })
		expect(again.status()).toBe(400)
		expect((await again.json()).message).toContain('bereits gebucht')
	})

	test('abgelehnte Zeilen lassen sich nicht mitbuchen: die Vorschau nennt die Differenz, „nicht zuordenbar“ bleibt für die Handbuchung', async ({ page, request }) => {
		test.setTimeout(90000)
		const tag = runTag()
		const dueDate = plusDays(41)
		const alma = await ensureMemberWithMandate(request, 'Bankabgleich', `Differenz ${tag}`)
		const bruno = await ensureMemberWithMandate(request, 'Bankabgleich', `Rest ${tag}`)
		await createClaim(request, alma.id, dueDate, { amount: 15, label: `Beitrag Differenz ${tag}` })
		await createClaim(request, bruno.id, dueDate, { amount: 25, label: `Beitrag Rest ${tag}` })
		const batch = await api.releaseAndSubmitDebitBatch(request, dueDate)
		await api.importCamtStatement(request, [collectionEntry({ bookingDate: today(), items: batch.items })])

		const panel = await openBankabgleich(page)
		const card = txCard(panel, `Beitrag Differenz ${tag}`)
		await expand(card)
		// Die Reihenfolge der Zeilen ist die des Auszugs, nicht die der Anlage: Zeilen werden über ihren Inhalt gefunden.
		const confirmedRow = card.locator('.vbh-bank-detail', { hasText: `Bankabgleich Differenz ${tag}` })
		const otherRow = card.locator('.vbh-bank-detail', { hasText: `Bankabgleich Rest ${tag}` })
		await confirmedRow.getByRole('button', { name: /^Bestätigen/ }).click()
		await otherRow.getByRole('button', { name: 'Ablehnen', exact: true }).click()
		await expect(otherRow.getByText('abgelehnt', { exact: true })).toBeVisible()
		await expect(card.getByText('2 von 2 beurteilt', { exact: true })).toBeVisible()

		// Alle beurteilt, eine zugeordnet: Verbuchen ist bereit – aber die Summe geht nicht auf, und die Vorschau sagt warum
		await verbuchen(card).click()
		const dialog = page.getByRole('dialog', { name: 'Einzugsgutschrift verbuchen' })
		await expect(dialog).toContainText(/Die zugeordneten Posten ergeben 15,00 €, der Bankumsatz beträgt 40,00 €/)
		await expect(dialog).toContainText('keinem Posten zugeordnet')
		await expect(jetztVerbuchen(dialog)).toBeDisabled()
		await dialog.getByRole('button', { name: 'Abbrechen', exact: true }).click()
		await expect(dialog).toBeHidden()

		// Ein Urteil lässt sich ändern: erst „nicht zuordenbar“, dann doch zuordnen
		await otherRow.getByRole('button', { name: 'Nicht zuordenbar', exact: true }).click()
		await expect(otherRow.getByText('nicht zuordenbar', { exact: true })).toBeVisible()
		await otherRow.getByRole('button', { name: /^Bestätigen/ }).click()
		await expect(card.getByText('bereit zum Verbuchen', { exact: true })).toBeVisible()
		await confirmedRow.getByRole('button', { name: 'Urteil ändern' }).click()
		await expect(confirmedRow.getByRole('button', { name: 'Ablehnen', exact: true })).toBeVisible()
	})
})

test.describe('Einzug-Unterreiter: Bankabgleich der Rücklastschrift', () => {
	test.beforeAll(async () => {
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
		// Anders als 28/43 (Tageslauf per occ) geht die Zahlungsaufforderung hier aus einem Web-Request raus:
		// Apache liest config.php über opcache (Standard: Zeitstempel alle 2 s prüfen). Ohne Pause sähe der erste
		// Request noch den alten Mail-Modus (SMTP auf 127.0.0.1:25, abgelehnt) und das Mahnwesen vermerkte keine Stufe.
		await new Promise((resolve) => setTimeout(resolve, 4000))
	})

	test.afterAll(async () => {
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	test('Deckung fehlt: Grund in Klartext, Folgen vorab genannt, zwei Gegenkonto-Zeilen, Zahlungsaufforderung und Gebühren-Forderung', async ({ page, request }) => {
		test.setTimeout(120000)
		const { income, fee } = await setUp(request, { recharge: true })
		const tag = runTag()
		const { member, item } = await submittedItem(request, tag, 'Deckung', { amount: 12.5, label: `Beitrag Deckung ${tag}` })
		await api.importCamtStatement(request, [returnEntry({ bookingDate: today(), item, reasonCode: 'AM04', reasonText: 'Insufficient funds', chargesCents: 350 })])

		const panel = await openBankabgleich(page)
		const card = txCard(panel, `Rücklastschrift Bankabgleich Deckung ${tag}`)
		await expand(card)
		await expect(card.locator('.vbh-typetag', { hasText: 'Rücklastschrift' })).toBeVisible()
		await expect(card).toContainText(/-16,00\s*€/)

		// Der Grund steht in Klartext; den ISO-Code sieht nur die Buchhaltung
		const row = card.locator('.vbh-bank-detail')
		await expect(row).toContainText('Mangels Kontodeckung zurückgegeben')
		await expect(row).toContainText('Rückgabecode (nur für die Buchhaltung sichtbar):')
		await expect(row.locator('code')).toHaveText('AM04')
		await expect(row).toContainText('Insufficient funds')
		await expect(row).toContainText(/Bankgebühr\s*3,50\s*€/)

		// Die automatischen Folgen stehen vor der Bestätigung da: Zahlungsaufforderung und Gebühren-Forderung, keine Sperre
		await expect(row).toContainText('Mit dem Verbuchen dieser Rücklastschrift geschieht automatisch')
		await expect(row).toContainText('Das Mitglied erhält sofort eine Zahlungsaufforderung per E-Mail')
		await expect(row).toContainText(/Die Bankgebühr von 3,50\s*€ wird dem Mitglied als eigene Gebühren-Forderung weiterbelastet/)
		await expect(row).not.toContainText('Das Mandat wird gesperrt')

		await row.getByRole('button', { name: /^Bestätigen/ }).click()
		await expect(card.getByText('bereit zum Verbuchen', { exact: true })).toBeVisible()
		await verbuchen(card).click()

		// Vorschau: Erlös zurück aufs ursprüngliche Erlöskonto, die Bankgebühr aufs Gebührenkonto, die Bank im Haben
		const dialog = page.getByRole('dialog', { name: 'Rücklastschrift verbuchen' })
		await expect(dialog).toContainText(`Buchungsdatum: ${germanDate(today())}`)
		const revenueRow = dialog.locator('.vbh-bank-bookingrow', { hasText: income.number })
		await expect(revenueRow).toContainText('Soll')
		await expect(revenueRow).toContainText('Erlös zurück')
		await expect(revenueRow).toContainText(/12,50\s*€/)
		const feeRow = dialog.locator('.vbh-bank-bookingrow', { hasText: fee.number })
		await expect(feeRow).toContainText('Soll')
		await expect(feeRow).toContainText('Bankgebühr')
		await expect(feeRow).toContainText(/3,50\s*€/)
		const bankRow = dialog.locator('.vbh-bank-bookingrow', { hasText: '1200' })
		await expect(bankRow).toContainText('Haben')
		await expect(bankRow).toContainText(/16,00\s*€/)
		await expect(dialog).toContainText('Das geschieht beim Verbuchen')
		await expect(dialog).toContainText('Das Mitglied erhält sofort eine Zahlungsaufforderung')
		await jetztVerbuchen(dialog).click()
		await expect(page.getByText('Rücklastschrift verbucht: 1 Forderung ist wieder offen.').first()).toBeVisible()
		await expect(card).toHaveCount(0)

		// Die Folgen sind eingetreten: Forderung zurückgegeben, Zahlungsaufforderung versandt, Gebühren-Forderung angelegt, Mandat unberührt
		const overview = await api.getJson(request, '/claims/overview')
		const claim = overview.claims.find((c) => c.description === `Beitrag Deckung ${tag}`)
		expect(claim.state).toBe('zurueckgegeben')
		expect(claim.dunning.stage).toBe(0)
		const feeClaim = overview.claims.find((c) => c.memberId === member.id && c.type === 'gebuehr')
		expect(feeClaim.amountCents).toBe(350)
		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates.find((m) => m.status === 'aktiv')).toBeTruthy()

		const journal = await api.listJournal(request)
		expect(journal).toHaveLength(1)
		expect(journal[0].lines.find((l) => l.accountId === income.id)?.debitCents).toBe(1250)
		expect(journal[0].lines.find((l) => l.accountId === fee.id)?.debitCents).toBe(350)
	})

	test('Konto erloschen: das Mandat wird gesperrt – vorab genannt, danach eingetreten', async ({ page, request }) => {
		test.setTimeout(120000)
		await setUp(request)
		const tag = runTag()
		const { member, item } = await submittedItem(request, tag, 'Sperre', { amount: 12.5, label: `Beitrag Sperre ${tag}` })
		await api.importCamtStatement(request, [returnEntry({ bookingDate: today(), item, reasonCode: 'AC04' })])

		const panel = await openBankabgleich(page)
		const card = txCard(panel, `Rücklastschrift Bankabgleich Sperre ${tag}`)
		await expand(card)
		const row = card.locator('.vbh-bank-detail')
		await expect(row).toContainText('Konto nicht nutzbar')
		await expect(row.locator('code')).toHaveText('AC04')
		await expect(row).toContainText('Das Mandat wird gesperrt')
		await expect(row).toContainText('Das Mitglied erhält sofort eine Zahlungsaufforderung per E-Mail')
		// Ohne Bankgebühr in der Rückgabe: keine Gebühren-Forderung
		await expect(row).not.toContainText('gebührenforderung')
		await expect(row).not.toContainText('Gebühren-Forderung')

		await row.getByRole('button', { name: /^Bestätigen/ }).click()
		await verbuchen(card).click()
		const dialog = page.getByRole('dialog', { name: 'Rücklastschrift verbuchen' })
		await expect(dialog).toContainText('Das Mandat wird gesperrt')
		// Ohne Gebühr nur eine Gegenkonto-Zeile
		await expect(dialog.locator('.vbh-bank-bookingrow')).toHaveCount(2)
		await jetztVerbuchen(dialog).click()
		await expect(page.getByText('Rücklastschrift verbucht: 1 Forderung ist wieder offen.').first()).toBeVisible()

		const mandate = (await api.mandatesByMember(request, member.id)).find((m) => m.status === 'ausgesetzt')
		expect(mandate, 'das Mandat ist gesperrt').toBeTruthy()
		expect(mandate.suspensionOrigin).toBe('ruecklastschrift')
		const overview = await api.getJson(request, '/claims/overview')
		expect(overview.claims.find((c) => c.description === `Beitrag Sperre ${tag}`).dunning.stage).toBe(0)
	})

	test('Revisor liest den Bankabgleich, ohne Aktionen und ohne Rückgabecode', async ({ page, request }) => {
		test.setTimeout(90000)
		await setUp(request)
		const tag = runTag()
		const { item } = await submittedItem(request, tag, 'Pruefung', { amount: 12.5, label: `Beitrag Pruefung ${tag}` })
		await api.importCamtStatement(request, [returnEntry({ bookingDate: today(), item, reasonCode: 'AM04', reasonText: 'Insufficient funds' })])
		const worklist = await api.findReconciliationItem(request, `Rücklastschrift Bankabgleich Pruefung ${tag}`)

		await openApp(page, USERS.revisor)
		// Der Willkommenshinweis für Kassenprüfer erscheint je Browserprofil einmal (siehe 08-roles).
		await expect(page.getByText('Willkommen als Kassenprüfer/in')).toBeVisible()
		await page.getByRole('button', { name: 'Verstanden' }).click()
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('tab', { name: 'Bankabgleich', exact: true }).click()
		const panel = visibleSection(page).getByRole('tabpanel', { name: 'Bankabgleich' })
		await expect(panel.getByText('Sie sehen den Bankabgleich nur lesend')).toBeVisible()

		const card = txCard(panel, `Pruefung ${tag}`)
		await expand(card)
		await expect(card).toContainText('Mangels Kontodeckung zurückgegeben')
		await expect(card).toContainText('Gleiche End-to-End-ID')
		await expect(card).not.toContainText('Rückgabecode')
		await expect(card).not.toContainText('AM04')
		await expect(card).not.toContainText('Insufficient funds')
		await expect(card.getByRole('button', { name: /Bestätigen|Zuordnen|Ablehnen|Nicht zuordenbar|Verbuchen/ })).toHaveCount(0)

		// Dasselbe an der API: lesen ja (ohne Code), schreiben nein, ohne Rolle gar nichts
		const asRevisor = await api.raw(request, 'GET', '/bank-reconciliation', { user: USERS.revisor })
		expect(asRevisor.ok()).toBeTruthy()
		const entry = (await asRevisor.json()).items.find((i) => i.bankTx.id === worklist.bankTx.id)
		expect(entry.details[0].return.reasonClass).toBe('insufficient_funds')
		expect(entry.details[0].return).not.toHaveProperty('reasonCode')
		expect(entry.details[0].return).not.toHaveProperty('reasonText')
		const pending = await api.raw(request, 'GET', '/sepa-import/pending', { user: USERS.revisor })
		expect(pending.ok()).toBeTruthy()
		for (const entry of await pending.json()) {
			for (const detail of entry.details) {
				expect(detail).not.toHaveProperty('returnReasonCode')
				expect(detail).not.toHaveProperty('returnReasonText')
			}
		}
		const asBookkeeper = await api.getJson(request, '/bank-reconciliation', { user: USERS.buchhalter })
		expect(asBookkeeper.items.find((i) => i.bankTx.id === worklist.bankTx.id).details[0].return.reasonCode).toBe('AM04')

		const detailId = worklist.details[0].id
		const itemId = worklist.details[0].candidates[0].debitItemId
		for (const [path, data] of [
			[`/sepa-import/details/${detailId}/assign`, { debitItemId: itemId }],
			[`/sepa-import/details/${detailId}/reject`, {}],
			[`/sepa-import/details/${detailId}/unmatched`, {}],
			[`/sepa-import/${worklist.bankTx.id}/settle`, {}],
			[`/sepa-import/${worklist.bankTx.id}/incoming-payment-suggestions/1`, {}],
			[`/bank-reconciliation/${worklist.bankTx.id}/incoming-payment-suggestions/1/reject`, {}],
		]) {
			expect((await api.raw(request, 'POST', path, { user: USERS.revisor, data })).status(), path).toBe(403)
		}
		expect((await api.raw(request, 'GET', '/bank-reconciliation', { user: USERS.ohneRolle })).status()).toBe(403)
		expect((await api.raw(request, 'GET', `/bank-reconciliation/${worklist.bankTx.id}/preview`, { user: USERS.ohneRolle })).status()).toBe(403)
	})
})

test.describe('Einzug-Unterreiter: Bankabgleich und geschlossenes Geschäftsjahr', () => {
	test('ein Umsatz im geschlossenen Vorjahr lässt sich nicht verbuchen: verständlicher Hinweis vor dem Klick, nach dem Wiedereröffnen geht es', async ({ page, request }) => {
		test.setTimeout(120000)
		const { income } = await setUp(request)
		const tag = runTag()
		const pastDate = `${new Date().getUTCFullYear() - 1}-03-15`
		// Eine Hilfsbuchung legt das Vorjahr als Geschäftsjahr an, dann wird es festgeschrieben.
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)
		await api.createBooking(request, { date: pastDate, description: `Hilfsbuchung Vorjahr ${tag}`, debitAccountId: bank.id, creditAccountId: income.id, amount: 1 })
		const periodId = await api.periodIdForDate(request, pastDate)
		await api.closePeriod(request, periodId)

		const { item } = await submittedItem(request, tag, 'Altjahr', { amount: 15, label: `Beitrag Altjahr ${tag}` })
		await api.importCamtStatement(request, [collectionEntry({ bookingDate: pastDate, items: [item] })])

		const panel = await openBankabgleich(page)
		const card = txCard(panel, `Beitrag Altjahr ${tag}`)
		await expand(card)
		await card.getByRole('button', { name: /^Bestätigen/ }).click()
		await verbuchen(card).click()

		const dialog = page.getByRole('dialog', { name: 'Einzugsgutschrift verbuchen' })
		await expect(dialog).toContainText(`Buchungsdatum: ${germanDate(pastDate)}`)
		await expect(dialog.getByRole('alert')).toContainText(`Der Bankumsatz vom ${germanDate(pastDate)} liegt in einem abgeschlossenen Geschäftsjahr und kann deshalb nicht verbucht werden`)
		await expect(dialog.getByRole('alert')).toContainText('Ein Verwalter kann das Geschäftsjahr')
		await expect(dialog).not.toContainText('Request failed')
		await expect(jetztVerbuchen(dialog)).toBeDisabled()

		// Der Server sagt dasselbe: 423 mit der Meldung der App
		const worklist = await api.findReconciliationItem(request, `Beitrag Altjahr ${tag}`)
		const refused = await api.settleSepaImport(request, worklist.bankTx.id, { expectOk: false })
		expect(refused.status()).toBe(423)
		expect((await refused.json()).message).toContain('abgeschlossen')

		// Der Weg aus dem Hindernis: ein Verwalter öffnet das Geschäftsjahr wieder, danach geht die Verbuchung
		await dialog.getByRole('button', { name: 'Abbrechen', exact: true }).click()
		await expect(dialog).toBeHidden()
		await api.reopenPeriod(request, periodId)
		await verbuchen(card).click()
		await expect(dialog.getByRole('heading', { name: 'Buchungsvorschau' })).toBeVisible()
		await expect(dialog.getByRole('alert')).toHaveCount(0)
		await expect(jetztVerbuchen(dialog)).toBeEnabled()
		await jetztVerbuchen(dialog).click()
		await expect(page.getByText('Verbucht: 1 Forderung als bezahlt erledigt.').first()).toBeVisible()
		const journal = (await api.listJournal(request, { period: periodId })).filter((e) => e.journal.description !== `Hilfsbuchung Vorjahr ${tag}`)
		expect(journal).toHaveLength(1)
		expect(journal[0].journal.date).toBe(pastDate)
	})
})

test.describe('Einzug-Unterreiter: Bankabgleich der Zahlungseingänge', () => {
	test.beforeEach(async ({ request }) => {
		await setUp(request)
	})

	/** Eine Überweisung ohne SEPA-Bezug: der Zahler und der Betrag sind alles, woran die Forderung erkannt wird. */
	async function seedTransfer(request, name, { amount, label }) {
		const member = await api.createMember(request, { firstName: 'Bankabgleich', lastName: name, email: `${name}@example.org`.toLowerCase().replace(/\s+/g, '') })
		await createClaim(request, member.id, plusDays(20), { amount, label })
		await api.importCamtStatement(request, [{
			bookingDate: today(),
			direction: 'CRDT',
			amountCents: Math.round(amount * 100),
			counterparty: `Bankabgleich ${name}`,
			counterpartyIban: IBAN,
			purpose: `Beitrag Bankabgleich ${name}`,
		}])
		return member
	}

	test('„diese Gutschrift passt auf Forderung X“: Begründung in Klartext, bestätigen bucht und schließt die Forderung ab', async ({ page, request }) => {
		test.setTimeout(90000)
		const tag = runTag()
		await seedTransfer(request, `Zahler ${tag}`, { amount: 33, label: `Beitrag Zahler ${tag}` })

		const panel = await openBankabgleich(page)
		// Ohne Einzug oder Rückgabe ist die Ansicht mit Arbeit schon gewählt
		await expect(panel.getByRole('button', { name: /^Zahlungseingänge \(1\)/ })).toHaveAttribute('aria-pressed', 'true')
		const card = panel.locator('.vbh-bank-in-tx', { hasText: `Zahler ${tag}` })
		await expect(card).toContainText('Zahlungseingang')
		await expect(card).toContainText(/33,00\s*€/)
		await expect(card).toContainText(`Beitrag Zahler ${tag}`)
		await expect(card).toContainText(/Betrag 33,00 € passt zur offenen Forderung von Bankabgleich Zahler .*, dessen Name auch im Zahlungstext steht/)
		await expect(card).toContainText('Buchung: Bank an 4000 Mitgliedsbeiträge')

		await card.getByRole('button', { name: /^Bestätigen und verbuchen/ }).click()
		const confirm = page.getByRole('dialog', { name: 'Zahlungseingang verbuchen' })
		await expect(confirm).toContainText('wird gebucht und die Forderung als bezahlt erledigt')
		await confirm.getByRole('button', { name: 'Verbuchen', exact: true }).click()
		await expect(page.getByText('Die Gutschrift ist der Forderung zugeordnet und gebucht.').first()).toBeVisible()
		await expect(panel.getByText('Es gibt keine Gutschrift, die zu einer offenen Forderung passt.')).toBeVisible()

		const overview = await api.getJson(request, '/claims/overview')
		const claim = overview.claims.find((c) => c.description === `Beitrag Zahler ${tag}`)
		expect(claim.state).toBe('erledigt')
		expect(claim.settlementType).toBe('paid')
		const journal = await api.listJournal(request)
		expect(journal).toHaveLength(1)
		expect(journal[0].journal.date).toBe(today())
	})

	test('ablehnen: der Vorschlag verschwindet und kommt nicht wieder, auch nicht nach dem Neuladen', async ({ page, request }) => {
		test.setTimeout(90000)
		const tag = runTag()
		await seedTransfer(request, `Ablehner ${tag}`, { amount: 44, label: `Beitrag Ablehner ${tag}` })

		const panel = await openBankabgleich(page)
		const card = panel.locator('.vbh-bank-in-tx', { hasText: `Ablehner ${tag}` })
		await card.getByRole('button', { name: /^Ablehnen/ }).click()
		const confirm = page.getByRole('dialog', { name: 'Vorschlag ablehnen' })
		await expect(confirm).toContainText('wird dieser Forderung nicht mehr vorgeschlagen')
		await confirm.getByRole('button', { name: 'Ablehnen', exact: true }).click()
		await expect(page.getByText('Vorschlag abgelehnt.').first()).toBeVisible()
		await expect(card).toHaveCount(0)

		// Gemerkt: der Server schlägt dasselbe Paar nicht wieder vor
		expect((await api.bankReconciliation(request)).incoming).toEqual([])
		const reloaded = await openBankabgleich(page)
		await expect(reloaded.getByRole('button', { name: /^Zahlungseingänge \(0\)/ })).toBeVisible()
		// Weder gebucht noch erledigt: die Forderung bleibt offen, der Umsatz liegt weiter unter Buchungen → Zuzuordnen
		const overview = await api.getJson(request, '/claims/overview')
		expect(overview.claims.find((c) => c.description === `Beitrag Ablehner ${tag}`).state).toBe('offen')
		expect(await api.listJournal(request)).toHaveLength(0)
		expect((await api.listTransactions(request, { status: 'unassigned' })).length).toBe(1)
	})
})

test.describe('Bankabgleich auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test('die Umsätze stehen als Karten, das Verbuchen-Fenster passt auf den Schirm, nichts läuft seitlich aus dem Bild', async ({ page, request }) => {
		test.setTimeout(120000)
		await setUp(request)
		const tag = runTag()
		const { item } = await submittedItem(request, tag, 'Handy', { amount: 12.5, label: `Beitrag Handy ${tag}` })
		await api.importCamtStatement(request, [collectionEntry({ bookingDate: today(), items: [item] })])

		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
		await visibleSection(page).getByRole('tab', { name: 'Bankabgleich', exact: true }).click()
		const panel = visibleSection(page).getByRole('tabpanel', { name: 'Bankabgleich' })
		const card = txCard(panel, `Beitrag Handy ${tag}`)
		await expand(card)
		await card.getByRole('button', { name: /^Bestätigen/ }).click()
		await expect(card.getByText('bereit zum Verbuchen', { exact: true })).toBeVisible()

		// Der Abschnitt scrollt nicht seitlich
		const overflow = await visibleSection(page).locator('.vbh-sectionbody').evaluate((el) => el.scrollWidth - el.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)

		await verbuchen(card).click()
		const dialog = page.getByRole('dialog', { name: 'Einzugsgutschrift verbuchen' })
		await expect(dialog).toContainText('Buchungsvorschau')
		await expect(jetztVerbuchen(dialog)).toBeVisible()
		const box = await dialog.boundingBox()
		expect(box.x + box.width).toBeLessThanOrEqual(375 + 1)
	})
})
