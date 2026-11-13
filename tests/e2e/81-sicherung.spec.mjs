import { expect, test } from '@playwright/test'
import { existsSync, readFileSync, unlinkSync } from 'fs'
import { join } from 'path'
import {
	api,
	AUTH_DIR,
	authHeaders,
	BANK_ACCOUNT,
	BASE_URL,
	BELEG_PNG,
	INCOME_ACCOUNT,
	openSettingsPage,
	USERS,
} from './fixtures/nextcloud.mjs'

// Sicherung und Umzug (Issue #111): den ganzen Bestand über die Oberfläche
// sichern, den Bestand leeren und die Sicherung wieder einspielen – danach
// steht alles da wie vorher, und es lässt sich weiterarbeiten.

const PASSWORD = 'E2e-Sicherung-2026!'
const API = `${BASE_URL}/index.php/apps/vereinsbuchhaltung/api`
const MEMBER_IBAN = 'DE89370400440532013000'

/** Was vor dem Sichern und nach dem Einspielen gleich sein muss. */
async function snapshot(request, attachmentId) {
	const beleg = await request.get(`${API}/attachments/${attachmentId}/download`, { headers: authHeaders('admin') })
	return {
		accounts: await api.listAccounts(request),
		journal: await api.listJournal(request),
		members: await api.listMembers(request),
		mandates: await api.listMandates(request),
		periods: await api.listPeriods(request),
		belegStatus: beleg.status(),
		beleg: beleg.ok() ? (await beleg.body()).toString('base64') : '',
	}
}

test.describe('Sicherung und Umzug', () => {
	test.describe.configure({ mode: 'serial' })

	let backupFile
	let before
	let attachmentId
	let clubName

	test.beforeAll(async ({ request }) => {
		// Frisch anmelden: Nextcloud verlangt für diese Aktionen eine
		// Passwortbestätigung, die nach der Anmeldung 30 Minuten gilt.
		const cached = join(AUTH_DIR, `${USERS.admin}.json`)
		if (existsSync(cached)) { unlinkSync(cached) }

		await api.resetBook(request)
		await api.backupWipe(request)
		await api.seedDemo(request)
		await api.enableMembership(request)
		await api.setRole(request, USERS.buchhalter, 'buchhalter')

		const journal = await api.listJournal(request)
		attachmentId = (await api.addAttachment(request, journal[0].journal.id)).id
		const member = await api.createMember(request, { firstName: 'Sina', lastName: 'Sicherung', email: 'sina.sicherung@example.org' })
		await api.createMandate(request, { memberId: member.id, iban: MEMBER_IBAN, accountHolder: 'Sina Sicherung', signedAt: '2026-01-15' })

		clubName = (await api.backupStatus(request)).clubName
		before = await snapshot(request, attachmentId)
		expect(before.journal.length).toBeGreaterThan(0)
		expect(before.beleg).toBe(BELEG_PNG.toString('base64'))
		expect(JSON.stringify(before.mandates)).toContain(MEMBER_IBAN)
	})

	test.afterAll(async ({ request }) => {
		// Andere Specs setzen ihren Bestand selbst auf – hier bleibt nichts stehen.
		await api.backupWipe(request)
		await api.resetBook(request)
	})

	test('sichern, leeren und einspielen stellt den Bestand wieder her', async ({ page, request }, testInfo) => {
		test.setTimeout(180 * 1000)
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_sicherung')

		// --- Sicherung erstellen: zu kurzes Passwort sperrt den Knopf
		await section.locator('[data-test="backup-export-password"]').fill('kurz')
		await section.locator('[data-test="backup-export-repeat"]').fill('kurz')
		await expect(section.locator('[data-test="backup-export"]')).toBeDisabled()

		await section.locator('[data-test="backup-export-password"]').fill(PASSWORD)
		await section.locator('[data-test="backup-export-repeat"]').fill(PASSWORD)
		const downloadPromise = page.waitForEvent('download')
		await section.locator('[data-test="backup-export"]').click()
		const download = await downloadPromise
		expect(download.suggestedFilename()).toMatch(/\.vbhbackup$/)
		backupFile = testInfo.outputPath('sicherung.vbhbackup')
		await download.saveAs(backupFile)
		await expect(section.locator('[data-test="backup-export-result"]')).toContainText('Sicherung erstellt')

		// --- Einspielen ist gesperrt, solange der Bestand nicht leer ist
		await expect(section.locator('[data-test="backup-not-empty"]')).toBeVisible()
		await section.locator('[data-test="backup-file"]').setInputFiles(backupFile)
		await section.locator('[data-test="backup-import-password"]').fill(PASSWORD)
		await section.locator('[data-test="backup-inspect"]').click()
		await expect(section.locator('[data-test="backup-inspection"]')).toContainText(clubName)
		await expect(section.locator('[data-test="backup-blocker"]')).toContainText('nicht leer')
		await expect(section.locator('[data-test="backup-import"]')).toHaveCount(0)

		// --- Bestand vollständig leeren: nur mit dem Vereinsnamen
		await section.locator('[data-test="backup-wipe"]').click()
		const dialog = page.locator('[data-test="backup-wipe-dialog"]')
		await dialog.locator('[data-test="backup-wipe-confirmation"]').fill('irgendwas')
		await expect(dialog.locator('[data-test="backup-wipe-confirm"]')).toBeDisabled()
		await dialog.locator('[data-test="backup-wipe-confirmation"]').fill(clubName)
		await dialog.locator('[data-test="backup-wipe-confirm"]').click()
		await expect(section.getByText('Der Bestand ist leer.')).toBeVisible({ timeout: 30000 })
		expect(await api.listJournal(request)).toEqual([])
		expect(await api.listMembers(request)).toEqual([])
		expect((await api.backupStatus(request)).empty).toBe(true)

		// --- Falsches Passwort: klare Meldung, nichts wird eingespielt
		await section.locator('[data-test="backup-import-password"]').fill('Falsches-Passwort-1')
		await section.locator('[data-test="backup-inspect"]').click()
		await expect(page.getByText('Das Passwort passt nicht oder die Datei ist beschädigt.', { exact: true })).toBeVisible()
		await expect(section.locator('[data-test="backup-inspection"]')).toHaveCount(0)

		// --- Einspielen
		await section.locator('[data-test="backup-import-password"]').fill(PASSWORD)
		await section.locator('[data-test="backup-inspect"]').click()
		await expect(section.locator('[data-test="backup-inspection"]')).toContainText(clubName)
		await section.locator('[data-test="backup-import"]').click()
		await expect(section.locator('[data-test="backup-result"]')).toContainText('Die Sicherung ist eingespielt.', { timeout: 60000 })

		const after = await snapshot(request, attachmentId)
		expect(after.belegStatus).toBe(200)
		expect(after).toEqual(before)
	})

	test('nach dem Einspielen lässt sich weiterarbeiten', async ({ request }) => {
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		const maxBefore = Math.max(...before.journal.map((j) => j.journal.id))
		const resp = await api.createBooking(request, {
			date: before.journal[0].journal.date,
			description: 'Buchung nach dem Einspielen',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: '12.34',
		})
		expect((await resp.json()).id).toBeGreaterThan(maxBefore)

		const member = await api.createMember(request, { firstName: 'Nora', lastName: 'Nachher' })
		expect(member.id).toBeGreaterThan(Math.max(...before.members.map((m) => m.id)))
	})

	test('eine Sicherung lässt sich nicht in einen gefüllten Bestand einspielen', async ({ request }) => {
		const headers = authHeaders('admin')
		delete headers['Content-Type'] // multipart: Playwright setzt ihn samt Boundary
		const resp = await request.fetch(`${API}/backup/import`, {
			method: 'POST',
			headers,
			multipart: {
				file: { name: 'sicherung.vbhbackup', mimeType: 'application/octet-stream', buffer: readFileSync(backupFile) },
				password: PASSWORD,
			},
		})
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('nicht leer')
	})

	test('Buchhalter kommen an Sicherung und Leeren nicht heran', async ({ page, request }) => {
		const wipe = await api.backupWipe(request, { user: USERS.buchhalter, expectOk: false })
		expect(wipe.status()).toBe(403)
		const status = await request.get(`${API}/backup/status`, { headers: authHeaders(USERS.buchhalter) })
		expect(status.status()).toBe(403)
		const exported = await request.post(`${API}/backup/export`, { headers: authHeaders(USERS.buchhalter), data: { password: PASSWORD, withFiles: true } })
		expect(exported.status()).toBe(403)
		expect((await api.listJournal(request)).length).toBeGreaterThan(0)

		// Die Einstellungsseite der App gibt es für Buchhalter nicht.
		await openSettingsPageAs(page, USERS.buchhalter)
		await expect(page.locator('#settings-section_sicherung')).toHaveCount(0)
	})
})

/** Einstellungsseite ohne die Wartebedingungen von openSettingsPage (die Seite darf hier fehlen). */
async function openSettingsPageAs(page, username) {
	await page.goto(`${BASE_URL}/login`)
	await page.getByRole('textbox', { name: /account name|email|kontoname/i }).fill(username)
	await page.getByRole('textbox', { name: /password|passwort/i }).fill(username)
	await page.locator('form button[type="submit"]').first().click()
	await page.waitForURL(/.*\/apps\/.*/, { timeout: 10000 })
	await page.goto(`${BASE_URL}/index.php/settings/admin/vereinsbuchhaltung`)
	await page.waitForLoadState('domcontentloaded')
}
