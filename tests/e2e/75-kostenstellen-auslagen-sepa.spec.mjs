import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, collectionEntry, INCOME_ACCOUNT, openApp, pickNcSelectOption, returnEntry, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Kostenstellen bei Auslagen und beim SEPA-Einzug (Issue #333).
//
// Auslagen: der Dialog „Umsatz einer Auslage zuordnen“ fragt bei
// eingeschaltetem Modul nach der Kostenstelle des Aufwands.
//
// Einzug: eine Beitragsgruppe trägt eine Vorgabe-Kostenstelle. Forderungen,
// die der Einzugszyklus aus einer Zuweisung dieser Gruppe erzeugt, bringen
// sie beim Verbuchen der Sammelgutschrift mit; die Rückgabe nimmt den Erlös
// wieder heraus. Von Hand angelegte Forderungen haben keine Zuweisung und
// bleiben ohne.

const MEMBER_IBAN = 'DE89370400440532013000'
const DEBIT_IBAN = 'DE02120300000000202051'
const FEE_ACCOUNT = '5400'
const TODAY = new Date().toISOString().slice(0, 10)
const runTag = () => Date.now().toString(36)

const post = async (request, path, data) => (await api.raw(request, 'POST', path, { expectOk: true, data })).json()

async function setModule(request, enabled) {
	await api.updateSettings(request, { cost_centers_enabled: enabled ? '1' : '0' })
}

/** Verteilung der Zeile eines Kontos in einer Buchung, als { Kostenstelle: Cent }. */
async function allocationsOf(request, journalId, accountId) {
	const entry = (await api.listJournal(request)).find((e) => e.journal.id === journalId)
	const line = entry.lines.find((l) => l.accountId === accountId)
	return Object.fromEntries((line.allocations ?? []).map((a) => [a.costCenterId, Math.round(a.amount * 100)]))
}

/** Einnahmen je Kostenstellen-Kürzel laut Bericht. */
async function incomeByCode(request) {
	const report = await api.getJson(request, '/report/cost-center-allocations')
	expect(report.reconciled).toBe(true)
	return Object.fromEntries(report.centers.map((c) => [c.code, c.incomeCents]))
}

test.describe('Kostenstellen: Auszahlung einer Auslage', () => {
	let memberId
	let expense
	let sen

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await api.resetBook(request)
		const accounts = await api.seedDefaultAccounts(request)
		expense = accounts.find((a) => a.type === 'expense')
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)
		await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
		await api.setRole(request, USERS.buchhalter, 'verwalter')
		await api.unlinkMemberByNcUser(request, USERS.ohneRolle)
		memberId = (await api.createMember(request, { firstName: 'Erika', lastName: 'Kostenstelle', email: 'erika.kostenstelle@example.org' })).id
		await api.linkMember(request, memberId, USERS.ohneRolle)
		await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '2', cost_centers_enabled: '1' })
		sen = await post(request, '/booking-cost-centers', { code: 'SEN', name: 'Senioren' })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { reimbursement_enabled: '0', reimbursement_required_approvals: '2', cost_centers_enabled: '0' }, { expectOk: false })
		await api.setRole(request, USERS.buchhalter, 'buchhalter')
		if (memberId) {
			await api.unlinkMember(request, memberId, { expectOk: false })
			await api.deleteMember(request, memberId, { expectOk: false })
		}
		await api.resetBook(request)
	})

	/** Freigegebene Auslage samt passendem Abgang auf dem Konto. */
	async function approvedWithTransaction(request, purpose, amount) {
		const submitted = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose, amount })).json()
		await api.approveReimbursement(request, submitted.id, { user: USERS.admin })
		await api.approveReimbursement(request, submitted.id, { user: USERS.verwalter })
		await api.importCamtStatement(request, [{ bookingDate: TODAY, direction: 'DBIT', amountCents: Math.round(Number(amount) * 100), counterparty: 'Erika Kostenstelle', counterpartyIban: MEMBER_IBAN, purpose: `Erstattung ${purpose}` }])
		return submitted.id
	}

	async function openAssignDialog(page, purpose) {
		await openApp(page, USERS.admin, { booted: true })
		await switchTab(page, 'Buchungen')
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: /^Zuzuordnen/ }).click()
		await visibleSection(page).locator('tr', { hasText: `Erstattung ${purpose}` }).getByRole('button', { name: 'Einer Auslage zuordnen…' }).click()
		const dialog = page.getByRole('dialog', { name: 'Umsatz einer Auslage zuordnen' })
		await dialog.locator('label.vbh-reimb-pick-row', { hasText: purpose }).getByRole('radio').check()
		return dialog
	}

	test('der Dialog fragt nach der Kostenstelle; die Buchung trägt sie', async ({ page, request }) => {
		const id = await approvedWithTransaction(request, 'Trikots Senioren', '48.00')
		const dialog = await openAssignDialog(page, 'Trikots Senioren')
		// Erst mit gewähltem Aufwandskonto erscheint das Feld.
		await expect(dialog.locator('.vbh-reimb-alloc')).toHaveCount(0)
		await pickNcSelectOption(dialog, 'Konto wählen', expense.name)
		await dialog.locator('.vbh-reimb-alloc').getByLabel('Kostenstelle', { exact: true }).selectOption({ label: 'SEN · Senioren' })

		const sent = page.waitForRequest((r) => r.method() === 'POST' && r.url().includes(`/api/reimbursements/${id}/assign`))
		await dialog.getByRole('button', { name: 'Zuordnen und buchen' }).click()
		expect((await sent).postDataJSON().allocations).toEqual([{ costCenterId: sen.id, amount: 48 }])
		await expect(dialog).toBeHidden()

		const paid = await (await api.getReimbursement(request, id)).json()
		expect(paid.status).toBe('paid')
		expect(await allocationsOf(request, paid.journalId, expense.id)).toEqual({ [sen.id]: 4800 })
	})

	test('ohne Auswahl entsteht die Buchung ohne Kostenstelle', async ({ page, request }) => {
		const id = await approvedWithTransaction(request, 'Porto Rundbrief', '9.20')
		const dialog = await openAssignDialog(page, 'Porto Rundbrief')
		await pickNcSelectOption(dialog, 'Konto wählen', expense.name)
		await dialog.getByRole('button', { name: 'Zuordnen und buchen' }).click()
		await expect(dialog).toBeHidden()

		const paid = await (await api.getReimbursement(request, id)).json()
		expect(paid.status).toBe('paid')
		expect(await allocationsOf(request, paid.journalId, expense.id)).toEqual({})
	})

	test('ausgeschaltet: kein Feld, und der Request trägt keine Verteilung', async ({ page, request }) => {
		const id = await approvedWithTransaction(request, 'Fahrtkosten Turnier', '7.77')
		await setModule(request, false)
		try {
			const dialog = await openAssignDialog(page, 'Fahrtkosten Turnier')
			await pickNcSelectOption(dialog, 'Konto wählen', expense.name)
			await expect(dialog.locator('.vbh-reimb-alloc')).toHaveCount(0)
			const sent = page.waitForRequest((r) => r.method() === 'POST' && r.url().includes(`/api/reimbursements/${id}/assign`))
			await dialog.getByRole('button', { name: 'Zuordnen und buchen' }).click()
			expect((await sent).postDataJSON()).not.toHaveProperty('allocations')
			await expect(dialog).toBeHidden()
			expect((await (await api.getReimbursement(request, id)).json()).status).toBe('paid')
		} finally {
			await setModule(request, true)
		}
	})
})

test.describe('Kostenstellen: Vorgabe je Beitragsgruppe im SEPA-Einzug', () => {
	let income
	let fee
	let sen
	let groupWith
	let groupWithout

	test.beforeAll(async () => {
		// Der Einzugszyklus verschickt Vorabinfos; ohne Zustellweg bliebe er hängen (siehe Spec 28).
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
	})

	test.afterAll(async ({ request }) => {
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
		await api.updateSettings(request, { cost_centers_enabled: '0' }, { expectOk: false })
		await api.resetBook(request)
	})

	async function setUp(request) {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const bank = await api.accountByNumber(request, BANK_ACCOUNT)
		await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
		await api.updateSettings(request, {
			membership_enabled: '1',
			club_name: 'Testverein e.V.',
			sepa_creditor_id: 'DE98ZZZ09999999999',
			sepa_debtor_account_id: bank.id,
			cost_centers_enabled: '1',
		});
		[income, fee] = await api.accountsByNumber(request, INCOME_ACCOUNT, FEE_ACCOUNT)
		await api.setSepaImportSettings(request, { returnFeeAccountId: fee.id, returnFeeRechargeEnabled: '0', contributionDefaultAccountId: income.id })
		sen = await post(request, '/booking-cost-centers', { code: 'SEN', name: 'Senioren' })
		const tag = runTag()
		const group = { minMonthlyAmount: 1, defaultMonthlyAmount: 5, allowedIntervals: [12], defaultInterval: 12, isActive: true }
		groupWith = await post(request, '/contribution-groups', { ...group, name: `Senioren ${tag}`, bookingCostCenterId: sen.id })
		groupWithout = await post(request, '/contribution-groups', { ...group, name: `Passive ${tag}` })
		expect(groupWith.bookingCostCenterId).toBe(sen.id)
		expect(groupWithout.bookingCostCenterId).toBeNull()
		return tag
	}

	/** Mitglied mit aktivem Mandat und einer Zuweisung zur Gruppe (Jahresbeitrag = 12 × Monatsbeitrag). */
	async function memberInGroup(request, lastName, group, monthlyAmount) {
		const member = await api.createMember(request, { firstName: 'Kostenstelle', lastName, email: `ks.${lastName}@example.org`.toLowerCase().replace(/\s+/g, '') })
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: DEBIT_IBAN, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
		await post(request, '/assignments', { memberId: member.id, groupId: group.id, intervalMonths: 12, monthlyAmount, paymentMethod: 'direct_debit', validFrom: TODAY })
		return member
	}

	/** Lässt den Einzugszyklus laufen: er legt die Forderungen der Zuweisungen an. */
	async function runDueCycle() {
		const container = getContainer()
		const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
		const job = JSON.parse(stdout).find((j) => (j.class || '').includes('ContributionDueCycleJob'))
		expect(job, 'ContributionDueCycleJob ist registriert').toBeTruthy()
		await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })
	}

	/** Zwei Mitglieder (mit und ohne Kostenstelle der Gruppe), Forderungen erzeugt, Lauf eingereicht. */
	async function submittedBatch(request, tag) {
		const a = await memberInGroup(request, `Anna ${tag}`, groupWith, 5)
		const b = await memberInGroup(request, `Bernd ${tag}`, groupWithout, 3)
		await runDueCycle()
		const claims = (await api.getJson(request, '/claims')).filter((c) => [a.id, b.id].includes(c.memberId))
		expect(claims.map((c) => c.assignmentId).filter(Boolean), 'der Zyklus hat je Zuweisung eine Forderung angelegt').toHaveLength(2)
		const dueDates = [...new Set(claims.map((c) => c.dueDate))]
		expect(dueDates).toHaveLength(1)
		// Mitglieder und Zuweisungen früherer Tests überleben das Zurücksetzen des Bestands und
		// laufen mit ein; ihre Gruppen haben dabei die Vorgabe verloren. Geprüft wird der eigene Posten.
		const batch = await api.releaseAndSubmitDebitBatch(request, dueDates[0])
		const withCc = batch.items.find((i) => i.memberDisplayName.includes(`Anna ${tag}`))
		expect(withCc, 'der Posten des Mitglieds der Gruppe mit Kostenstelle ist im Lauf').toBeTruthy()
		return { a, b, batch, withCc }
	}

	/** Importiert die Sammelgutschrift, bestätigt alle Zeilen und verbucht. Liefert die Buchungs-ID. */
	async function settleCollection(request, batch) {
		await api.importCamtStatement(request, [collectionEntry({ bookingDate: TODAY, items: batch.items })])
		const item = await api.findReconciliationItem(request, batch.items[0].remittanceInfo)
		for (const detail of item.details) {
			await api.decideSepaDetail(request, detail.id, 'assign', { debitItemId: detail.candidates[0].debitItemId ?? detail.candidates[0].id })
		}
		await api.settleSepaImport(request, item.bankTx.id)
		return (await api.listTransactions(request, {})).find((t) => t.id === item.bankTx.id).journalId
	}

	test('Sammelgutschrift: nur der Beitrag der Gruppe mit Kostenstelle wird zugeordnet', async ({ request }) => {
		test.setTimeout(120000)
		const tag = await setUp(request)
		const { batch, withCc } = await submittedBatch(request, tag)

		const journalId = await settleCollection(request, batch)

		const total = batch.items.reduce((sum, i) => sum + i.amountCents, 0)
		const entry = (await api.listJournal(request)).find((e) => e.journal.id === journalId)
		expect(entry.lines.find((l) => l.accountId === income.id).creditCents).toBe(total)
		expect(await allocationsOf(request, journalId, income.id)).toEqual({ [sen.id]: withCc.amountCents })
		expect(await incomeByCode(request)).toEqual({ SEN: withCc.amountCents })
	})

	test('Rücklastschrift nimmt den Erlös wieder aus der Kostenstelle; die Gebühr bleibt ohne', async ({ request }) => {
		test.setTimeout(120000)
		const tag = await setUp(request)
		const { batch, withCc } = await submittedBatch(request, tag)
		await settleCollection(request, batch)
		expect(await incomeByCode(request)).toEqual({ SEN: withCc.amountCents })

		await api.importCamtStatement(request, [returnEntry({ bookingDate: TODAY, item: withCc, reasonCode: 'AC04', chargesCents: 300 })])
		const back = (await api.bankReconciliation(request)).items.find((entry) => entry.details.some((d) => d.isReturn))
		for (const detail of back.details) {
			await api.decideSepaDetail(request, detail.id, 'assign', { debitItemId: detail.candidates[0].debitItemId ?? detail.candidates[0].id })
		}
		await api.settleSepaImport(request, back.bankTx.id)

		const journalId = (await api.listTransactions(request, {})).find((t) => t.id === back.bankTx.id).journalId
		expect(await allocationsOf(request, journalId, income.id)).toEqual({ [sen.id]: withCc.amountCents })
		expect(await allocationsOf(request, journalId, fee.id)).toEqual({})
		// Einnahme und Rückgabe heben sich in der Kostenstelle auf.
		expect((await incomeByCode(request)).SEN ?? 0).toBe(0)
	})

	/** Importiert die Rückgabe eines Postens, bestätigt und verbucht sie. Liefert die Buchungs-ID. */
	async function settleReturn(request, item) {
		await api.importCamtStatement(request, [returnEntry({ bookingDate: TODAY, item, reasonCode: 'AC04', chargesCents: 300 })])
		const back = (await api.bankReconciliation(request)).items.find((entry) => entry.details.some((d) => d.isReturn))
		for (const detail of back.details) {
			await api.decideSepaDetail(request, detail.id, 'assign', { debitItemId: detail.candidates[0].debitItemId ?? detail.candidates[0].id })
		}
		await api.settleSepaImport(request, back.bankTx.id)
		return (await api.listTransactions(request, {})).find((t) => t.id === back.bankTx.id).journalId
	}

	test('Einzug ohne Kostenstelle verbucht: die spätere Rückgabe bucht die Kostenstelle nicht ins Minus', async ({ request }) => {
		test.setTimeout(120000)
		const tag = await setUp(request)
		const { batch, withCc } = await submittedBatch(request, tag)
		// Der Einzug wird verbucht, während die Funktion ausgeschaltet ist …
		await setModule(request, false)
		await settleCollection(request, batch)
		// … die Rückgabe kommt, nachdem sie eingeschaltet wurde.
		await setModule(request, true)
		const journalId = await settleReturn(request, withCc)

		expect(await allocationsOf(request, journalId, income.id)).toEqual({})
		expect(await incomeByCode(request)).toEqual({})
	})

	test('ausgeschaltet: der Gruppendialog hat kein Feld, die Vorgabe bleibt, verbucht wird ohne Kostenstelle', async ({ page, request }) => {
		test.setTimeout(120000)
		const tag = await setUp(request)
		const { batch } = await submittedBatch(request, tag)
		await setModule(request, false)

		// Gruppe in der Oberfläche umbenennen: der Request trägt das Feld nicht, die Vorgabe bleibt.
		await openApp(page, USERS.admin)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Beitragsgruppen', exact: true }).click()
		// Die Zeile der Gruppenliste - die Zuweisungs-Tabelle darunter nennt die Gruppe auch.
		const row = visibleSection(page).locator('tr', { has: page.locator('td.vbh-stack-title', { hasText: `Senioren ${tag}` }) })
		await expect(row).not.toContainText('Kostenstelle')
		await row.getByRole('button', { name: 'Bearbeiten' }).click()
		const dialog = page.getByRole('dialog', { name: 'Beitragsgruppe bearbeiten' })
		await expect(dialog.getByText('Kostenstelle', { exact: true })).toHaveCount(0)
		const saved = page.waitForRequest((r) => r.method() === 'PUT' && r.url().includes(`/api/contribution-groups/${groupWith.id}`))
		await dialog.getByPlaceholder('z.B. Basisbeitrag').fill(`Senioren neu ${tag}`)
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		expect((await saved).postDataJSON()).not.toHaveProperty('bookingCostCenterId')
		await expect(dialog).toBeHidden()
		const stored = (await api.getJson(request, '/contribution-groups')).find((g) => g.id === groupWith.id)
		expect(stored).toMatchObject({ name: `Senioren neu ${tag}`, bookingCostCenterId: sen.id })

		const journalId = await settleCollection(request, batch)
		await setModule(request, true)
		expect(await allocationsOf(request, journalId, income.id)).toEqual({})
	})

	test('eingeschaltet: Gruppendialog setzt die Vorgabe; die Kostenstelle lässt sich dann nicht löschen', async ({ page, request }) => {
		const tag = await setUp(request)
		await openApp(page, USERS.admin)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Beitragsgruppen', exact: true }).click()
		const row = visibleSection(page).locator('tr', { has: page.locator('td.vbh-stack-title', { hasText: `Passive ${tag}` }) })
		await row.getByRole('button', { name: 'Bearbeiten' }).click()
		const dialog = page.getByRole('dialog', { name: 'Beitragsgruppe bearbeiten' })
		await dialog.locator('label', { hasText: 'Kostenstelle' }).locator('select').selectOption({ label: 'SEN · Senioren' })
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(dialog).toBeHidden()
		await expect(row).toContainText('Kostenstelle: SEN')

		const blocked = await api.raw(request, 'DELETE', `/booking-cost-centers/${sen.id}`)
		expect(blocked.status()).toBe(400)
		expect((await blocked.json()).message).toContain('Beitragsgruppen')
	})
})
