import { join } from 'path'
import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, pickNcSelectOption, fixture, FIXTURES_DIR, CAMT_STATEMENT, USERS } from './fixtures/nextcloud.mjs'

// Kontoauszug-Import: alle drei Formate, Dublettenerkennung (auch
// formatübergreifend) und die Zuordnung eines Umsatzes über die Oberfläche.

const N = CAMT_STATEMENT.txCount

test.describe('Kontoauszug-Import', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
	})

	test('CSV-CAMT über den Import-Dialog: Vorschau, Übernahme, Zuordnungsliste', async ({ page, request }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Umsätze importieren' }).click()

		const dialog = page.getByRole('dialog')
		await dialog.locator('input[type="file"]').setInputFiles(join(FIXTURES_DIR, CAMT_STATEMENT.csv))

		// Die Vorschau kommt von selbst, sobald die Datei gewählt ist.
		await expect(dialog.getByText(`${N} neu`)).toBeVisible()
		await dialog.getByRole('button', { name: `${N} Buchungen importieren` }).click()
		await dialog.getByRole('button', { name: 'Schließen' }).last().click()

		// Alle Umsätze warten auf ihre Zuordnung.
		await visibleSection(page).getByRole('button', { name: 'Zuzuordnen' }).click()
		await expect(visibleSection(page).getByText(`0 von ${N} Bankbuchungen zugeordnet`)).toBeVisible()
		await expect(visibleSection(page).getByText('Stadtwerke Musterstadt').first()).toBeVisible()

		const tx = await api.listTransactions(request)
		expect(tx).toHaveLength(N)
	})

	// Die folgenden Tests stellen ihre Vorbedingung (CSV bereits importiert)
	// selbst her: schlägt ein früherer Test fehl, startet Playwright den
	// Worker neu und beforeAll setzt den Bestand zurück – ein Test darf sich
	// deshalb nie stillschweigend auf die Daten seiner Vorgänger verlassen.
	test('derselbe Auszug noch einmal: nur Dubletten, nichts Neues (API)', async ({ request }) => {
		await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		const result = await api.importStatement(request, fixture(CAMT_STATEMENT.csv))
		expect(result.new).toBe(0)
		expect(result.duplicate).toBe(N)
	})

	test('CAMT.053 und MT940: Formaterkennung und formatübergreifende Dubletten (API)', async ({ request }) => {
		// Die drei Fixture-Dateien sind derselbe Kontoauszug in drei Formaten.
		await api.importStatement(request, fixture(CAMT_STATEMENT.csv))

		const camt = await api.importStatement(request, fixture(CAMT_STATEMENT.camt053), { filename: 'auszug.xml' })
		expect(camt.format).toBe('camt')
		expect(camt.new).toBe(0)
		expect(camt.duplicate).toBe(N)

		const mt940 = await api.importStatement(request, fixture(CAMT_STATEMENT.mt940), { filename: 'auszug.sta' })
		expect(mt940.format).toBe('mt940')
		expect(mt940.new).toBe(0)
	})

	test('Umsatz über die Oberfläche einem Konto zuordnen', async ({ page, request }) => {
		await api.importStatement(request, fixture(CAMT_STATEMENT.csv))

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Buchungen')
		await visibleSection(page).getByRole('button', { name: 'Zuzuordnen' }).click()

		// Der Mietumsatz bekommt sein Gegenkonto über das Zuordnungs-Select
		// in der Tabellenzeile.
		const row = visibleSection(page).locator('tr', { hasText: 'Stadtwerke Musterstadt' }).first()
		await pickNcSelectOption(row, '– nicht zugeordnet –', 'Mietkosten')

		// Aus dem Umsatz ist eine zugeordnete Buchung geworden.
		await expect(visibleSection(page).getByText(`von ${N} Bankbuchungen zugeordnet`)).toContainText(`1 von ${N}`)
		const assigned = await api.listTransactions(request, { status: 'assigned' })
		expect(assigned.length).toBeGreaterThanOrEqual(1)
	})
})
