import { expect, test } from '@playwright/test'
import { mkdirSync } from 'fs'
import { join } from 'path'
import { capturedMails, startMailCapture, stopMailCapture } from './fixtures/mail-capture.mjs'
import { api, openApp, switchTab, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Auslagenerstattung, Teil 2 (siehe 67): die Ansichten und Sprachen.
//   - „Mein Bereich" mit beiden Unterreitern (Beitrag und Auslagen), Deep-Link
//   - englische Oberfläche und englische Mails
//   - deutsche Mails an das Mitglied sprechen informell (Du-Fassung)
//   - Revisor: liest, sieht die IBAN maskiert, hat keinen Knopf zum Entscheiden oder Auszahlen
//   - Handy-Breite der Verwaltungssicht
//   - „Alle Daten löschen" räumt die Auslagen mit
//
// Mit E2E_SHOTS=<Ordner> legt die Spec zusätzlich Screenshots der Ansichten ab (Rundgang, nicht Teil der Prüfung).

const SHOTS = process.env.E2E_SHOTS
if (SHOTS) {
	mkdirSync(SHOTS, { recursive: true })
}
async function shot(page, name) {
	if (SHOTS) {
		await page.waitForTimeout(700) // Einblendung der Dialoge abwarten
		await page.screenshot({ path: join(SHOTS, `${name}.png`), fullPage: false })
	}
}

const DE_MEMBER = { firstName: 'Erika', lastName: 'Musterfrau', email: 'erika.ansicht@example.org' }
const EN_MEMBER = { firstName: 'Emma', lastName: 'English', email: 'emma.ansicht@example.org' }
const EMAILS = {
	[USERS.admin]: 'admin.ansicht@example.org',
	[USERS.verwalter]: 'test1.ansicht@example.org',
	[USERS.buchhalter]: 'test2.ansicht@example.org',
}

test.describe.configure({ mode: 'serial' })

async function unlinkExisting(request, ncUserId) {
	const existing = (await api.listMembers(request)).find((m) => m.ncUserId === ncUserId)
	if (existing) {
		await api.unlinkMember(request, existing.id)
		await api.deleteMember(request, existing.id)
	}
}

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

/** Reiter „Buchungen → Auslagen" in der Verwaltungssicht. */
async function openAuslagenSicht(page, user) {
	await openApp(page, user, { booted: true })
	await switchTab(page, 'Buchungen')
	const subtab = visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' })
	await subtab.click()
	await expect(subtab).toHaveClass(/active/)
}

/** Zwei Freigaben durch die Verwalter test1 und test2. */
async function approveFully(request, id) {
	await api.approveReimbursement(request, id, { user: USERS.verwalter })
	await api.approveReimbursement(request, id, { user: USERS.buchhalter })
}

test.describe('Auslagenerstattung: Ansichten und Sprachen', () => {
	const memberIds = []
	let approvedEnglishClaim

	test.beforeAll(async ({ request }) => {
		test.setTimeout(120000)
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.setRole(request, USERS.buchhalter, 'verwalter')
		for (const [uid, email] of Object.entries(EMAILS)) {
			await api.setUserEmail(request, uid, email)
		}
		for (const [uid, member] of [[USERS.ohneRolle, DE_MEMBER], [USERS.englisch, EN_MEMBER]]) {
			await unlinkExisting(request, uid)
			const created = await api.createMember(request, member)
			memberIds.push(created.id)
			await api.linkMember(request, created.id, uid)
		}
		await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '2', self_service_enabled: '1' })
		await startMailCapture()
	})

	test.afterAll(async ({ request }) => {
		await stopMailCapture()
		await api.updateSettings(request, { reimbursement_enabled: '0', self_service_enabled: '0' }, { expectOk: false })
		await api.setRole(request, USERS.buchhalter, 'buchhalter')
		for (const id of memberIds) {
			await api.unlinkMember(request, id, { expectOk: false })
			await api.deleteMember(request, id, { expectOk: false })
		}
		await api.resetBook(request)
	})

	// --- Mein Bereich mit beiden Unterreitern ----------------------------------------

	test('„Mein Bereich" zeigt Beitrag und Auslagen als Unterreiter und findet den Deep-Link wieder', async ({ page, request }) => {
		await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Material Jugendfreizeit', amount: '12.50' })

		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Bereich').click()
		const section = visibleSection(page)
		const subtabs = section.locator('.vbh-subtabs')
		await expect(subtabs.getByRole('button', { name: 'Beitrag' })).toHaveClass(/active/)
		await expect(section.getByRole('heading', { name: 'Meine Stammdaten' })).toBeVisible()
		await shot(page, '01-mein-bereich-beitrag')

		await subtabs.getByRole('button', { name: 'Auslagen' }).click()
		await expect(section.getByRole('heading', { name: 'Meine Auslagen' })).toBeVisible()
		await expect(section.locator('tr.vbh-reimb-row', { hasText: 'Material Jugendfreizeit' })).toContainText('Eingereicht')
		await shot(page, '02-mein-bereich-auslagen-liste')

		await subtabs.getByRole('button', { name: 'Beitrag' }).click()
		await expect(section.getByRole('heading', { name: 'Meine Stammdaten' })).toBeVisible()
	})

	test('Deep-Link /self/reimbursements: wer Rolle und Mitgliedschaft hat, landet im Unterreiter Auslagen', async ({ page, request }) => {
		// Personalunion: der Revisor ist selbst Mitglied. (Konten ohne Rolle haben keine URL-Anbindung.)
		const rita = await api.createMember(request, { firstName: 'Rita', lastName: 'Revisor' })
		memberIds.push(rita.id)
		await api.linkMember(request, rita.id, USERS.revisor)
		await api.submitReimbursement(request, { user: USERS.revisor, purpose: 'Antrag der Revisorin' })

		await openApp(page, USERS.revisor, { booted: true })
		await page.goto(`${page.url().split('/index.php')[0]}/index.php/apps/vereinsbuchhaltung/self/reimbursements`)
		const section = visibleSection(page)
		await expect(section.getByRole('heading', { name: 'Meine Auslagen' })).toBeVisible()
		await expect(section.locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' })).toHaveClass(/active/)
		await expect(section.locator('tr.vbh-reimb-row', { hasText: 'Antrag der Revisorin' })).toBeVisible()

		await section.locator('.vbh-subtabs').getByRole('button', { name: 'Beitrag' }).click()
		await expect(page).toHaveURL(/\/self(\?|$)/)
		await section.locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' }).click()
		await expect(page).toHaveURL(/\/self\/reimbursements/)
	})

	test('die Detailansicht des Mitglieds zeigt Status, Belege, Verlauf und Knöpfe', async ({ page }) => {
		await openApp(page, USERS.ohneRolle)
		await tabButton(page, 'Mein Bereich').click()
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' }).click()
		await visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Material Jugendfreizeit' }).click()
		const dialog = page.getByRole('dialog', { name: /Auslage vom/ })
		await expect(dialog).toContainText('12,50')
		await expect(dialog).toContainText('Antrag eingereicht')
		await expect(dialog.getByRole('button', { name: 'Antrag zurückziehen' })).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Beleg hinzufügen' })).toBeVisible()
		await shot(page, '03-mitglied-detail')
	})

	// --- Deutsche Mails sprechen das Mitglied informell an ----------------------------------

	test('die Mails an ein deutschsprachiges Mitglied duzen', async ({ request }) => {
		const claim = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Mail-Wortlaut Freigabe' })).json()
		await approveFully(request, claim.id)
		const approved = await waitForMail(DE_MEMBER.email, /Deine Auslage wurde freigegeben/)
		expect(approved.text).toContain('Deinem Antrag')
		expect(approved.text).not.toMatch(/\bIhr(e|em|en)?\b/)

		const rejectedClaim = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Mail-Wortlaut Ablehnung' })).json()
		await api.rejectReimbursement(request, rejectedClaim.id, 'Bitte den Kassenbon beilegen', { user: USERS.verwalter })
		const rejected = await waitForMail(DE_MEMBER.email, /Deine Auslage wurde abgelehnt/)
		expect(rejected.text).toContain('Bitte den Kassenbon beilegen')
		expect(rejected.text).toContain('wende dich')
		if (SHOTS) {
			await import('fs').then((fs) => fs.writeFileSync(join(SHOTS, 'mail-freigabe.txt'), `${approved.subject}\n\n${approved.text}`))
			await import('fs').then((fs) => fs.writeFileSync(join(SHOTS, 'mail-ablehnung.txt'), `${rejected.subject}\n\n${rejected.text}`))
		}
	})

	// --- Englisch -------------------------------------------------------------------

	test('englische Oberfläche und englische Mails', async ({ page, request }) => {
		approvedEnglishClaim = await (await api.submitReimbursement(request, { user: USERS.englisch, purpose: 'Youth camp supplies', amount: '25.00' })).json()

		await openApp(page, USERS.englisch)
		await tabButton(page, 'My area').click()
		const section = visibleSection(page)
		await section.locator('.vbh-subtabs').getByRole('button', { name: 'Expenses' }).click()
		await expect(section.getByRole('heading', { name: 'My expense claims' })).toBeVisible()
		await expect(section.locator('tr.vbh-reimb-row', { hasText: 'Youth camp supplies' })).toContainText('Submitted')
		await shot(page, '04-englisch-liste')

		await section.getByRole('button', { name: 'Submit expense claim' }).click()
		const dialog = page.getByRole('dialog', { name: 'Submit expense claim' })
		await expect(dialog.getByLabel('Amount (€)', { exact: true })).toBeVisible()
		await expect(dialog).toContainText('Still needed')
		await expect(dialog).toContainText('Drop the receipt here or choose it')
		await shot(page, '05-englisch-dialog')
		await dialog.getByRole('button', { name: 'Cancel' }).click()

		await approveFully(request, approvedEnglishClaim.id)
		const mail = await waitForMail(EN_MEMBER.email, /Your expense claim has been approved/)
		expect(mail.text).toContain('25.00')
		expect(mail.text).not.toMatch(/Auslage|Antrag/)
		if (SHOTS) {
			await import('fs').then((fs) => fs.writeFileSync(join(SHOTS, 'mail-englisch.txt'), `${mail.subject}\n\n${mail.text}`))
		}
	})

	// --- Revisor --------------------------------------------------------------------

	test('der Revisor liest alles, sieht die IBAN maskiert und kann nichts entscheiden', async ({ page }) => {
		await openAuslagenSicht(page, USERS.revisor)
		await visibleSection(page).locator('.vbh-reimb-staff .vbh-chip', { hasText: /^Alle\s*\d/ }).click()
		await expect(visibleSection(page).locator('tr.vbh-reimb-row').first()).toBeVisible()
		await shot(page, '06-revisor-liste')

		await visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Youth camp supplies' }).click()
		const dialog = page.getByRole('dialog', { name: /Auslage #\d+ von Emma English/ })
		await expect(dialog).toContainText('Freigegeben')
		await expect(dialog).toContainText(/DE89[\s•]+30 00/)
		await expect(dialog).not.toContainText(/DE89 3704/)
		for (const name of ['Freigeben', 'Ablehnen', 'Auszahlung buchen']) {
			await expect(dialog.getByRole('button', { name }), `„${name}“ darf der Revisor nicht sehen`).toHaveCount(0)
		}
		// Die Historie nennt dem Revisor die Namen.
		await expect(dialog).toContainText('test1 hat freigegeben')
		await shot(page, '07-revisor-detail')
	})

	// --- Verwalter (Rundgang) ------------------------------------------------------------

	test('Verwaltersicht: Liste, Filter, Einzelansicht und Ablehnen-Dialog', async ({ page, request }) => {
		const open = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Offener Antrag für den Rundgang' })).json()

		await openAuslagenSicht(page, USERS.verwalter)
		const panel = visibleSection(page).locator('.vbh-reimb-staff')
		await expect(panel.locator('.vbh-chip', { hasText: /^Zu prüfen\s*\d/ })).toBeVisible()
		await expect(panel.locator('tr.vbh-reimb-row', { hasText: 'Offener Antrag für den Rundgang' })).toContainText('Ihre Freigabe')
		await shot(page, '08-verwalter-liste')

		await panel.locator('tr.vbh-reimb-row', { hasText: 'Offener Antrag für den Rundgang' }).click()
		const dialog = page.getByRole('dialog', { name: /Auslage #\d+ von Erika Musterfrau/ })
		await expect(dialog.getByRole('button', { name: 'Freigeben' })).toBeVisible()
		await shot(page, '09-verwalter-detail')

		await dialog.getByRole('button', { name: 'Ablehnen' }).click()
		await shot(page, '10-verwalter-ablehnen')
		await page.getByRole('dialog', { name: 'Auslage ablehnen' }).getByRole('button', { name: 'Abbrechen' }).click()
		expect((await (await api.getReimbursement(request, open.id)).json()).status).toBe('submitted')
	})

	// --- Handy ------------------------------------------------------------------------

	test('auf Handy-Breite lässt sich ein Antrag prüfen und freigeben, ohne seitlich zu scrollen', async ({ page, request }) => {
		const phone = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Handy-Antrag' })).json()
		await page.setViewportSize({ width: 390, height: 844 })

		await openApp(page, USERS.verwalter, { booted: true })
		// Am Handy steht die Navigation unten; „Buchungen" und der Unterreiter „Auslagen".
		await page.locator('nav, .vbh-mobilenav').getByRole('button', { name: 'Buchungen' }).first().click()
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Auslagen' }).click()
		const row = visibleSection(page).locator('tr.vbh-reimb-row', { hasText: 'Handy-Antrag' })
		await expect(row).toBeVisible()
		await shot(page, '11-handy-liste')
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
		expect(overflow, 'Die Seite darf nicht breiter sein als das Fenster').toBeLessThanOrEqual(1)

		await row.click()
		const dialog = page.getByRole('dialog', { name: /Auslage #\d+ von Erika Musterfrau/ })
		await expect(dialog).toBeVisible()
		const box = await dialog.locator('.modal-container').boundingBox()
		expect(box.width).toBeLessThanOrEqual(390)
		await dialog.getByRole('button', { name: 'Freigeben' }).scrollIntoViewIfNeeded()
		await shot(page, '12-handy-detail')
		await dialog.getByRole('button', { name: 'Freigeben' }).click()
		await expect(dialog).toContainText('1 von 2 Freigaben')
		expect((await (await api.getReimbursement(request, phone.id)).json()).status).toBe('partially_approved')
	})

	// --- Zurücksetzen -------------------------------------------------------------------

	test('„Alle Daten löschen" räumt Anträge, Historie und offene Posten der Auslagen mit', async ({ request }) => {
		expect((await (await api.listReimbursements(request)).json()).items.length).toBeGreaterThan(0)

		await api.resetBook(request)

		expect((await (await api.listReimbursements(request)).json()).items).toEqual([])
		expect((await api.getJson(request, '/open-items')).filter((i) => i.reimbursementId)).toEqual([])
		// Mitglieder und Einstellungen bleiben.
		expect((await api.listMembers(request)).some((m) => m.displayName === 'Erika Musterfrau')).toBe(true)
		expect((await api.getSettings(request)).reimbursement_enabled).toBe(true)
	})
})
