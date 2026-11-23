import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, INCOME_ACCOUNT, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// CSV-Export der Buchungsliste: ist die Liste gefiltert, enthält die Datei
// genau die angezeigten Buchungen – nicht wieder das ganze Journal.

const MIT_BELEG = 'Export mit Beleg'
const OHNE_BELEG = 'Export ohne Beleg'

async function csvPerKlick(page, testInfo, name) {
	const downloadPromise = page.waitForEvent('download')
	await visibleSection(page).locator('a.vbh-export-btn').click()
	const download = await downloadPromise
	const file = testInfo.outputPath(name)
	await download.saveAs(file)
	return { text: await readFile(file, 'utf8'), fileName: download.suggestedFilename() }
}

test.describe('CSV-Export der gefilterten Buchungsliste', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		const booking = (description, date) => api.createBooking(request, {
			date,
			description,
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 12,
		}).then((r) => r.json())
		const mitBeleg = await booking(MIT_BELEG, '2026-04-10')
		await booking(OHNE_BELEG, '2026-04-11')
		await api.addAttachment(request, mitBeleg.id)
	})

	// Als Revisor: der gefilterte Export ist ein POST, bleibt aber ein Lesezugriff.
	test('„nur ohne Beleg“ exportiert nur die Buchungen ohne Beleg', async ({ page }, testInfo) => {
		await openApp(page, USERS.revisor)
		await switchTab(page, 'Buchungen')
		const section = visibleSection(page)
		await expect(section.locator('tr', { hasText: MIT_BELEG }).first()).toBeVisible()

		const alles = await csvPerKlick(page, testInfo, 'alles.csv')
		expect(alles.text).toContain(MIT_BELEG)
		expect(alles.text).toContain(OHNE_BELEG)

		await section.getByLabel('nur ohne Beleg').check()
		await expect(section.locator('tr', { hasText: MIT_BELEG })).toHaveCount(0)

		const gefiltert = await csvPerKlick(page, testInfo, 'ohne-beleg.csv')
		expect(gefiltert.fileName).toMatch(/^journal_.*\.csv$/)
		expect(gefiltert.text).toContain('"Nr.";"Datum";"Beschreibung"')
		expect(gefiltert.text).toContain(OHNE_BELEG)
		expect(gefiltert.text).not.toContain(MIT_BELEG)
	})

	test('die Suche schränkt den Export ebenso ein', async ({ page }, testInfo) => {
		await openApp(page, USERS.revisor)
		await switchTab(page, 'Buchungen')
		const section = visibleSection(page)
		await section.getByPlaceholder('Suche (Text oder Betrag)…').fill(MIT_BELEG)
		await expect(section.locator('tr', { hasText: OHNE_BELEG })).toHaveCount(0)

		const gefiltert = await csvPerKlick(page, testInfo, 'suche.csv')
		expect(gefiltert.text).toContain(MIT_BELEG)
		expect(gefiltert.text).not.toContain(OHNE_BELEG)
	})
})
