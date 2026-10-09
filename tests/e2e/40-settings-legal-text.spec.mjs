import { test, expect } from '@playwright/test'
import { api, openSettingsPage, USERS } from './fixtures/nextcloud.mjs'

// Einstellungen des Beitrags-/SEPA-Moduls und Mandats-Rechtstext-Editor
// (Issue #101, Spec §4 „Neue Einstellungen" und §3.11): in den
// Nextcloud-Einstellungen unter Verwaltung → Vereinsbuchhaltung, Bereiche
// „Beiträge & SEPA" und „Mandats-Rechtstext", ab Rolle Verwalter.
//
// Der Rechtstext liegt versioniert in der Datenbank und wächst nur: die Spec
// nimmt deshalb nie die Anzahl der Fassungen beim Start an, sondern misst
// vor dem Speichern und vergleicht danach.

const CLUB_NAME = 'Musikverein Echo & Söhne e.V.'
// Eindeutig je Lauf: der Rahmen darf sich nie mit dem aktuellen decken (sonst „unverändert").
const RAHMEN = `Wir ziehen die Beiträge jährlich im März ein. (E2E ${Date.now()})`

const beitraegeSepa = (page) => page.locator('#settings-section_beitraege-sepa')
const legalSection = (page) => page.locator('#settings-section_mandats-rechtstext')

/** Eine Einstellungskarte des Beitragsmoduls über ihre Überschrift. */
const card = (page, title) => beitraegeSepa(page).locator('.vbh-card').filter({ has: page.getByRole('heading', { name: title, exact: true }) })

/** Toasts stehen doppelt im DOM – .first(). */
const savedToast = (page) => page.getByRole('status').filter({ hasText: 'Einstellungen gespeichert.' }).first()

/**
 * Schalter (NcCheckboxRadioSwitch) setzen. Das Eingabefeld selbst ist
 * unsichtbar überlagert und für einen Mausklick nicht zugänglich – geklickt
 * wird, wie ein Mensch es tut, auf die Beschriftung.
 */
async function setSwitch(scope, label, on) {
	const input = scope.getByRole('switch', { name: label })
	if ((await input.isChecked()) !== on) {
		await scope.getByText(label).click()
	}
	await expect(input).toBeChecked({ checked: on })
}

/** Öffnet die Einstellungsseite und wartet, bis die (asynchron geladenen) Karten des Beitragsmoduls stehen. */
async function openSettings(page) {
	await openSettingsPage(page, USERS.admin)
	await expect(card(page, 'Rücklastschriften und Mahnwesen')).toBeVisible()
}

/** Alles, was diese Spec verstellt, auf den Ausgangszustand – `resetBook()` räumt die App-Config nicht. */
async function resetSettings(request) {
	await api.updateSettings(request, {
		fiscal_year_start_month: 1,
		mandate_reference_prefix: 'M',
		mandate_document_folder: 'SEPA-Mandate',
		show_missing_document_warning: '1',
		expiry_warning_days: 180,
		dunning_interval_days: 14,
		club_name: CLUB_NAME,
		storage_user: '',
		storage_path: 'Vereinsbuchhaltung/Belege',
	})
	await api.raw(request, 'POST', '/debit-batches/settings', {
		data: { releaseLeadDays: 5, xmlFolderEnabled: '0', xmlFolderPath: 'SEPA-Einreichungen' },
	})
	await api.raw(request, 'POST', '/sepa-import/settings', {
		data: { returnFeeAccountId: 0, returnFeeRechargeEnabled: '0', contributionDefaultAccountId: 0 },
	})
	// Terminplan-Abstände (Issue #70): die Spec liest sie nur, setzt sie aber auf den Standard
	await api.raw(request, 'POST', '/due-date-schedule/lead-days', {
		data: { warningLeadDays: 21, prenotificationLeadDays: 14 },
	})
}

test.describe('Einstellungen Beiträge & SEPA', () => {
	let feeAccount, revenueAccount

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		feeAccount = await api.createAccount(request, { number: '6998', name: 'E2E Rücklastschriftgebühren', type: 'expense' })
		revenueAccount = await api.createAccount(request, { number: '4998', name: 'E2E Beitragserlöse', type: 'income' })
		await resetSettings(request)
	})

	test.afterAll(async ({ request }) => {
		await resetSettings(request)
	})

	test('alle Karten des Beitragsmoduls stehen da, der Terminplan wird verlinkt statt nachgebaut', async ({ page }) => {
		await openSettings(page)

		for (const title of ['Beitragsjahr und Einzugszyklus', 'Ablage der Einzugsdatei (XML)', 'Mandate', 'Rücklastschriften und Mahnwesen']) {
			await expect(card(page, title)).toBeVisible()
		}
		await expect(legalSection(page)).toBeVisible()

		// Vorwarnfenster und Vorabinfo-Vorlauf gehören dem Terminplan (#70): hier nur als Übersicht
		const cycle = card(page, 'Beitragsjahr und Einzugszyklus')
		await expect(cycle.getByText(/Vorwarnfenster: 21 Tage/)).toBeVisible()
		await expect(cycle.getByText(/Vorabinfo-Vorlauf: 14 Tage/)).toBeVisible()
		await expect(cycle.getByLabel('Vorwarnfenster')).toHaveCount(0)
		await expect(cycle.getByRole('link', { name: /Terminplan/ })).toHaveAttribute('href', /\/apps\/vereinsbuchhaltung\/contributions\/batch$/)
	})

	test('Beitragsjahr und Freigabe-Vorlauf ändern, nach Reload noch da', async ({ page, request }) => {
		await openSettings(page)
		const cycle = card(page, 'Beitragsjahr und Einzugszyklus')

		await cycle.getByLabel('Beitragsjahr beginnt im').selectOption({ label: 'April' })
		await cycle.getByLabel('Freigabe-Vorlauf').fill('7')
		await cycle.getByRole('button', { name: 'Speichern' }).click()
		await expect(savedToast(page)).toBeVisible()

		await page.reload()
		await expect(card(page, 'Beitragsjahr und Einzugszyklus')).toBeVisible()
		await expect(card(page, 'Beitragsjahr und Einzugszyklus').getByLabel('Beitragsjahr beginnt im')).toHaveValue('4')
		await expect(card(page, 'Beitragsjahr und Einzugszyklus').getByLabel('Freigabe-Vorlauf')).toHaveValue('7')

		expect((await api.getSettings(request)).fiscal_year_start_month).toBe(4)
		expect((await api.getJson(request, '/debit-batches/settings')).releaseLeadDays).toBe(7)
	})

	test('ein unsinniger Freigabe-Vorlauf wird mit Feldnamen gemeldet und nicht gespeichert', async ({ page, request }) => {
		await openSettings(page)
		const cycle = card(page, 'Beitragsjahr und Einzugszyklus')
		const before = (await api.getJson(request, '/debit-batches/settings')).releaseLeadDays

		await cycle.getByLabel('Freigabe-Vorlauf').fill('0')
		await cycle.getByRole('button', { name: 'Speichern' }).click()
		await expect(cycle.getByRole('alert')).toContainText('Freigabe-Vorlauf')
		await expect(cycle.getByRole('alert')).toContainText('zwischen 1 und 365')

		expect((await api.getJson(request, '/debit-batches/settings')).releaseLeadDays).toBe(before)
	})

	test('Mandate: Präfix, Nachweis-Ordner, Hinweis und Ablauf-Vorwarnung speichern, nach Reload noch da', async ({ page, request }) => {
		await openSettings(page)
		const mandates = card(page, 'Mandate')

		await mandates.getByLabel('Mandatsreferenz-Präfix').fill('TST')
		await mandates.getByLabel('Ablauf-Vorwarnung').fill('90')
		await mandates.getByLabel('Nachweis-Ordner').fill('E2E-Nachweise')
		await setSwitch(mandates, /Auf Mandate ohne Nachweis hinweisen/, false)
		await mandates.getByRole('button', { name: 'Speichern' }).click()
		await expect(savedToast(page)).toBeVisible()

		await page.reload()
		const reloaded = card(page, 'Mandate')
		await expect(reloaded).toBeVisible()
		await expect(reloaded.getByLabel('Mandatsreferenz-Präfix')).toHaveValue('TST')
		await expect(reloaded.getByLabel('Ablauf-Vorwarnung')).toHaveValue('90')
		await expect(reloaded.getByLabel('Nachweis-Ordner')).toHaveValue('E2E-Nachweise')
		await expect(reloaded.getByRole('switch', { name: /Auf Mandate ohne Nachweis hinweisen/ })).not.toBeChecked()

		const settings = await api.getSettings(request)
		expect(settings.mandate_reference_prefix).toBe('TST')
		expect(settings.expiry_warning_days).toBe(90)
		expect(settings.mandate_document_folder).toBe('E2E-Nachweise')
		expect(settings.show_missing_document_warning).toBe(false)
	})

	test('Mandate: ein Nachweis-Ordner mit „..“ wird mit verständlicher Meldung abgelehnt', async ({ page }) => {
		await openSettings(page)
		const mandates = card(page, 'Mandate')

		await mandates.getByLabel('Nachweis-Ordner').fill('../fremd')
		await mandates.getByRole('button', { name: 'Speichern' }).click()
		await expect(mandates.getByRole('alert')).toContainText('Nachweis-Ordner')
		await expect(mandates.getByRole('alert')).toContainText('nicht erlaubt')
	})

	test('Rücklastschriften: Konten und Mahnabstand speichern, nach Reload noch da', async ({ page, request }) => {
		await openSettings(page)
		const returns = card(page, 'Rücklastschriften und Mahnwesen')

		await returns.getByLabel('Konto für Rücklastschriftgebühren').selectOption({ label: '6998 · E2E Rücklastschriftgebühren' })
		await returns.getByLabel('Standard-Erlöskonto').selectOption({ label: '4998 · E2E Beitragserlöse' })
		await setSwitch(returns, /Rücklastschriftgebühren an das Mitglied weiterbelasten/, true)
		await returns.getByLabel('Mahnabstand').fill('21')
		await returns.getByRole('button', { name: 'Speichern' }).click()
		await expect(savedToast(page)).toBeVisible()

		await page.reload()
		const reloaded = card(page, 'Rücklastschriften und Mahnwesen')
		await expect(reloaded).toBeVisible()
		await expect(reloaded.getByLabel('Konto für Rücklastschriftgebühren')).toHaveValue(String(feeAccount.id))
		await expect(reloaded.getByLabel('Standard-Erlöskonto')).toHaveValue(String(revenueAccount.id))
		await expect(reloaded.getByRole('switch', { name: /weiterbelasten/ })).toBeChecked()
		await expect(reloaded.getByLabel('Mahnabstand')).toHaveValue('21')

		const sepa = await api.getJson(request, '/sepa-import/settings')
		expect(sepa).toMatchObject({ returnFeeAccountId: feeAccount.id, returnFeeRechargeEnabled: true, contributionDefaultAccountId: revenueAccount.id })
		expect((await api.getSettings(request)).dunning_interval_days).toBe(21)
	})

	test('Weiterbelastung ohne Konto für Rücklastschriftgebühren: verständliche Meldung, nichts gespeichert', async ({ page, request }) => {
		// Vorbedingung selbst herstellen: kein Konto, Weiterbelastung aus
		await api.raw(request, 'POST', '/sepa-import/settings', {
			data: { returnFeeAccountId: 0, returnFeeRechargeEnabled: '0', contributionDefaultAccountId: 0 },
			expectOk: true,
		})
		await openSettings(page)
		const returns = card(page, 'Rücklastschriften und Mahnwesen')

		await setSwitch(returns, /Rücklastschriftgebühren an das Mitglied weiterbelasten/, true)
		await returns.getByRole('button', { name: 'Speichern' }).click()
		await expect(returns.getByRole('alert')).toContainText('Konto für Rücklastschriftgebühren')
		await expect(returns.getByRole('alert')).toContainText('gewählt')

		expect((await api.getJson(request, '/sepa-import/settings')).returnFeeRechargeEnabled).toBe(false)
	})

	test('der Server lehnt falsche Kontoarten, Geldkonten und unbekannte Konten ab', async ({ request }) => {
		const bank = await api.accountByNumber(request, '1200')
		const post = (data) => api.raw(request, 'POST', '/sepa-import/settings', { data })

		// Ertragskonto als Gebührenkonto
		let resp = await post({ returnFeeAccountId: revenueAccount.id })
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('Aufwandskonto')

		// Aufwandskonto als Erlöskonto
		resp = await post({ contributionDefaultAccountId: feeAccount.id })
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('Ertragskonto')

		// Geldkonto
		resp = await post({ returnFeeAccountId: bank.id })
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('Geldkonto')

		// gibt es nicht
		resp = await post({ returnFeeAccountId: 999999 })
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('nicht gefunden')
	})

	test('ein ungültiger Mahnabstand wird gemeldet, der Server prüft ebenfalls', async ({ page, request }) => {
		await openSettings(page)
		const returns = card(page, 'Rücklastschriften und Mahnwesen')

		await returns.getByLabel('Mahnabstand').fill('0')
		await returns.getByRole('button', { name: 'Speichern' }).click()
		await expect(returns.getByRole('alert')).toContainText('Mahnabstand')

		const resp = await api.raw(request, 'POST', '/settings', { data: { dunning_interval_days: 0 } })
		expect(resp.status()).toBe(400)
	})

	test('Konten-Einstellungen räumen sich ab, wenn das Konto gelöscht wird', async ({ request }) => {
		const temp = await api.createAccount(request, { number: '6997', name: 'E2E Wegwerf-Gebührenkonto', type: 'expense' })
		await api.raw(request, 'POST', '/sepa-import/settings', { data: { returnFeeAccountId: temp.id }, expectOk: true })
		expect((await api.getJson(request, '/sepa-import/settings')).returnFeeAccountId).toBe(temp.id)

		await api.deleteAccount(request, temp.id)

		expect((await api.getJson(request, '/sepa-import/settings')).returnFeeAccountId).toBeNull()
	})

	test('XML-Ablage ohne Ablage-Nutzer: der Hinweis steht da und das Einschalten wird verständlich abgelehnt', async ({ page, request }) => {
		await api.updateSettings(request, { storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
		await openSettings(page)
		const xml = card(page, 'Ablage der Einzugsdatei (XML)')

		await expect(xml.getByText(/noch keiner gewählt/)).toBeVisible()
		await setSwitch(xml, /Einzugsdatei zusätzlich im Nextcloud-Ordner ablegen/, true)
		await xml.getByRole('button', { name: 'Speichern' }).click()
		await expect(xml.getByRole('alert')).toContainText('Nextcloud-Nutzer')

		expect((await api.getJson(request, '/debit-batches/settings')).xmlFolderEnabled).toBe(false)
	})

	test('XML-Ablage mit Ablage-Nutzer: einschalten, Ordner setzen, nach Reload noch da', async ({ page, request }) => {
		await api.updateSettings(request, { storage_user: 'admin', storage_path: 'Vereinsbuchhaltung/Belege' })
		await openSettings(page)
		const xml = card(page, 'Ablage der Einzugsdatei (XML)')

		await expect(xml.getByText('Ablage-Nutzer:')).toBeVisible()
		await setSwitch(xml, /Einzugsdatei zusätzlich im Nextcloud-Ordner ablegen/, true)
		await xml.getByLabel('Ablageordner').fill('E2E-Einreichungen')
		await xml.getByRole('button', { name: 'Speichern' }).click()
		await expect(savedToast(page)).toBeVisible()

		await page.reload()
		const reloaded = card(page, 'Ablage der Einzugsdatei (XML)')
		await expect(reloaded).toBeVisible()
		await expect(reloaded.getByRole('switch', { name: /Einzugsdatei zusätzlich/ })).toBeChecked()
		await expect(reloaded.getByLabel('Ablageordner')).toHaveValue('E2E-Einreichungen')

		const settings = await api.getJson(request, '/debit-batches/settings')
		expect(settings).toMatchObject({ xmlFolderEnabled: true, xmlFolderPath: 'E2E-Einreichungen' })
	})

	test('ein ungültiger XML-Ablageordner wird mit verständlicher Meldung abgelehnt', async ({ page, request }) => {
		await api.updateSettings(request, { storage_user: 'admin', storage_path: 'Vereinsbuchhaltung/Belege' })
		await openSettings(page)
		const xml = card(page, 'Ablage der Einzugsdatei (XML)')

		await xml.getByLabel('Ablageordner').fill('../raus')
		await xml.getByRole('button', { name: 'Speichern' }).click()
		await expect(xml.getByRole('alert')).toContainText('Ablageordner')
		await expect(xml.getByRole('alert')).toContainText('nicht erlaubt')

		expect((await api.getJson(request, '/debit-batches/settings')).xmlFolderPath).not.toBe('../raus')
	})

	test('auf einem schmalen Display laufen die Karten nicht über', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 800 })
		await openSettings(page)

		const overflowing = await page.evaluate(() => [...document.querySelectorAll('#settings-section_beitraege-sepa .vbh-card, #settings-section_mandats-rechtstext .vbh-card')]
			.filter((el) => el.scrollWidth > el.clientWidth + 1)
			.map((el) => el.querySelector('h4')?.textContent ?? '?'))
		expect(overflowing).toEqual([])
	})
})

test.describe('Mandats-Rechtstext', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await resetSettings(request)
	})

	test.afterAll(async ({ request }) => {
		await resetSettings(request)
	})

	test('Pflichtblock ist geschützt, die Vorschau ersetzt den Vereinsnamen, kein Marker erscheint', async ({ page }) => {
		await openSettingsPage(page, USERS.admin)
		const legal = legalSection(page)
		const protectedBlock = legal.getByTestId('legaltext-protected')
		const preview = legal.getByTestId('legaltext-preview')

		await expect(protectedBlock).toContainText('SEPA-Lastschriftmandat')
		await expect(protectedBlock).toContainText('{{creditor_name}}')
		// nur zur Ansicht: weder Eingabefeld noch bearbeitbarer Inhalt
		await expect(protectedBlock.locator('textarea, input, [contenteditable]')).toHaveCount(0)

		await expect(preview).toContainText(`Ich ermächtige ${CLUB_NAME}`)
		await expect(preview).not.toContainText('{{creditor_name}}')
		// „&" darf nicht als Entität erscheinen (t() maskiert Variablen als HTML)
		await expect(legal.getByTestId('legaltext-club-name')).toHaveText(CLUB_NAME)

		// der interne Rahmen-Marker bleibt unsichtbar - weder als Text noch als Kommentar im DOM-Text
		const text = await legal.innerText()
		expect(text).not.toContain('vbh:rahmen')
		expect(text).not.toContain('<!--')
		expect(await legal.locator('textarea').inputValue()).not.toContain('vbh:')
	})

	test('Rahmen tippen aktualisiert die Vorschau; Speichern ist erst bei einer Änderung möglich', async ({ page }) => {
		await openSettingsPage(page, USERS.admin)
		const legal = legalSection(page)
		const save = legal.getByRole('button', { name: 'Neue Version speichern' })
		const textarea = legal.getByLabel('Rahmentext (optional)')
		const preview = legal.getByTestId('legaltext-preview')

		await expect(save).toBeDisabled()
		await expect(legal.getByRole('button', { name: 'Änderungen verwerfen' })).toHaveCount(0)

		await textarea.fill('Absatz eins von {{creditor_name}}\n\nAbsatz zwei')
		await expect(save).toBeEnabled()
		await expect(preview.locator('p', { hasText: `Absatz eins von ${CLUB_NAME}` })).toBeVisible()
		await expect(preview.locator('p', { hasText: 'Absatz zwei' })).toBeVisible()

		await legal.getByRole('button', { name: 'Änderungen verwerfen' }).click()
		await expect(save).toBeDisabled()
		await expect(preview).not.toContainText('Absatz eins')
	})

	test('neue Version speichern: sie steht im Verlauf, frühere Fassungen bleiben unverändert, nach Reload noch da', async ({ page, request }) => {
		const before = await api.getJson(request, '/mandate-legal-text/history')
		const previousNumber = before.length
		const nextNumber = previousNumber + 1

		await openSettingsPage(page, USERS.admin)
		const legal = legalSection(page)
		await legal.getByLabel('Rahmentext (optional)').fill(RAHMEN)
		await legal.getByRole('button', { name: 'Neue Version speichern' }).click()

		await expect(page.getByRole('status').filter({ hasText: `Neue Fassung ${nextNumber} gespeichert.` }).first()).toBeVisible()
		const entry = legal.getByTestId(`legaltext-version-${nextNumber}`)
		await expect(entry).toBeVisible()
		await expect(entry).toContainText('aktuell')
		await expect(entry).toContainText('Verwalter')
		// nach dem Speichern ist der Stand sauber: nichts mehr zu speichern
		await expect(legal.getByRole('button', { name: 'Neue Version speichern' })).toBeDisabled()

		// Verlauf auf dem Server: eine Fassung mehr, die bisherige wortgleich
		const after = await api.getJson(request, '/mandate-legal-text/history')
		expect(after).toHaveLength(nextNumber)
		expect(after[0].rahmen).toBe(RAHMEN)
		const previous = after.find((v) => v.number === previousNumber)
		expect(previous.rahmen).toBe(before.find((v) => v.number === previousNumber).rahmen)
		expect(previous.pflichtblock).toBe(before.find((v) => v.number === previousNumber).pflichtblock)

		await page.reload()
		const reloaded = legalSection(page)
		await expect(reloaded.getByLabel('Rahmentext (optional)')).toHaveValue(RAHMEN)
		await expect(reloaded.getByTestId('legaltext-preview')).toContainText(RAHMEN)

		// die frühere Fassung lässt sich einsehen; ihr Text ist unverändert
		const old = reloaded.getByTestId(`legaltext-version-${previousNumber}`)
		await old.getByRole('button', { name: 'Anzeigen' }).click()
		await expect(old.getByRole('button', { name: 'Ausblenden' })).toBeVisible()
		await expect(old).toContainText('SEPA-Lastschriftmandat')
		await expect(old).not.toContainText(RAHMEN)
		await expect(old).not.toContainText('vbh:rahmen')
	})

	test('der Hinweis „keine Rückwirkung“ steht im Editor', async ({ page }) => {
		await openSettingsPage(page, USERS.admin)
		const legal = legalSection(page)

		await expect(legal.getByText(/Bestehende Mandate behalten die Fassung/)).toBeVisible()
		await expect(legal.getByText(/frühere Fassungen bleiben unverändert erhalten/)).toBeVisible()
	})

	test('Server: ein unveränderter Rahmen erzeugt keine zweite Fassung', async ({ request }) => {
		const current = await api.getJson(request, '/mandate-legal-text')
		const before = await api.getJson(request, '/mandate-legal-text/history')

		const resp = await api.raw(request, 'POST', '/mandate-legal-text', { data: { rahmen: current.rahmen } })

		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('unverändert')
		expect(await api.getJson(request, '/mandate-legal-text/history')).toHaveLength(before.length)
	})

	test('ein zu langer Rahmen wird mit verständlicher Meldung abgelehnt, Speichern bleibt gesperrt', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const legal = legalSection(page)

		await legal.getByLabel('Rahmentext (optional)').fill('x'.repeat(5001))
		await expect(legal.getByRole('alert')).toContainText('zu lang')
		await expect(legal.getByRole('alert')).toContainText('5001')
		await expect(legal.getByRole('button', { name: 'Neue Version speichern' })).toBeDisabled()

		const resp = await api.raw(request, 'POST', '/mandate-legal-text', { data: { rahmen: 'x'.repeat(5001) } })
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('zu lang')
	})

	test('die für interne Marker reservierte Zeichenfolge wird abgelehnt', async ({ page, request }) => {
		await openSettingsPage(page, USERS.admin)
		const legal = legalSection(page)

		await legal.getByLabel('Rahmentext (optional)').fill('Text <!-- vbh:rahmen --> mehr Text')
		await expect(legal.getByRole('alert')).toContainText('reserviert')
		await expect(legal.getByRole('button', { name: 'Neue Version speichern' })).toBeDisabled()

		const resp = await api.raw(request, 'POST', '/mandate-legal-text', { data: { rahmen: 'Text <!-- vbh:rahmen --> mehr Text' } })
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('reserviert')
	})

	test('die API liefert Pflichtblock und Rahmen getrennt und nie den Textkörper mit dem Marker', async ({ request }) => {
		const current = await api.getJson(request, '/mandate-legal-text')
		const history = await api.getJson(request, '/mandate-legal-text/history')

		expect(JSON.stringify(current)).not.toContain('vbh:rahmen')
		expect(JSON.stringify(history)).not.toContain('vbh:rahmen')
		expect(current.version).not.toHaveProperty('body')
		expect(current.pflichtblock).toContain('SEPA-Lastschriftmandat')
		expect(history[0]).toMatchObject({ number: history.length })
	})
})

test.describe('Rechte der Einstellungen', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
	})

	for (const path of ['/mandate-legal-text/history', '/debit-batches/settings', '/sepa-import/settings']) {
		test(`${path}: nur ab Verwalter`, async ({ request }) => {
			for (const user of [USERS.buchhalter, USERS.revisor, USERS.ohneRolle]) {
				expect((await api.raw(request, 'GET', path, { user })).status(), `${path} als ${user}`).toBe(403)
			}
			expect((await api.raw(request, 'GET', path, { user: USERS.verwalter })).status()).toBe(200)
		})
	}

	test('Schreiben der Einstellungen und des Rechtstexts prallt für Buchhalter ab', async ({ request }) => {
		const rahmen = await api.raw(request, 'POST', '/mandate-legal-text', { user: USERS.buchhalter, data: { rahmen: 'Buchhalter darf das nicht' } })
		expect(rahmen.status()).toBe(403)

		const settings = await api.raw(request, 'POST', '/settings', { user: USERS.buchhalter, data: { dunning_interval_days: 30 } })
		expect(settings.status()).toBe(403)
		const debit = await api.raw(request, 'POST', '/debit-batches/settings', { user: USERS.buchhalter, data: { releaseLeadDays: 30 } })
		expect(debit.status()).toBe(403)
	})

	test('der aktuelle Rechtstext ist ab Revisor lesbar (für die Anzeige der Mandate)', async ({ request }) => {
		expect((await api.raw(request, 'GET', '/mandate-legal-text', { user: USERS.revisor })).status()).toBe(200)
	})
})
