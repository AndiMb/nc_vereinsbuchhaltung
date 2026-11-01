import { expect, test } from '@playwright/test'
import { capturedMails, startMailCapture, stopMailCapture } from './fixtures/mail-capture.mjs'
import { api, BANK_ACCOUNT, BELEG_PNG, dav, openApp, openSettingsPage, pickNcSelectOption, skipBookingTour, switchTab, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Auslagenerstattung (docs/reimbursement-design.md): ein Mitglied reicht eine
// privat verauslagte Ausgabe mit Beleg ein, mindestens N verschiedene Verwalter
// geben sie frei, erst dann entsteht ein offener Posten zur Überweisung, und
// jeder Schritt steht in der Historie.
//
// Verwalter sind hier admin (Nextcloud-Administrator), test1 und – für die
// Dauer der Spec – test2: Bei N = 2 verlangt die Einstellung mindestens drei.
// Eingereicht wird als test4, verknüpft mit dem Mitglied „Erika Musterfrau".
// Wirkungen auf das Mitglied (Sicht, Namen) prüft die Spec ebenso wie die Rechte,
// die der Server durchsetzt, und den Weg über den Wächter-Ordner.

const MEMBER = { firstName: 'Erika', lastName: 'Musterfrau', email: 'erika.auslage@example.org' }
const EMAILS = {
	[USERS.admin]: 'admin.auslage@example.org',
	[USERS.verwalter]: 'test1.auslage@example.org',
	[USERS.buchhalter]: 'test2.auslage@example.org',
}
const WATCH_FOLDER = 'AuslagenBelege'

test.describe.configure({ mode: 'serial' })

/** Räumt eine Verknüpfung weg, die ein abgebrochener Lauf übrig ließ. */
async function unlinkExisting(request, ncUserId) {
	const existing = (await api.listMembers(request)).find((m) => m.ncUserId === ncUserId)
	if (existing) {
		await api.unlinkMember(request, existing.id)
		await api.deleteMember(request, existing.id)
	}
}

/** Wartet auf eine Mail mit passendem Betreff an den Empfänger. */
async function waitForMail(recipient, subject, timeoutMs = 20000) {
	const deadline = Date.now() + timeoutMs
	let seen = []
	while (Date.now() < deadline) {
		seen = (await capturedMails()).filter((m) => m.to.includes(recipient))
		const hit = seen.find((m) => subject.test(m.subject))
		if (hit) {
			return hit
		}
		await new Promise((resolve) => setTimeout(resolve, 500))
	}
	throw new Error(`Keine Mail ${subject} an ${recipient}; gefunden: ${seen.map((m) => m.subject).join(' | ') || 'keine'}`)
}

async function mailsTo(recipient) {
	return (await capturedMails()).filter((m) => m.to.includes(recipient))
}

/** Reiter „Buchungen → Auslagen" in der Verwaltungssicht. */
async function openAuslagenSicht(page, user) {
	await openApp(page, user)
	await switchTab(page, 'Buchungen')
	await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' }).click()
}

/** Die Zeile eines Antrags in der Verwaltungssicht, geöffnet. */
async function openAntrag(page, purpose) {
	await visibleSection(page).getByRole('button', { name: 'Alle', exact: false }).first().click()
	await visibleSection(page).locator('tr.vbh-reimb-row', { hasText: purpose }).click()
	const dialog = page.getByRole('dialog', { name: /Auslage #\d+ von Erika Musterfrau/ })
	await expect(dialog).toBeVisible()
	return dialog
}

test.describe('Auslagenerstattung', () => {
	let memberId
	let ownMemberIds = []
	let accounts

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await api.resetBook(request)
		accounts = await api.seedDefaultAccounts(request)

		// test2 wird für diese Spec Verwalter: drei Verwalter, damit N = 2 zulässig ist.
		await api.setRole(request, USERS.buchhalter, 'verwalter')
		for (const [uid, email] of Object.entries(EMAILS)) {
			await api.setUserEmail(request, uid, email)
		}

		await unlinkExisting(request, USERS.ohneRolle)
		memberId = (await api.createMember(request, MEMBER)).id
		await api.linkMember(request, memberId, USERS.ohneRolle)

		await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '2' })
		await startMailCapture()
	})

	test.afterAll(async ({ request }) => {
		await stopMailCapture()
		await api.updateSettings(request, { reimbursement_enabled: '0', reimbursement_required_approvals: '2', storage_mode: 'appdata', storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' }, { expectOk: false })
		await api.setRole(request, USERS.buchhalter, 'buchhalter')
		for (const id of [memberId, ...ownMemberIds]) {
			if (id) {
				await api.unlinkMember(request, id, { expectOk: false })
				await api.deleteMember(request, id, { expectOk: false })
			}
		}
		await dav.remove(request, 'admin', WATCH_FOLDER)
		await api.resetBook(request)
	})

	// --- Einstellungen ---------------------------------------------------------

	test('die Einstellung verlangt mindestens N+1 Verwalter und eine Zahl ab 1', async ({ request }) => {
		const tooMany = await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '5' }, { expectOk: false })
		expect(tooMany.status()).toBe(400)
		expect((await tooMany.json()).message).toMatch(/mindestens 6 Verwalter/)

		const zero = await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '0' }, { expectOk: false })
		expect(zero.status()).toBe(400)

		// Der Fehlversuch hat nichts verändert.
		const settings = await api.getSettings(request)
		expect(settings.reimbursement_enabled).toBe(true)
		expect(settings.reimbursement_required_approvals).toBe(2)
	})

	test('die Einstellungsseite zeigt den Server-Grund und speichert eine gültige Zahl', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_auslagen')
		await expect(section.getByText('Auslagenerstattung aktivieren')).toBeVisible()
		const approvals = section.locator('input[type="number"]')
		await expect(approvals).toHaveValue('2')

		await approvals.fill('5')
		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect(section.getByRole('alert')).toContainText('mindestens 6 Verwalter')

		await approvals.fill('1')
		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert' }).first()).toBeVisible()
		expect((await api.getSettings(request)).reimbursement_required_approvals).toBe(1)

		await api.updateSettings(request, { reimbursement_required_approvals: '2' })
	})

	// --- Einreichen ---------------------------------------------------------------

	test('ein Mitglied reicht über „Mein Bereich → Auslagen" eine Auslage mit Beleg ein', async ({ page, request }) => {
		await openApp(page, USERS.ohneRolle)
		// Keine Buchhaltung, nur der eigene Bereich – und ohne Self-Service „Beitrag" keine Unterreiter-Leiste.
		await expect(tabButton(page, 'Buchungen')).toHaveCount(0)
		await tabButton(page, 'Mein Bereich').click()
		const section = visibleSection(page)
		await expect(section.getByRole('heading', { name: 'Meine Auslagen' })).toBeVisible()
		await expect(section.locator('.vbh-subtabs')).toHaveCount(0)

		await section.getByRole('button', { name: 'Auslage einreichen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Auslage einreichen' })
		await dialog.getByLabel('Betrag (€)', { exact: true }).fill('12,50')
		await dialog.getByLabel('Verwendungszweck').fill('Material Jugendfreizeit')
		await dialog.getByLabel('IBAN für die Erstattung').fill('DE89 3704 0044 0532 0130 00')
		// Ohne Beleg lässt sich nicht einreichen.
		await expect(dialog.getByRole('button', { name: 'Einreichen', exact: true })).toBeDisabled()
		await dialog.locator('input[type="file"]').setInputFiles({ name: 'beleg.png', mimeType: 'image/png', buffer: BELEG_PNG })
		await dialog.getByRole('button', { name: 'Einreichen', exact: true }).click()
		await expect(dialog).toBeHidden()

		const row = section.locator('tr.vbh-reimb-row', { hasText: 'Material Jugendfreizeit' })
		await expect(row).toContainText('12,50')
		await expect(row).toContainText('Eingereicht')
		await expect(row).toContainText('0 von 2 Freigaben')

		const own = (await (await api.selfReimbursements(request, { user: USERS.ohneRolle })).json()).items
		expect(own).toHaveLength(1)
		expect(own[0]).toMatchObject({ status: 'submitted', amountCents: 1250, requiredApprovals: 2 })
	})

	test('alle Verwalter bekommen eine Mail über den neuen Antrag, das Mitglied nicht', async () => {
		for (const email of Object.values(EMAILS)) {
			const mail = await waitForMail(email, /Neue Auslage zur Freigabe/)
			expect(mail.text).toContain('Erika Musterfrau')
			expect(mail.text).toContain('12,50')
		}
		expect(await mailsTo(MEMBER.email)).toHaveLength(0)
	})

	// --- Freigeben ---------------------------------------------------------------

	test('die erste Freigabe macht den Antrag „teilweise freigegeben" – ohne Posten und ohne Mail ans Mitglied', async ({ page, request }) => {
		await openAuslagenSicht(page, USERS.verwalter)
		const dialog = await openAntrag(page, 'Material Jugendfreizeit')
		await expect(dialog).toContainText('DE89370400440532013000')
		await expect(dialog.getByRole('link', { name: 'beleg.png' })).toBeVisible()

		await dialog.getByRole('button', { name: 'Freigeben' }).click()
		await expect(dialog).toContainText('Teilweise freigegeben')
		await expect(dialog).toContainText('1 von 2 Freigaben')
		// Jede Person zählt einmal: der Knopf ist weg, der Hinweis steht da.
		await expect(dialog.getByRole('button', { name: 'Freigeben' })).toHaveCount(0)
		await expect(dialog).toContainText('Sie haben diesen Antrag bereits freigegeben')
		await expect(dialog).toContainText('test1 hat freigegeben')

		const openItems = await api.getJson(request, '/open-items')
		expect(openItems.filter((i) => i.reimbursementId)).toHaveLength(0)
		expect(await mailsTo(MEMBER.email)).toHaveLength(0)
	})

	test('die zweite Freigabe lässt den offenen Posten zur Überweisung entstehen und benachrichtigt das Mitglied', async ({ page, request }) => {
		await openAuslagenSicht(page, USERS.buchhalter)
		const dialog = await openAntrag(page, 'Material Jugendfreizeit')
		await dialog.getByRole('button', { name: 'Freigeben' }).click()
		await expect(dialog).toContainText('2 von 2 Freigaben')
		await expect(dialog.locator('.vbh-typetag').first()).toHaveText('Freigegeben')
		await dialog.getByRole('button', { name: 'Schließen' }).click()

		// Der Posten steht bei den offenen Posten, gekennzeichnet – und ist keine Forderung.
		const items = (await api.getJson(request, '/open-items')).filter((i) => i.reimbursementId)
		expect(items).toHaveLength(1)
		expect(items[0]).toMatchObject({ amountCents: 1250, status: 'open', memberId: null, type: null, debtor: 'Erika Musterfrau', reference: expect.stringMatching(/^AUSL-\d+$/) })
		expect(items[0].overdue).toBe(false)
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Offene Posten' }).click()
		await expect(visibleSection(page).locator('tr', { hasText: 'Erika Musterfrau' })).toContainText('Auslage – zu überweisen')
		// Die generischen Wege sind gesperrt: erledigt wird nur über den Antrag.
		const generic = await api.raw(request, 'POST', `/open-items/${items[0].id}/pay`)
		expect(generic.status()).toBe(400)
		expect((await generic.json()).message).toMatch(/Auslagenerstattung/)

		const mail = await waitForMail(MEMBER.email, /Auslage wurde freigegeben/)
		expect(mail.text).toContain('12,50')
	})

	test('das Mitglied sieht den Fortschritt, aber nie die Namen der Freigeber', async ({ page, request }) => {
		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Bereich').click()
		const section = visibleSection(page)
		await section.locator('tr.vbh-reimb-row', { hasText: 'Material Jugendfreizeit' }).click()
		const dialog = page.getByRole('dialog', { name: /Auslage vom/ })
		await expect(dialog).toContainText('Freigegeben')
		await expect(dialog).toContainText('2 von 2 Freigaben')
		await expect(dialog).toContainText('Freigabe durch einen Verwalter')
		for (const name of ['test1', 'test2', 'admin']) {
			await expect(dialog, `„${name}“ darf dem Mitglied nie angezeigt werden`).not.toContainText(name)
		}
		// Ein freigegebener Antrag lässt sich nicht mehr zurückziehen oder ändern.
		await expect(dialog.getByRole('button', { name: 'Antrag zurückziehen' })).toHaveCount(0)

		const id = (await (await api.selfReimbursements(request, { user: USERS.ohneRolle })).json()).items[0].id
		const detail = await (await api.selfReimbursement(request, id, { user: USERS.ohneRolle })).json()
		for (const entry of detail.history) {
			expect(entry).not.toHaveProperty('actorUid')
			expect(entry).not.toHaveProperty('hash')
		}
	})

	// --- Auszahlen -------------------------------------------------------------------

	test('„Auszahlung buchen" öffnet den Buchungsdialog, vermerkt die Überweisung und hängt den Beleg an die Buchung', async ({ page, request }) => {
		const expense = accounts.find((a) => a.type === 'expense')
		await openAuslagenSicht(page, USERS.admin)
		const detail = await openAntrag(page, 'Material Jugendfreizeit')
		await detail.getByRole('button', { name: 'Auszahlung buchen' }).click()

		// Der normale Buchungsdialog, vorbelegt mit Text und Betrag; Konto und Geldkonto wählt, wer bucht.
		const dialog = page.getByRole('dialog')
		await skipBookingTour(dialog)
		await expect(dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann')).toHaveValue(/Erstattung Auslage: Material Jugendfreizeit \(Erika Musterfrau\)/)
		await pickNcSelectOption(dialog, '– Kategorie wählen –', expense.name)
		await dialog.getByRole('button', { name: 'Buchen', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		const reimbursement = (await (await api.listReimbursements(request)).json()).items[0]
		expect(reimbursement.status).toBe('paid')
		expect(reimbursement.journalId).toBeTruthy()
		const openItem = (await api.getJson(request, '/open-items')).find((i) => i.reimbursementId === reimbursement.id)
		expect(openItem).toMatchObject({ status: 'paid', paidJournalId: reimbursement.journalId })

		// Der Beleg des Mitglieds hängt jetzt an der Buchung.
		const attachments = await api.listAttachments(request, reimbursement.journalId)
		expect(attachments.map((a) => a.fileName)).toEqual(['beleg.png'])

		await waitForMail(MEMBER.email, /Auslage wurde erstattet/)

		// Die Historie nennt jeden Schritt und stimmt in sich.
		const full = await (await api.getReimbursement(request, reimbursement.id)).json()
		expect(full.history.map((e) => e.action)).toEqual(['submitted', 'approved', 'approved', 'paid'])
		expect(full.history.map((e) => e.actorUid)).toEqual([USERS.ohneRolle, USERS.verwalter, USERS.buchhalter, USERS.admin])
		full.history.forEach((entry, i) => {
			expect(entry.seq).toBe(i + 1)
			expect(entry.prevHash).toBe(i === 0 ? null : full.history[i - 1].hash)
		})

		// Ein zweites Mal auszahlen geht nicht.
		const again = await api.payReimbursement(request, reimbursement.id, reimbursement.journalId, '2026-11-02', { expectOk: false })
		expect(again.status()).toBe(400)
	})

	// --- Ablehnen und Zurückziehen -------------------------------------------------------

	test('ein Verwalter lehnt sofort und endgültig ab – das Mitglied sieht und liest die Begründung', async ({ page, request }) => {
		await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Fahrtkosten Messe', amount: '40,00' })

		await openAuslagenSicht(page, USERS.verwalter)
		const detail = await openAntrag(page, 'Fahrtkosten Messe')
		await detail.getByRole('button', { name: 'Ablehnen' }).click()
		const reject = page.getByRole('dialog', { name: 'Auslage ablehnen' })
		// Ohne Begründung geht es nicht.
		await expect(reject.getByRole('button', { name: 'Ablehnen' })).toBeDisabled()
		await reject.getByLabel('Begründung (Pflicht)').fill('Das war eine private Fahrt')
		await reject.getByRole('button', { name: 'Ablehnen' }).click()
		await expect(detail).toContainText('Abgelehnt')
		await expect(detail).toContainText('Das war eine private Fahrt')

		const mail = await waitForMail(MEMBER.email, /Auslage wurde abgelehnt/)
		expect(mail.text).toContain('Das war eine private Fahrt')

		// Endgültig: keine weitere Freigabe, kein offener Posten.
		const rejected = (await (await api.listReimbursements(request)).json()).items.find((i) => i.purpose === 'Fahrtkosten Messe')
		const approve = await api.approveReimbursement(request, rejected.id, { user: USERS.buchhalter, expectOk: false })
		expect(approve.status()).toBe(400)
		expect((await api.getJson(request, '/open-items')).filter((i) => i.reimbursementId && i.status === 'open')).toHaveLength(0)

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Bereich').click()
		await visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Fahrtkosten Messe' }).click()
		const own = page.getByRole('dialog', { name: /Auslage vom/ })
		await expect(own).toContainText('Abgelehnt')
		await expect(own).toContainText('Das war eine private Fahrt')
	})

	test('das Mitglied zieht einen Antrag zurück, solange noch keine Freigabe vorliegt', async ({ page, request }) => {
		const created = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Porto Einladungen', amount: '3,80' })).json()

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Bereich').click()
		await visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Porto Einladungen' }).click()
		const dialog = page.getByRole('dialog', { name: /Auslage vom/ })
		await dialog.getByRole('button', { name: 'Antrag zurückziehen' }).click()
		await expect(page.getByRole('status').filter({ hasText: 'zurückgezogen' }).first()).toBeVisible()
		await expect(visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Porto Einladungen' })).toContainText('Zurückgezogen')

		const approve = await api.approveReimbursement(request, created.id, { expectOk: false })
		expect(approve.status()).toBe(400)
	})

	test('nach einer Freigabe lässt sich der Antrag nicht mehr zurückziehen', async ({ request }) => {
		const created = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Druckkosten Flyer' })).json()
		await api.approveReimbursement(request, created.id, { user: USERS.verwalter })

		const withdraw = await api.selfWithdrawReimbursement(request, created.id, { user: USERS.ohneRolle, expectOk: false })
		expect(withdraw.status()).toBe(400)

		// Aufräumen: ablehnen, damit der Antrag den folgenden Tests nicht im Weg steht.
		await api.rejectReimbursement(request, created.id, 'Test aufgeräumt', { user: USERS.buchhalter })
	})

	// --- Rechte --------------------------------------------------------------------

	test('der Server setzt die Rechte durch: Mitglied, Revisor, Buchhalter, Selbstfreigabe, fremde Anträge', async ({ request }) => {
		const pending = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Rechtetest' })).json()

		// Ein Mitglied ohne Rolle sieht die Verwaltungssicht nicht.
		expect((await api.listReimbursements(request, { user: USERS.ohneRolle, expectOk: false })).status()).toBe(403)

		// Der Revisor liest, sieht die IBAN nur maskiert und darf nichts entscheiden.
		const asAuditor = await (await api.listReimbursements(request, { user: USERS.revisor })).json()
		const row = asAuditor.items.find((i) => i.id === pending.id)
		expect(row.iban).toBeUndefined()
		expect(row.ibanMasked).toMatch(/^DE89•+3000$/)
		expect(row.canApprove).toBe(false)
		expect((await api.approveReimbursement(request, pending.id, { user: USERS.revisor, expectOk: false })).status()).toBe(403)
		expect((await api.rejectReimbursement(request, pending.id, 'nein', { user: USERS.revisor, expectOk: false })).status()).toBe(403)
		expect((await api.payReimbursement(request, pending.id, 1, '2026-11-01', { user: USERS.revisor, expectOk: false })).status()).toBe(403)

		// Dieselbe Person zählt nur einmal.
		await api.approveReimbursement(request, pending.id, { user: USERS.verwalter })
		const twice = await api.approveReimbursement(request, pending.id, { user: USERS.verwalter, expectOk: false })
		expect(twice.status()).toBe(403)

		// Selbstfreigabe: test1 ist selbst Mitglied und reicht ein – freigeben können es nur die anderen.
		const verwalterMember = await api.createMember(request, { firstName: 'Viktor', lastName: 'Verwalter' })
		ownMemberIds.push(verwalterMember.id)
		await api.linkMember(request, verwalterMember.id, USERS.verwalter)
		const own = await (await api.submitReimbursement(request, { user: USERS.verwalter, purpose: 'Eigener Antrag eines Verwalters' })).json()
		expect((await api.approveReimbursement(request, own.id, { user: USERS.verwalter, expectOk: false })).status()).toBe(403)
		expect((await api.rejectReimbursement(request, own.id, 'doch nicht', { user: USERS.verwalter, expectOk: false })).status()).toBe(403)
		const staffView = await (await api.getReimbursement(request, own.id, { user: USERS.verwalter })).json()
		expect(staffView).toMatchObject({ ownRequest: true, canApprove: false, canReject: false })
		await api.approveReimbursement(request, own.id, { user: USERS.admin })
		// Die Mail über den neuen Antrag geht nicht an den Einreicher selbst.
		await waitForMail(EMAILS[USERS.admin], /Neue Auslage zur Freigabe/)
		expect((await mailsTo(EMAILS[USERS.verwalter])).filter((m) => /Verwalter/.test(m.text) && /Eigener Antrag/.test(m.text))).toHaveLength(0)

		// Fremde Anträge: ein anderes verknüpftes Mitglied bekommt 404, nicht „verboten" (kein Hinweis auf die Existenz).
		const other = await api.createMember(request, { firstName: 'Rita', lastName: 'Revisor' })
		ownMemberIds.push(other.id)
		await api.linkMember(request, other.id, USERS.revisor)
		expect((await api.selfReimbursement(request, pending.id, { user: USERS.revisor, expectOk: false })).status()).toBe(404)
		expect((await (await api.selfReimbursements(request, { user: USERS.revisor })).json()).items).toHaveLength(0)
		expect((await api.selfWithdrawReimbursement(request, pending.id, { user: USERS.revisor, expectOk: false })).status()).toBe(404)

		await api.rejectReimbursement(request, pending.id, 'Test aufgeräumt', { user: USERS.buchhalter })
		await api.rejectReimbursement(request, own.id, 'Test aufgeräumt', { user: USERS.buchhalter })
	})

	test('ausgeschaltet: keine neuen Anträge, bestehende bleiben sichtbar; ohne Anträge verschwindet der Reiter', async ({ page, request }) => {
		await api.updateSettings(request, { reimbursement_enabled: '0' })
		try {
			// Bisherige Anträge bleiben lesbar, neue gibt es nicht.
			await openApp(page, USERS.ohneRolle)
			await tabButton(page, 'Mein Bereich').click()
			const section = visibleSection(page)
			await expect(section.locator('tr.vbh-reimb-row').first()).toBeVisible()
			await expect(section.getByRole('button', { name: 'Auslage einreichen' })).toHaveCount(0)
			expect((await api.submitReimbursement(request, { user: USERS.ohneRolle, expectOk: false })).status()).toBe(400)

			// Ein Mitglied ohne Anträge hat keinen Zugang mehr (Rita ist verknüpft, hat aber nie eingereicht).
			expect((await api.selfReimbursements(request, { user: USERS.revisor, expectOk: false })).status()).toBe(403)
			await openApp(page, USERS.revisor)
			await expect(tabButton(page, 'Mein Bereich')).toHaveCount(0)

			// Die Verwaltung bearbeitet bestehende Anträge weiter.
			await openAuslagenSicht(page, USERS.admin)
			await expect(visibleSection(page).locator('tr.vbh-reimb-row').first()).toBeVisible()
		} finally {
			await api.updateSettings(request, { reimbursement_enabled: '1' })
		}
	})

	// --- Wächter-Ordner ----------------------------------------------------------------

	test('im Wächter-Ordner liegt der Beleg unter <Ordner>/<Ausgabejahr>/, bleibt aus dem Eingangskorb heraus und hängt nach der Auszahlung an der Buchung', async ({ request }) => {
		await dav.mkcol(request, 'admin', WATCH_FOLDER)
		await api.updateSettings(request, { storage_mode: 'watch', storage_user: 'admin', storage_path: WATCH_FOLDER })

		const created = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Beleg im Wächter-Ordner', fileName: 'watch-beleg.png', expenseDate: '2026-09-20' })).json()
		expect(await dav.exists(request, 'admin', `${WATCH_FOLDER}/2026/watch-beleg.png`)).toBe(true)

		// Im Eingangskorb „Zuzuordnen" erscheint die Datei nicht, und eine Buchung lässt sie sich nicht zuordnen.
		const inbox = await api.attachmentInbox(request)
		expect(inbox.files.map((f) => f.name)).not.toContain('watch-beleg.png')

		await api.approveReimbursement(request, created.id, { user: USERS.verwalter })
		await api.approveReimbursement(request, created.id, { user: USERS.buchhalter })

		const [bank, expense] = await api.accountsByNumber(request, BANK_ACCOUNT, '5300')
		const booking = await (await api.createBooking(request, { date: '2026-11-02', description: 'Erstattung im Wächter-Ordner', debitAccountId: expense.id, creditAccountId: bank.id, amount: 12.5 })).json()
		await api.payReimbursement(request, created.id, booking.id, '2026-11-02')

		// Die Datei bleibt, wo sie ist, und ist jetzt mit der Buchung verknüpft.
		expect(await dav.exists(request, 'admin', `${WATCH_FOLDER}/2026/watch-beleg.png`)).toBe(true)
		const attachments = await api.listAttachments(request, booking.id)
		expect(attachments).toHaveLength(1)
		expect(attachments[0]).toMatchObject({ fileName: 'watch-beleg.png', unlinkOnly: true })
		expect(attachments[0].fileId).toBeTruthy()
	})
})
