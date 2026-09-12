import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, INCOME_ACCOUNT, openAccountTreeNode, openApp, pickNcSelectOption, selectPeriod, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Kontoauszug (Tab Konten): eine Buchung an Ort und Stelle korrigieren, ohne
// den Umweg über das Journal (Issue #39). Deckt zugleich das ältere Umbuchen
// ab, das dabei ins Drei-Punkte-Menü gewandert ist.

const JAHR = 2031

/** Zeile des Kontoauszugs zu einer Buchungsbeschreibung. */
function auszugsZeile(page, text) {
	return visibleSection(page).locator('tr', { hasText: text }).first()
}

test.describe('Kontoauszug', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: `${JAHR}-05-02`,
			description: 'Beitrag Linus Beispiel',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 90,
		})
	})

	test('Buchung aus dem Kontoauszug heraus bearbeiten', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)

		const zeile = auszugsZeile(page, 'Beitrag Linus Beispiel')
		await expect(zeile).toBeVisible({ timeout: 15000 })
		// Innerhalb der Zeile – im Kopf des Auszugs steht ein gleichnamiger
		// Knopf für das Konto selbst.
		await zeile.getByRole('button', { name: /bearbeiten/i }).click()

		const dialog = page.getByRole('dialog')
		await dialog.getByPlaceholder('z. B. Mitgliedsbeitrag Max Mustermann').fill('Beitrag Linus Beispiel (korrigiert)')
		await dialog.getByRole('button', { name: 'Speichern', exact: true }).click()
		await expect(page.getByRole('dialog')).toBeHidden()

		// Kern des Issues: der Auszug aktualisiert sich an Ort und Stelle,
		// ohne Wechsel in den Reiter Buchungen.
		await expect(visibleSection(page).getByText('Beitrag Linus Beispiel (korrigiert)')).toBeVisible({ timeout: 15000 })
		await expect(visibleSection(page).getByText('Beitrag Linus Beispiel', { exact: true })).toHaveCount(0)
	})

	test('Beleg zeigt sich als Büroklammer in der Auszugszeile', async ({ page, request }) => {
		const journal = await api.listJournal(request, { period: await api.periodIdForDate(request, `${JAHR}-05-02`) })
		const buchung = journal.find((e) => e.journal.description.startsWith('Beitrag Linus Beispiel')).journal
		await api.addAttachment(request, buchung.id)

		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)

		const zeile = auszugsZeile(page, 'Beitrag Linus Beispiel (korrigiert)')
		await expect(zeile.getByRole('button', { name: /Beleg/ })).toBeVisible({ timeout: 15000 })
	})

	test('Umbuchen bleibt über das Menü der Zeile erreichbar', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)

		const zeile = auszugsZeile(page, 'Beitrag Linus Beispiel (korrigiert)')
		await expect(zeile).toBeVisible({ timeout: 15000 })
		await zeile.locator('.action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: 'Umbuchen' }).click()

		// Die Habenseite (Mitgliedsbeiträge) auf Spenden umbuchen.
		const panel = visibleSection(page).locator('.vbh-reassign')
		await panel.getByRole('button', { name: /4000/ }).click()
		await pickNcSelectOption(panel, 'Neues Konto wählen…', 'Spenden')

		await expect(visibleSection(page).locator('.vbh-reassign')).toHaveCount(0, { timeout: 15000 })
		const journal = await api.listJournal(request, { period: await api.periodIdForDate(request, `${JAHR}-05-02`) })
		const [spenden] = await api.accountsByNumber(request, '4100')
		const buchung = journal.find((e) => e.journal.description === 'Beitrag Linus Beispiel (korrigiert)')
		expect(buchung.lines.some((l) => l.accountId === spenden.id)).toBe(true)
	})

	test('ohne Schreibrecht bleibt der Auszug lesbar, aber ohne Bearbeiten', async ({ page }) => {
		await openApp(page, USERS.revisor)
		// Das Rollen-Intro erscheint nur beim ersten Besuch – je nach
		// Reihenfolge der Spec-Dateien also mal ja, mal nein.
		const intro = page.getByRole('button', { name: 'Verstanden' })
		if (await intro.isVisible().catch(() => false)) {
			await intro.click()
		}
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(JAHR))
		await openAccountTreeNode(page, BANK_ACCOUNT)

		const zeile = auszugsZeile(page, 'Beitrag Linus Beispiel (korrigiert)')
		await expect(zeile).toBeVisible({ timeout: 15000 })
		await expect(zeile.getByRole('button', { name: /bearbeiten/i })).toHaveCount(0)
		await expect(zeile.locator('.action-item__menutoggle')).toHaveCount(0)
		// Der Beleg bleibt einsehbar – Belegprüfung ist genau die Aufgabe der Rolle.
		await expect(zeile.getByRole('button', { name: /Beleg/ })).toBeVisible()
	})
})

test.describe('Kontenbaum: Saldo inkl. Unterkonten', () => {
	const UNTERKONTO_JAHR = 2032
	// Bestehendes Konto "Spenden" (4100) bekommt ein Unterkonto: die
	// Buchungen verteilen sich auf beide, der Baum links muss sie bei
	// angehaktem "inkl. Unterkonten" zusammenzaehlen.
	const SPENDEN_ACCOUNT = '4100'

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, spenden] = await api.accountsByNumber(request, BANK_ACCOUNT, SPENDEN_ACCOUNT)
		const unterkonto = await api.createAccount(request, { number: '4101', name: 'Spenden Unterkonto (E2E)', type: 'income', parentId: spenden.id })
		await api.createBooking(request, { date: `${UNTERKONTO_JAHR}-03-01`, description: 'Spende Hauptkonto', debitAccountId: bank.id, creditAccountId: spenden.id, amount: 60 })
		await api.createBooking(request, { date: `${UNTERKONTO_JAHR}-03-02`, description: 'Spende Unterkonto', debitAccountId: bank.id, creditAccountId: unterkonto.id, amount: 40 })
	})

	test('Saldo im Kontenbaum zaehlt Unterkonten je nach Haken mit oder nicht', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Konten')
		await selectPeriod(page, String(UNTERKONTO_JAHR))

		const saldo = visibleSection(page).locator('.vbh-treenode', { hasText: SPENDEN_ACCOUNT }).first().locator('.vbh-treesaldo')

		// Anklicken waehlt das Konto aus und klappt es auf - dabei erscheint
		// rechts der Kontoauszug samt Haken "inkl. Unterkonten" (voreingestellt an).
		await openAccountTreeNode(page, SPENDEN_ACCOUNT)
		const haken = visibleSection(page).getByRole('checkbox', { name: 'inkl. Unterkonten' })
		await expect(haken).toBeChecked()
		await expect(saldo).toHaveText(/^100,00\s*€$/)

		// NcCheckboxRadioSwitch legt einen gestylten Label-Span ueber den
		// eigentlichen, unsichtbaren Input - ein normaler Klick auf den Input
		// selbst laeuft deshalb (immer, nicht nur gelegentlich) in Playwrights
		// Sichtbarkeitspruefung ("intercepts pointer events") und schlaegt nach
		// 30s Timeout fehl. force:true klickt trotzdem den Input direkt an, was
		// fuer einen nativen Checkbox-Input ein echtes Klick-/Change-Ereignis
		// ausloest.
		await haken.click({ force: true })
		await expect(haken).not.toBeChecked()
		await expect(saldo).toHaveText(/^60,00\s*€$/)
	})
})
