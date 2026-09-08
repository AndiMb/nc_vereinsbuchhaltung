import { test, expect } from '@playwright/test'
import { api, dav, findBooking, openApp, pickNcSelectOption, skipBookingTour, visibleSection, BANK_ACCOUNT, BELEG_PNG, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Der Wächter-Ordner für Belege: was in der Dateien-App abgelegt wird, meldet
// die Übersicht als „noch keiner Buchung zugewiesen" und lässt sich beim
// Buchen auswählen. Die Datei bleibt dabei liegen, wo sie ist.

const FOLDER = 'Belegordner'

async function createBooking(request, description, date) {
	const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
	return (await api.createBooking(request, {
		date,
		description,
		debitAccountId: bank.id,
		creditAccountId: income.id,
		amount: 5,
	})).json()
}

test.describe('Wächter-Ordner für Belege', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		// Der Ordner muss vorher da sein – die App legt ihn absichtlich nicht an.
		await dav.mkcol(request, 'admin', FOLDER)
		await dav.mkcol(request, 'admin', `${FOLDER}/2026`)
		await api.updateSettings(request, { storage_mode: 'watch', storage_user: 'admin', storage_path: FOLDER })
	})

	test.afterAll(async ({ request }) => {
		await api.resetBook(request)
		await api.updateSettings(request, { storage_mode: 'appdata', storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
		await dav.remove(request, 'admin', FOLDER)
	})

	test('die Einstellung verlangt einen vorhandenen Ordner', async ({ request }) => {
		const resp = await api.updateSettings(request, { storage_mode: 'watch', storage_user: 'admin', storage_path: 'gibt-es-nicht' }, { expectOk: false })
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toMatch(/existiert/)
		expect((await api.getSettings(request)).storage_mode).toBe('watch')
	})

	test('abgelegte Datei erscheint auf der Übersicht und wird beim Buchen verknüpft', async ({ page, request }) => {
		await dav.put(request, 'admin', `${FOLDER}/2026/rechnung.png`, BELEG_PNG, 'image/png')

		const file = (await api.attachmentInbox(request)).files.find((f) => f.name === 'rechnung.png')
		expect(file).toBeTruthy()
		expect(file.folder).toBe('2026')
		expect(file.journalIds).toEqual([])
		expect((await api.attachmentInboxSummary(request)).unassigned).toBeGreaterThanOrEqual(1)

		await openApp(page, USERS.verwalter)
		const tile = visibleSection(page).locator('.vbh-total--warn', { hasText: 'Noch keiner Buchung zugewiesen' })
		await expect(tile).toBeVisible()
		await tile.getByRole('button', { name: 'Ansehen' }).click()

		const inboxDialog = page.getByRole('dialog')
		await expect(inboxDialog.getByText('rechnung.png')).toBeVisible()
		await inboxDialog.locator('li', { hasText: 'rechnung.png' }).getByRole('button', { name: 'Buchung anlegen' }).click()

		// Die Datei wartet jetzt im Buchungsdialog, wie eine gewählte Upload-Datei.
		const dialog = page.getByRole('dialog')
		await expect(dialog.getByText('rechnung.png')).toBeVisible()
		await dialog.getByRole('button', { name: 'Einnahme' }).click()
		await skipBookingTour(dialog)
		await dialog.getByLabel('Betrag (€)', { exact: true }).fill('12')
		await pickNcSelectOption(dialog, '– Kategorie wählen –', 'Mitgliedsbeiträge')
		await dialog.locator('input[type="date"]').fill('2026-06-01')
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill('Rechnung aus dem Ordner')
		await dialog.getByRole('button', { name: 'Buchen', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		const booking = findBooking(await api.listJournal(request), 'Rechnung aus dem Ordner')
		const attachments = await api.listAttachments(request, booking.id)
		expect(attachments.map((a) => [a.fileName, a.fileId, a.missing, a.unlinkOnly])).toEqual([['rechnung.png', file.fileId, false, true]])
		expect(await dav.exists(request, 'admin', `${FOLDER}/2026/rechnung.png`)).toBe(true)
		const linked = (await api.attachmentInbox(request)).files.find((f) => f.fileId === file.fileId)
		expect(linked.journalIds).toEqual([booking.id])

		// Die Datei ist über die App lesbar, auch für Nutzer, denen der Ordner nicht gehört.
		const download = await api.raw(request, 'GET', `/attachments/${attachments[0].id}/download`, { user: USERS.revisor })
		expect(download.status()).toBe(200)
		expect((await download.body()).length).toBe(BELEG_PNG.length)
	})

	test('Upload landet im Jahresordner, Löschen lässt die Datei stehen', async ({ request }) => {
		const booking = await createBooking(request, 'Foto vom Handy', '2025-03-01')
		const attachment = await api.addAttachment(request, booking.id, { name: 'foto.png' })
		expect(attachment.fileId).toBeTruthy()
		expect(await dav.exists(request, 'admin', `${FOLDER}/2025/foto.png`)).toBe(true)

		await api.deleteAttachment(request, attachment.id)
		expect(await dav.exists(request, 'admin', `${FOLDER}/2025/foto.png`)).toBe(true)
		const again = (await api.attachmentInbox(request)).files.find((f) => f.fileId === attachment.fileId)
		expect(again.journalIds).toEqual([])
	})

	test('Umbenennen in der Dateien-App bricht die Verknüpfung nicht, Löschen meldet die Datei als fehlend', async ({ request }) => {
		await dav.put(request, 'admin', `${FOLDER}/quittung.png`, BELEG_PNG, 'image/png')
		const file = (await api.attachmentInbox(request)).files.find((f) => f.name === 'quittung.png')
		const booking = await createBooking(request, 'Quittung verknüpft', '2026-06-02')
		const linked = await api.linkAttachment(request, booking.id, file.fileId)
		expect(linked.fileId).toBe(file.fileId)

		await dav.move(request, 'admin', `${FOLDER}/quittung.png`, `${FOLDER}/2026/quittung-umbenannt.png`)
		let [after] = await api.listAttachments(request, booking.id)
		expect(after.missing).toBe(false)
		expect((await api.raw(request, 'GET', `/attachments/${linked.id}/download`)).status()).toBe(200)

		await dav.remove(request, 'admin', `${FOLDER}/2026/quittung-umbenannt.png`)
		;[after] = await api.listAttachments(request, booking.id)
		expect(after.missing).toBe(true)
		expect((await api.attachmentInboxSummary(request)).missing).toBe(1)
		const inbox = await api.attachmentInbox(request)
		expect(inbox.missing.map((m) => [m.journalId, m.description])).toEqual([[booking.id, 'Quittung verknüpft']])
	})
})
