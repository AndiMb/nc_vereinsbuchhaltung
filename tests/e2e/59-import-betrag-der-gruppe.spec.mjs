import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Betrag und Turnus kommen von der Beitragsgruppe: die vereinsweite Einstellung „Standard-Beitrag“ gibt es nicht
// mehr. Beim CSV-Import bekommt eine Zeile mit Startdatum, aber ohne Betrag, den üblichen Monatsbeitrag
// (Standardbeitrag) und den Standard-Turnus der Beitragsgruppe der Zeile. Ohne Startdatum entsteht nie ein
// Beitrag; lässt sich keine Gruppe bestimmen, bleibt es bei einer Warnung.
//
// Jeder Test legt seine Mitglieder mit eindeutigen Nachnamen an (/reset räumt Mitglieder und Zuweisungen nicht
// ab, ein Wiederholungslauf fände sonst zwei gleichnamige Zeilen).

const GROUP_NAME = 'Betrag der Gruppe E2E'
const OTHER_GROUP_NAME = 'Zweite Gruppe E2E'
const today = () => new Date().toISOString().slice(0, 10)
/** JJJJ-MM-TT → TT.MM.JJJJ, wie die CSV es erwartet. */
const german = (iso) => iso.split('-').reverse().join('.')
const unique = () => Math.random().toString(36).slice(2, 8)

async function ensureGroup(request, name, { defaultMonthlyAmount, defaultInterval }) {
	const groups = await api.getJson(request, '/contribution-groups')
	let group = groups.find((g) => g.name === name)
	if (!group) {
		group = await (await api.raw(request, 'POST', '/contribution-groups', {
			expectOk: true,
			data: { name, minMonthlyAmount: 5, defaultMonthlyAmount, allowedIntervals: [1, 3, 12], defaultInterval, isActive: true },
		})).json()
	}
	return group
}

/** Öffnet „Liste einlesen“, lädt die CSV und prüft sie; liefert den Dialog. */
async function checkCsv(page, csv) {
	await openApp(page, USERS.buchhalter)
	await switchTab(page, 'Beiträge')
	await visibleSection(page).getByRole('button', { name: 'Liste einlesen', exact: true }).click()
	const dialog = page.getByRole('dialog', { name: 'Mitgliederliste einlesen' })
	await expect(dialog).toBeVisible()
	await dialog.locator('input[type="file"]').setInputFiles({ name: 'betrag-der-gruppe.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf-8') })
	await dialog.getByRole('button', { name: 'Prüfen', exact: true }).click()
	return dialog
}

test.describe('CSV-Import: Betrag und Turnus aus der Beitragsgruppe', () => {
	let group

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.updateSettings(request, { membership_enabled: '1', club_name: 'Testverein e.V.' })
		// Standardbeitrag 8 €/Monat, Standard-Turnus vierteljährlich – beides ungleich dem, was ein
		// „jährlich“-Fallback oder ein eingetippter Wert ergäbe.
		group = await ensureGroup(request, GROUP_NAME, { defaultMonthlyAmount: 8, defaultInterval: 3 })
		await ensureGroup(request, OTHER_GROUP_NAME, { defaultMonthlyAmount: 6, defaultInterval: 12 })
	})

	test('Die Einstellung „Standard-Beitrag“ gibt es nicht mehr', async ({ request }) => {
		const settings = await api.getSettings(request)
		expect(settings).not.toHaveProperty('default_fee_amount')
		expect(settings).not.toHaveProperty('default_fee_frequency')
	})

	test('Start ohne Betrag: Vorschau zeigt den Betrag der Gruppe, Übernehmen legt Betrag und Turnus der Gruppe an', async ({ page, request }) => {
		test.setTimeout(90000)
		const name = `Import Gruppenbetrag-${unique()}`
		const csv = ['Name;E-Mail;Beitragsgruppe;Start', `${name};gruppenbetrag-${unique()}@example.org;${GROUP_NAME};${german(today())}`].join('\r\n')

		const dialog = await checkCsv(page, csv)

		// Der Hinweis über der Dateiauswahl steht immer da – nicht mehr nur bei hinterlegtem Standardbeitrag.
		await expect(dialog.getByText('bekommen den üblichen Betrag ihrer Beitragsgruppe', { exact: false })).toBeVisible()

		const row = dialog.locator('tr', { hasText: name })
		await expect(row.getByText('nur Zuweisung', { exact: true })).toBeVisible()
		await expect(row.getByText('Fehler', { exact: true })).toHaveCount(0)
		await expect(row).toContainText('8,00')
		await expect(dialog.getByText('1 von 1 Zeilen sind in Ordnung', { exact: false })).toBeVisible()

		await dialog.getByRole('button', { name: '1 Zeile übernehmen', exact: true }).click()
		await page.getByRole('dialog', { name: 'Mitglieder übernehmen' }).getByRole('button', { name: 'Übernehmen', exact: true }).click()
		await expect(dialog.getByText('1 von 1 Zeilen übernommen', { exact: false })).toBeVisible()

		const members = await api.getJson(request, '/members')
		const imported = members.find((m) => m.displayName === name)
		expect(imported, 'das Mitglied wurde angelegt').toBeTruthy()
		const assignments = (await api.getJson(request, '/assignments')).filter((a) => a.memberId === imported.id)
		expect(assignments).toHaveLength(1)
		expect(assignments[0].groupId).toBe(group.id)
		expect(assignments[0].monthlyAmount).toBe(8)
		expect(assignments[0].intervalMonths).toBe(3)
		expect(assignments[0].validFrom).toBe(today())
	})

	test('Ohne Startdatum und ohne Betrag entsteht nur das Mitglied, auch wenn die Gruppe genannt ist', async ({ page, request }) => {
		test.setTimeout(90000)
		const name = `Import Ohnestart-${unique()}`
		const csv = ['Name;E-Mail;Beitragsgruppe', `${name};ohnestart-${unique()}@example.org;${GROUP_NAME}`].join('\r\n')

		const dialog = await checkCsv(page, csv)

		const row = dialog.locator('tr', { hasText: name })
		await expect(row.getByText('nur Stammdaten', { exact: true })).toBeVisible()
		await expect(row.getByText('Fehler', { exact: true })).toHaveCount(0)

		await dialog.getByRole('button', { name: '1 Zeile übernehmen', exact: true }).click()
		await page.getByRole('dialog', { name: 'Mitglieder übernehmen' }).getByRole('button', { name: 'Übernehmen', exact: true }).click()
		await expect(dialog.getByText('1 von 1 Zeilen übernommen', { exact: false })).toBeVisible()

		const imported = (await api.getJson(request, '/members')).find((m) => m.displayName === name)
		expect(imported, 'das Mitglied wurde angelegt').toBeTruthy()
		const assignments = (await api.getJson(request, '/assignments')).filter((a) => a.memberId === imported.id)
		expect(assignments).toEqual([])
	})

	test('Start ohne Betrag und ohne Spalte „Beitragsgruppe“ bei mehreren Gruppen: Warnung, kein Beitrag', async ({ page }) => {
		test.setTimeout(60000)
		const name = `Import Ohnegruppe-${unique()}`
		const csv = ['Name;E-Mail;Start', `${name};ohnegruppe-${unique()}@example.org;${german(today())}`].join('\r\n')

		const dialog = await checkCsv(page, csv)

		// Die Zeile ist in Ordnung (das Mitglied entsteht), nennt aber, warum kein Beitrag entsteht.
		const row = dialog.locator('tr', { hasText: name })
		await expect(row.getByText('nur Stammdaten', { exact: true })).toBeVisible()
		await expect(row.getByText('Keine Beitragsgruppe angegeben', { exact: false })).toBeVisible()
		await expect(row.getByText('Fehler', { exact: true })).toHaveCount(0)
	})
})
