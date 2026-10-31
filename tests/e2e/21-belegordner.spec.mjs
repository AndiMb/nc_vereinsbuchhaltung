import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BELEG_PNG, dav, findBooking, INCOME_ACCOUNT, neuAnlegen, openApp, openSettingsPage, pickNcSelectOption, skipBookingTour, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

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

	test('der Ordnerbaum in den Einstellungen wählt den Wächter-Ordner per Klick', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const section = page.locator('#settings-section_belege')
		await section.locator('select').first().selectOption('watch')
		await section.locator('select').nth(1).selectOption('admin')

		await section.getByRole('button', { name: 'Ordner wählen…' }).click()
		const picker = page.getByRole('dialog')
		await picker.getByRole('button', { name: FOLDER, exact: true }).click()
		await picker.getByRole('button', { name: '2026', exact: true }).click()
		await picker.getByRole('button', { name: 'Übernehmen' }).click()
		await expect(picker).toBeHidden()
		// Im Wächter-Modus ist das Pfadfeld nur Anzeige – getippt wird nichts.
		await expect(section.locator('input[type="text"]')).toHaveValue(`${FOLDER}/2026`)
		await expect(section.locator('input[type="text"]')).toHaveAttribute('readonly', '')

		await section.getByRole('button', { name: 'Speichern' }).click()
		await expect(page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert' }).first()).toBeVisible()
		const settings = await api.getSettings(request)
		expect(settings.storage_mode).toBe('watch')
		expect(settings.storage_path).toBe(`${FOLDER}/2026`)
		await api.updateSettings(request, { storage_mode: 'watch', storage_user: 'admin', storage_path: FOLDER })
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

	test('„Aus Ordner wählen" öffnet die Auswahl neben dem Formular, Suchfeld direkt bedienbar', async ({ page, request }) => {
		await dav.put(request, 'admin', `${FOLDER}/2026/suchtest-eins.png`, BELEG_PNG, 'image/png')
		await dav.put(request, 'admin', `${FOLDER}/2026/suchtest-zwei.png`, BELEG_PNG, 'image/png')

		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const bookingDialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await skipBookingTour(bookingDialog)
		// Erst wenn der Buchungsdialog den Fokus hält, ist seine Fokusfalle aktiv (nach der Einblend-Animation).
		// Öffnet das Popup vorher, schiebt sich die Falle des Buchungsdialogs danach darüber und zieht den Fokus
		// zurück – so schnell klickt kein Mensch, ein Runner schon.
		await expect(bookingDialog.locator(':focus')).toHaveCount(1)
		await bookingDialog.getByRole('button', { name: 'Aus Ordner wählen' }).click()

		// Kein zweiter Dialog darüber: die Auswahl steht als Seitenleiste im
		// Buchungsdialog, das Formular bleibt daneben sichtbar. Sofort tippen,
		// ohne vorher zu klicken – das Suchfeld hat beim Öffnen den Fokus.
		await expect(page.getByRole('dialog')).toHaveCount(1)
		const folderDialog = bookingDialog.getByRole('complementary', { name: 'Beleg aus Ordner wählen' })
		await expect(folderDialog).toBeVisible()
		await expect(bookingDialog.getByText('Buchungstext')).toBeVisible()
		await expect(folderDialog.getByPlaceholder(/Dateiname, Ordner/)).toBeFocused()
		await page.keyboard.type('suchtest-eins')
		await expect(folderDialog.getByText('suchtest-eins.png')).toBeVisible()
		await expect(folderDialog.getByText('suchtest-zwei.png')).toBeHidden()

		// Am Desktop übernimmt ein Klick die Datei sofort: sie wandert links in
		// die Warteliste und fällt aus der Auswahl, die Leiste bleibt offen.
		// Der Fokus landet im Suchfeld statt (über die Fokusfalle) oben im
		// Formular – sonst sprang die linke Spalte bei jedem Klick nach oben.
		await folderDialog.getByRole('button', { name: /Auswählen: suchtest-eins.png/ }).click()
		await expect(bookingDialog.locator('.vbh-booking-main').getByText('suchtest-eins.png')).toBeVisible()
		await expect(folderDialog.getByText('suchtest-eins.png')).toBeHidden()
		await expect(folderDialog).toBeVisible()
		await expect(folderDialog.getByPlaceholder(/Dateiname, Ordner/)).toBeFocused()
	})

	test('die Seitenleiste merkt sich, ob sie zuletzt offen war – mobil geht sie nie von selbst auf (#230)', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		// Erst öffnen, wenn die Konten da sind: sonst trägt App.vue das
		// Standard-Geldkonto nach dem Öffnen nach, das leere Formular gilt als
		// geändert und „Abbrechen“ fragt nach dem Verwerfen.
		await expect(page.getByText('1000 Kasse').first()).toBeVisible()
		await neuAnlegen(page)
		let bookingDialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await skipBookingTour(bookingDialog)
		await expect(bookingDialog.locator(':focus')).toHaveCount(1)
		const folderPanel = () => page.getByRole('complementary', { name: 'Beleg aus Ordner wählen' })
		await expect(folderPanel()).toHaveCount(0)
		await bookingDialog.getByRole('button', { name: 'Aus Ordner wählen' }).click()
		await expect(folderPanel()).toBeVisible()

		// Schließen, während die Leiste offen ist, ändert die gemerkte Wahl nicht.
		await page.getByRole('dialog').getByRole('button', { name: 'Abbrechen' }).click()
		await expect(page.getByRole('dialog')).toHaveCount(0)
		await neuAnlegen(page)
		bookingDialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await expect(folderPanel()).toBeVisible()
		// Von selbst aufgegangen: der Fokus bleibt im Formular, nicht im Suchfeld der Leiste.
		await expect(bookingDialog.locator('.vbh-booking-main :focus')).toHaveCount(1)

		// Mobil ersetzt die Leiste das Formular – dort bleibt es beim Knopf.
		await page.getByRole('dialog').getByRole('button', { name: 'Abbrechen' }).click()
		await expect(page.getByRole('dialog')).toHaveCount(0)
		await page.setViewportSize({ width: 390, height: 844 })
		// Mobil steht statt „Neu“ der schwebende Plus-Knopf unten rechts.
		await page.getByRole('button', { name: /^Neu(e Buchung)? anlegen$/ }).click()
		const buchungItem = page.getByRole('menuitem', { name: 'Buchung', exact: true })
		if (await buchungItem.isVisible().catch(() => false)) { await buchungItem.click() }
		await expect(page.getByRole('dialog')).toHaveCount(1)
		await expect(page.getByText('Buchungstext')).toBeVisible()
		await expect(folderPanel()).toHaveCount(0)
		await page.getByRole('dialog').getByRole('button', { name: 'Abbrechen' }).click()
		await expect(page.getByRole('dialog')).toHaveCount(0)

		// Zugeklappt bleibt sie beim nächsten Öffnen zu.
		await page.setViewportSize({ width: 1366, height: 900 })
		await neuAnlegen(page)
		bookingDialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await expect(folderPanel()).toBeVisible()
		await bookingDialog.getByRole('button', { name: 'Aus Ordner wählen' }).click()
		await expect(folderPanel()).toHaveCount(0)
		await page.getByRole('dialog').getByRole('button', { name: 'Abbrechen' }).click()
		await expect(page.getByRole('dialog')).toHaveCount(0)
		await neuAnlegen(page)
		await expect(page.getByRole('dialog', { name: 'Neue Buchung' })).toBeVisible()
		await expect(folderPanel()).toHaveCount(0)
	})

	test('Belegvorschau steht neben Formular und Liste, die angesehene Datei ist markiert (#231)', async ({ page, request }) => {
		await dav.put(request, 'admin', `${FOLDER}/2026/vorschau-a.png`, BELEG_PNG, 'image/png')
		await dav.put(request, 'admin', `${FOLDER}/2026/vorschau-b.png`, BELEG_PNG, 'image/png')

		// Breites Fenster: drei Spalten. Darunter stünde die Vorschau anstelle der Liste.
		await page.setViewportSize({ width: 1920, height: 1080 })
		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const bookingDialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await skipBookingTour(bookingDialog)
		await expect(bookingDialog.locator(':focus')).toHaveCount(1)
		await bookingDialog.getByRole('button', { name: 'Aus Ordner wählen' }).click()
		const folderDialog = bookingDialog.getByRole('complementary', { name: 'Beleg aus Ordner wählen' })
		await folderDialog.getByPlaceholder(/Dateiname, Ordner/).fill('vorschau-')

		const names = folderDialog.locator('.vbh-folder-item .vbh-attachment-name')
		await expect(names).toHaveCount(2)
		const [first, second] = (await names.allTextContents()).map((n) => n.trim())

		// Ein Klick auf den Namen zeigt die Datei in der dritten Spalte statt in einem neuen Tab.
		await names.first().click()
		const preview = bookingDialog.getByRole('complementary', { name: 'Belegvorschau' })
		await expect(preview.locator('img')).toBeVisible()
		await expect(preview.locator('.vbh-receipt-preview-name')).toHaveText(first)
		await expect(bookingDialog.getByText('Buchungstext')).toBeVisible()
		await expect(folderDialog.locator('.vbh-folder-item--previewed')).toContainText(first)

		// ↑/↓ in der Liste blättern, die Markierung wandert mit.
		await page.keyboard.press('ArrowDown')
		await expect(preview.locator('.vbh-receipt-preview-name')).toHaveText(second)
		await expect(folderDialog.locator('.vbh-folder-item--previewed')).toContainText(second)

		// Übernehmen lässt die Vorschau stehen.
		await folderDialog.getByRole('button', { name: `Auswählen: ${second}` }).click()
		await expect(preview.getByText('übernommen')).toBeVisible()
	})

	test('Belegvorschau im schmalen Fenster und beim Wechsel der Fensterbreite per Tastatur bedienbar', async ({ page, request }) => {
		for (const name of ['schmal-a.png', 'schmal-b.png', 'schmal-c.png']) {
			await dav.put(request, 'admin', `${FOLDER}/2026/${name}`, BELEG_PNG, 'image/png')
		}

		await page.setViewportSize({ width: 1366, height: 900 })
		await openApp(page, USERS.verwalter)
		await neuAnlegen(page)
		const bookingDialog = page.getByRole('dialog', { name: 'Neue Buchung' })
		await skipBookingTour(bookingDialog)
		await expect(bookingDialog.locator(':focus')).toHaveCount(1)
		await bookingDialog.getByRole('button', { name: 'Aus Ordner wählen' }).click()
		const folderDialog = bookingDialog.getByRole('complementary', { name: 'Beleg aus Ordner wählen' })
		const search = folderDialog.getByPlaceholder(/Dateiname, Ordner/)
		await search.fill('schmal-')
		const names = folderDialog.locator('.vbh-folder-item .vbh-attachment-name')
		await expect(names).toHaveCount(3)
		const order = (await names.allTextContents()).map((n) => n.trim())

		// Umschalt+↓ im Suchfeld markiert Text und blättert nicht.
		await names.first().click()
		const fallback = folderDialog.locator('.vbh-receipt-preview')
		const shown = fallback.locator('.vbh-receipt-preview-name')
		await expect(shown).toHaveText(order[0])
		await folderDialog.getByRole('button', { name: 'Liste' }).click()
		await search.focus()
		await page.keyboard.press('Shift+ArrowDown')
		await expect(folderDialog.locator('.vbh-folder-item--previewed')).toContainText(order[0])

		// Schmal: die Vorschau steht anstelle der Liste und hat den Fokus, ↓ blättert.
		await names.first().click()
		await expect(fallback).toBeVisible()
		await expect(search).toBeHidden()
		await page.keyboard.press('ArrowDown')
		await expect(shown).toHaveText(order[1])

		// Breiter: drei Spalten, der Fokus wandert auf die markierte Zeile, ↓ blättert weiter.
		await page.setViewportSize({ width: 1920, height: 1080 })
		const preview = bookingDialog.getByRole('complementary', { name: 'Belegvorschau' })
		await expect(preview.locator('.vbh-receipt-preview-name')).toHaveText(order[1])
		await expect(folderDialog.locator('.vbh-folder-item--previewed .vbh-attachment-name')).toBeFocused()
		await page.keyboard.press('ArrowDown')
		await expect(preview.locator('.vbh-receipt-preview-name')).toHaveText(order[2])

		// Wieder schmal: die Vorschau rückt anstelle der Liste und bekommt den Fokus.
		await page.setViewportSize({ width: 1366, height: 900 })
		await expect(fallback).toBeVisible()
		await page.keyboard.press('ArrowUp')
		await expect(shown).toHaveText(order[1])
	})

	test('Beleg für markierte Buchungen: Übernehmen steht rechts unten im Dialog, nicht unter der Liste', async ({ page, request }) => {
		await createBooking(request, 'Fußzeile im Ordner-Dialog', '2026-10-01')
		const booking = findBooking(await api.listJournal(request), 'Fußzeile im Ordner-Dialog')
		await dav.put(request, 'admin', `${FOLDER}/2026/fusszeile.png`, BELEG_PNG, 'image/png')

		await page.setViewportSize({ width: 1920, height: 1080 })
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await page.getByRole('checkbox', { name: `Buchung #${booking.entryNo} auswählen` }).check()
		await page.getByRole('button', { name: 'Beleg zuordnen…' }).click()

		const dialog = page.getByRole('dialog', { name: 'Beleg für 1 Buchung wählen' })
		await dialog.getByPlaceholder(/Dateiname, Ordner/).fill('fusszeile')
		await expect(dialog.getByRole('button', { name: 'Übernehmen', exact: true })).toBeDisabled()
		await dialog.getByRole('checkbox', { name: 'Auswählen: fusszeile.png' }).check()

		const take = dialog.getByRole('button', { name: '1 übernehmen' })
		await expect(take).toBeEnabled()
		await expect(dialog.locator('.vbh-folder-dialog-list').getByRole('button', { name: '1 übernehmen' })).toHaveCount(0)
		await expect(dialog.getByRole('button', { name: 'Abbrechen' })).toBeVisible()
		await take.click()
		await expect(dialog).toBeHidden()
	})

	test('Eingangskorb: beschädigtes PDF bekommt einen Hinweis, Pfeiltasten springen von Kästchen zu Kästchen', async ({ page, request }) => {
		// Ohne startxref – der PDF-Viewer des Browsers zeigte dazu einen Fehlerdialog,
		// dessen „Schließen" in Edge den ganzen App-Tab schloss.
		const kaputt = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n')
		await dav.put(request, 'admin', `${FOLDER}/korb-kaputt.pdf`, kaputt, 'application/pdf')
		await dav.put(request, 'admin', `${FOLDER}/korb-bild.png`, BELEG_PNG, 'image/png')

		await page.setViewportSize({ width: 1920, height: 1080 })
		await openApp(page, USERS.verwalter)
		const tile = visibleSection(page).locator('.vbh-total--warn', { hasText: 'Noch keiner Buchung zugewiesen' })
		await tile.getByRole('button', { name: 'Ansehen' }).click()
		const inbox = page.getByRole('dialog', { name: 'Belege im Wächter-Ordner' })
		await expect(inbox.getByText('Datei anklicken für Vorschau')).toBeVisible()
		await inbox.getByPlaceholder(/Dateiname, Ordner/).fill('korb-')

		await inbox.getByRole('button', { name: 'korb-kaputt.pdf', exact: true }).click()
		await expect(inbox.getByText('Die PDF-Datei scheint unvollständig oder beschädigt zu sein.')).toBeVisible()
		await expect(inbox.locator('.vbh-receipt-preview iframe')).toHaveCount(0)

		const boxes = inbox.locator('.vbh-folder-item input[type="checkbox"]')
		await expect(boxes).toHaveCount(2)
		const rows = inbox.locator('.vbh-folder-item')
		const firstName = (await rows.first().locator('.vbh-attachment-name').textContent()).trim()
		await rows.first().locator('.vbh-attachment-name').click()
		await boxes.first().focus()
		await page.keyboard.press('ArrowDown')
		await expect(boxes.nth(1)).toBeFocused()
		await expect(inbox.locator('.vbh-folder-item--previewed')).not.toContainText(firstName)
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

		// Hinausgeschoben gilt als fehlend – die Datei-ID bleibt, zurück im Ordner ist sie wieder da.
		await dav.move(request, 'admin', `${FOLDER}/2026/quittung-umbenannt.png`, 'quittung-draussen.png')
		;[after] = await api.listAttachments(request, booking.id)
		expect(after.missing).toBe(true)
		expect((await api.attachmentInboxSummary(request)).missing).toBe(1)
		await dav.move(request, 'admin', 'quittung-draussen.png', `${FOLDER}/2026/quittung-umbenannt.png`)
		;[after] = await api.listAttachments(request, booking.id)
		expect(after.missing).toBe(false)
		expect((await api.raw(request, 'POST', `/journal/${booking.id}/attachments/link`, { data: { fileId: file.fileId } })).status()).toBe(400)

		await dav.remove(request, 'admin', `${FOLDER}/2026/quittung-umbenannt.png`)
		;[after] = await api.listAttachments(request, booking.id)
		expect(after.missing).toBe(true)
		expect((await api.attachmentInboxSummary(request)).missing).toBe(1)
		const inbox = await api.attachmentInbox(request)
		expect(inbox.missing.map((m) => [m.journalId, m.description])).toEqual([[booking.id, 'Quittung verknüpft']])
	})

	test('Dubletten werden erkannt und lassen sich in einen Unterordner verschieben', async ({ page, request }) => {
		// Eigener Inhalt – BELEG_PNG liegt in diesem Ordner schon mehrfach.
		const inhalt = Buffer.from('%PDF-1.4\n% Dublettentest ' + Date.now() + '\n%%EOF\n')
		await dav.mkcol(request, 'admin', `${FOLDER}/dup`)
		await dav.put(request, 'admin', `${FOLDER}/dup/original.pdf`, inhalt, 'application/pdf')
		await dav.put(request, 'admin', `${FOLDER}/dup/kopie.pdf`, inhalt, 'application/pdf')
		await dav.put(request, 'admin', `${FOLDER}/kopie.pdf`, inhalt, 'application/pdf')
		const files = (await api.attachmentInbox(request)).files
		const id = (folder, name) => files.find((f) => f.folder === folder && f.name === name).fileId
		const booking = await createBooking(request, 'Original verbucht', '2026-07-01')
		await api.linkAttachment(request, booking.id, id('dup', 'original.pdf'))

		const { groups } = await api.attachmentInboxDuplicates(request)
		const group = groups.find((g) => g.files.some((f) => f.fileId === id('dup', 'original.pdf')))
		expect(group.files.map((f) => [f.name, f.folder, f.verdict]).sort()).toEqual([
			['kopie.pdf', '', 'removable'],
			['kopie.pdf', 'dup', 'removable'],
			['original.pdf', 'dup', 'assigned'],
		])
		expect(group.doubleBooked).toBe(false)

		// Aus dem Ordner hinaus und als Revisor geht es nicht.
		expect((await api.raw(request, 'POST', '/attachments/inbox/move', { data: { fileIds: [id('', 'kopie.pdf')], target: '../raus' } })).status()).toBe(400)
		expect((await api.raw(request, 'POST', '/attachments/inbox/move', { user: USERS.revisor, data: { fileIds: [id('', 'kopie.pdf')], target: '_Dubletten' } })).status()).toBe(403)

		await openApp(page, USERS.verwalter)
		await visibleSection(page).locator('.vbh-total--warn', { hasText: 'Noch keiner Buchung zugewiesen' }).getByRole('button', { name: 'Ansehen' }).click()
		const inbox = page.getByRole('dialog', { name: 'Belege im Wächter-Ordner' })
		await inbox.getByRole('button', { name: 'Dubletten' }).click()
		await expect(inbox.getByText('original.pdf')).toBeVisible()
		await inbox.getByRole('button', { name: 'Alle „kann weg“ auswählen' }).click()
		await inbox.getByRole('button', { name: 'Verschieben nach…' }).click()

		const move = page.getByRole('dialog', { name: /Dateien verschieben/ })
		await move.getByLabel('Neuer Unterordner (optional)').fill('_Dubletten')
		await move.getByRole('button', { name: 'Verschieben', exact: true }).click()
		await expect(move).toBeHidden()

		// Keine Kopie überschreibt die andere, das Original bleibt liegen.
		expect(await dav.exists(request, 'admin', `${FOLDER}/_Dubletten/kopie.pdf`)).toBe(true)
		expect(await dav.exists(request, 'admin', `${FOLDER}/_Dubletten/kopie (2).pdf`)).toBe(true)
		expect(await dav.exists(request, 'admin', `${FOLDER}/dup/original.pdf`)).toBe(true)
		const after = (await api.attachmentInboxDuplicates(request)).groups.find((g) => g.files.some((f) => f.fileId === id('dup', 'original.pdf')))
		expect(after.files.filter((f) => f.verdict === 'removable').every((f) => f.folder === '_Dubletten')).toBe(true)
	})

	test('Umschalten vom Nutzerordner trägt die Datei-IDs nach', async ({ request }) => {
		await api.updateSettings(request, { storage_mode: 'user', storage_user: 'admin', storage_path: FOLDER })
		const booking = await createBooking(request, 'Alter Beleg', '2026-02-02')
		const old = await api.addAttachment(request, booking.id, { name: 'alt.png' })
		expect(old.fileId).toBeFalsy()
		expect(await dav.exists(request, 'admin', `${FOLDER}/${booking.id}/${old.id}_alt.png`)).toBe(true)

		const switched = await (await api.updateSettings(request, { storage_mode: 'watch', storage_user: 'admin', storage_path: FOLDER })).json()
		expect(switched.storage_backfilled).toBe(1)
		const [linked] = await api.listAttachments(request, booking.id)
		expect(linked.fileId).toBeTruthy()
		expect(linked.missing).toBe(false)
		expect((await api.attachmentInbox(request)).files.find((f) => f.fileId === linked.fileId).journalIds).toEqual([booking.id])
	})
})
