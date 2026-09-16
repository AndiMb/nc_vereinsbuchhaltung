import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Aufnahme-Assistent & voller CSV-Import (Issue #69, Spec §3.1): der
// dreistufige Weg Stammdaten → Mandat → Beitrag in MemberDialog.vue (Schritt
// 2/3 überspringbar) und der auf das neue Domänenmodell (Member/Mandate/
// Assignment statt der alten SepaMandate/MembershipFee-Tabellen) umgestellte
// CSV-Import in MemberImportDialog.vue.
//
// Für das neue Mandats-/Zuweisungs-Datenmodell gab es vor diesem Ticket keine
// Oberfläche (siehe 27-electronic-mandate.spec.mjs) – MemberDialog.vue ist
// die erste UI-Fläche dafür, deshalb wird hier zusätzlich zur UI-Bedienung
// über die API nachgeprüft, was tatsächlich entstanden ist.

const GROUP_NAME = 'Testgruppe Aufnahme'

async function enableMembership(request) {
	await api.updateSettings(request, { membership_enabled: '1', club_name: 'Testverein e.V.' })
}

async function ensureGroup(request) {
	const groups = await api.getJson(request, '/contribution-groups')
	let group = groups.find((g) => g.name === GROUP_NAME)
	if (!group) {
		group = await (await api.raw(request, 'POST', '/contribution-groups', {
			expectOk: true,
			data: { name: GROUP_NAME, minMonthlyAmount: 5, defaultMonthlyAmount: 10, allowedIntervals: [1, 12], defaultInterval: 12, isActive: true },
		})).json()
	}
	return group
}

async function assignmentsByMember(request, memberId) {
	const all = await api.getJson(request, '/assignments')
	return all.filter((a) => a.memberId === memberId)
}

test.describe('Aufnahme-Assistent & voller CSV-Import', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Papier-Mandat mit Unterschriftsdatum aktiviert sofort, Beitragsgruppen-Zuweisung mit Terminvorschau', async ({ page, request }) => {
		const group = await ensureGroup(request)

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Petra')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Aufnahme')
		await dialog.getByLabel('E-Mail').fill('petra.aufnahme@example.org')

		// Schritt 2 (Mandat): papier ist voreingestellt, das Unterschriftsdatum
		// entscheidet sofort-aktiv (Spec §3.1/§2.2).
		await dialog.getByLabel('IBAN', { exact: true }).fill('DE02120300000000202051')
		await dialog.getByLabel('Mandat unterschrieben am').fill('2026-01-15')

		// Schritt 3 (Beitrag): Beitragsgruppe wählen, Terminvorschau ansehen.
		await dialog.getByLabel('Beitragsgruppe').selectOption({ label: group.name })
		await dialog.getByRole('button', { name: 'Vorschau', exact: true }).click()
		await expect(dialog.getByText('Erste Periode:')).toBeVisible()
		await expect(dialog.getByText('voraussichtlicher Einzugstermin', { exact: false })).toBeVisible()

		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		const members = await api.listMembers(request)
		const member = members.find((m) => m.displayName === 'Petra Aufnahme')
		expect(member).toBeTruthy()

		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].signatureType).toBe('papier')
		expect(mandates[0].status).toBe('aktiv')

		const assignments = await assignmentsByMember(request, member.id)
		expect(assignments).toHaveLength(1)
		expect(assignments[0].groupId).toBe(group.id)
		expect(assignments[0].paymentMethod).toBe('direct_debit')
	})

	test('Papier-Mandat ohne Unterschriftsdatum bleibt Entwurf', async ({ page, request }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Uwe')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Entwurf')
		await dialog.getByLabel('IBAN', { exact: true }).fill('DE02120300000000202051')
		// Unterschriftsdatum bewusst leeren.
		await dialog.getByLabel('Mandat unterschrieben am').fill('')
		await expect(dialog.getByText('Ohne Unterschriftsdatum bleibt das Mandat ein Entwurf', { exact: false })).toBeVisible()

		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		const members = await api.listMembers(request)
		const member = members.find((m) => m.displayName === 'Uwe Entwurf')
		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].status).toBe('entwurf')
	})

	test('Elektronisches Mandat: Entwurf bleibt bis zur Zustimmung, Einmal-Link geht sofort raus', async ({ page, request }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Elke')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Elektronisch')
		// Ohne Mailadresse lässt sich kein Einmal-Link verschicken - die Option
		// bleibt bis dahin gesperrt (Spec §2.2: "an NC-Konto- oder bestätigte
		// Mitglieds-Mailadresse").
		await expect(dialog.getByLabel('Art der Unterschrift').locator('option[value="elektronisch"]')).toBeDisabled()
		await dialog.getByLabel('E-Mail').fill('elke.elektronisch@example.org')
		await expect(dialog.getByLabel('Art der Unterschrift').locator('option[value="elektronisch"]')).toBeEnabled()

		await dialog.getByLabel('Art der Unterschrift').selectOption('elektronisch')
		await dialog.getByLabel('IBAN', { exact: true }).fill('DE02120300000000202051')
		await expect(dialog.getByText('geht sofort ein Einmal-Link', { exact: false })).toBeVisible()

		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		const members = await api.listMembers(request)
		const member = members.find((m) => m.displayName === 'Elke Elektronisch')
		const mandates = await api.mandatesByMember(request, member.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].signatureType).toBe('elektronisch')
		// Erst die Zustimmung über den Einmal-Link aktiviert (siehe
		// 27-electronic-mandate.spec.mjs) - hier nur der Versand.
		expect(mandates[0].status).toBe('entwurf')
	})

	test('Ohne Mailadresse fällt der Beitrag sichtbar auf Überweisung zurück', async ({ page, request }) => {
		const group = await ensureGroup(request)

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Otto')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('OhneMail')
		// Bewusst keine Mailadresse.

		await dialog.getByLabel('Beitragsgruppe').selectOption({ label: group.name })
		await expect(dialog.getByText('Zahlungsart wird auf Überweisung gesetzt', { exact: false })).toBeVisible()
		await expect(dialog.getByLabel('Zahlungsart')).toBeDisabled()
		await expect(dialog.getByLabel('Zahlungsart')).toHaveValue('ueberweisung')

		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		const members = await api.listMembers(request)
		const member = members.find((m) => m.displayName === 'Otto OhneMail')
		const assignments = await assignmentsByMember(request, member.id)
		expect(assignments).toHaveLength(1)
		expect(assignments[0].paymentMethod).toBe('ueberweisung')
	})

	test('CSV-Import: Mitglied, Mandat und Zuweisung in einer Zeile, Bestätigungs-Checkbox erforderlich', async ({ page, request }) => {
		const group = await ensureGroup(request)

		const csv = [
			'Name;E-Mail;IBAN;Mandat am;Mandatsreferenz;Mitgliedsnummer;Beitragsgruppe;Betrag;Frequenz;Start',
			`Klara Import;klara.import@example.org;DE02120300000000202051;15.01.2026;ALT-EXTERN-1;IMP-0001;${group.name};9,00;monatlich;01.02.2026`,
			'Barzahler Import;;;;;;;;;', // reine Stammdatenzeile - Spec §3.1: Zeile ohne Mandat/Beitrag ist gueltig
		].join('\r\n')

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Liste einlesen', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitgliederliste einlesen' })
		await expect(dialog).toBeVisible()
		await dialog.locator('input[type="file"]').setInputFiles({ name: 'mitglieder.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf-8') })
		await dialog.getByRole('button', { name: 'Prüfen', exact: true }).click()

		await expect(dialog.getByText('2 von 2 Zeilen sind in Ordnung', { exact: false })).toBeVisible()

		// Die Bestaetigungs-Checkbox ist das vom Aktivierungs-Gate verlangte
		// Admin-Handeln (Spec §3.1) - ohne sie bleibt "Zeilen übernehmen" gesperrt.
		const confirmCheckbox = dialog.getByRole('checkbox', { name: 'sofort aktiviert', exact: false })
		await expect(confirmCheckbox).toBeVisible()
		const runButton = dialog.getByRole('button', { name: 'Zeilen übernehmen', exact: false })
		await expect(runButton).toBeDisabled()

		await confirmCheckbox.check()
		await expect(runButton).toBeEnabled()
		await runButton.click()

		const confirmDialog = page.getByRole('dialog', { name: 'Mitglieder übernehmen' })
		await expect(confirmDialog).toBeVisible()
		await confirmDialog.getByRole('button', { name: 'Übernehmen', exact: true }).click()
		await expect(dialog).toBeVisible() // Import-Dialog schliesst sich nicht selbst, zeigt das Ergebnis

		const members = await api.listMembers(request)
		const klara = members.find((m) => m.displayName === 'Klara Import')
		expect(klara).toBeTruthy()
		const mandates = await api.mandatesByMember(request, klara.id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].status).toBe('aktiv')
		expect(mandates[0].mandateReference).toBe('ALT-EXTERN-1')

		const assignments = await assignmentsByMember(request, klara.id)
		expect(assignments).toHaveLength(1)
		expect(assignments[0].groupId).toBe(group.id)
		expect(assignments[0].intervalMonths).toBe(1)
		expect(assignments[0].monthlyAmount).toBe(9)

		const barzahler = members.find((m) => m.displayName === 'Barzahler Import')
		expect(barzahler).toBeTruthy()
		expect(await api.mandatesByMember(request, barzahler.id)).toHaveLength(0)

		// Ein zweiter Einlauf derselben Datei legt Klara nicht doppelt an -
		// Mitgliedsnummer ist ein harter Dublettenschlüssel (Spec §3.1). Der
		// Barzahler ohne Mitgliedsnummer hat keinen harten Schlüssel und würde
		// (mit Warnung) erneut angelegt - hier reicht die Prüfung auf Klaras Zeile.
		await dialog.locator('input[type="file"]').setInputFiles({ name: 'mitglieder.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf-8') })
		await dialog.getByRole('button', { name: 'Prüfen', exact: true }).click()
		const klaraRow = dialog.locator('tr', { hasText: 'Klara Import' })
		await expect(klaraRow.getByText('existiert bereits', { exact: false })).toBeVisible()
	})
})
