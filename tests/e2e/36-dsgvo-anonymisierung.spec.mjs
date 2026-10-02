import { expect, test } from '@playwright/test'
import { api, openApp, switchTab, tabButton, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// DSGVO-Anonymisierung & Datenübersicht (Issue #78, Spec §3.8, T30): letztes
// Glied der 14-Ticket-Kette aus Issue #63.
//
// Was diese Spec NICHT abdeckt (bewusst, siehe unten "statisch geprüft"):
// den eigentlichen Anonymisierungsvollzug für ein tatsächlich
// anonymisierungsreifes Mitglied. Die Reife hängt an der letzten zugehörigen
// Buchung vor über 10 Jahren (AnonymizationEligibilityCalculator) - das in der
// E2E-Fixture-Umgebung herzustellen bräuchte ein Geschäftsjahr, das ein
// Jahrzehnt zurückreicht, was den gemeinsam genutzten Testbestand aller
// anderen Spec-Dateien verzerren würde. Die Fristberechnung (inkl. Dominanz
// der 10-Jahres-Regel über die SEPA-14-Monats-Untergrenze) und der komplette
// Redigier-Vorgang (Strukturdaten bleiben, Freitexte/Namen sind geschwärzt)
// sind stattdessen erschöpfend durch PHPUnit abgedeckt:
// tests/unit/AnonymizationEligibilityCalculatorTest.php,
// tests/unit/AnonymizationCandidateServiceTest.php,
// tests/unit/MemberAnonymizationServiceTest.php.
//
// Was hier tatsächlich geprüft wird: die beiden Zugangswege zur
// "Datenübersicht" (Art. 15 DSGVO, kein strukturierter Export nach Art. 20)
// und die serverseitige Sicherheitsschranke, dass sich ein frisches, noch
// nicht anonymisierungsreifes Mitglied NICHT anonymisieren lässt - weder über
// die Oberfläche (kein "Jetzt anonymisieren"-Knopf) noch über einen direkten
// API-Aufruf (400 statt stillschweigendem Erfolg).
//
// HINWEIS: dieser Test wurde wegen des zum Zeitpunkt der Implementierung
// belegten gemeinsamen Docker-E2E-Testservers (siehe PR-Beschreibung) nur
// statisch gegen die bestehenden Fixture-Helfer geprüft, nicht live gegen den
// Container ausgeführt - Aufbau und Selektoren folgen bewusst eng den bereits
// laufenden Specs 23-members.spec.mjs/26-self-service.spec.mjs/
// 35-beitragsbescheinigung.spec.mjs.

const MEMBER = { firstName: 'Nora', lastName: 'Neumitglied' }

test.describe('DSGVO: Datenübersicht & Anonymisierung', () => {
	test('Kassenwart-Kanal: Datenübersicht öffnet, frisches Mitglied ist nicht anonymisierungsreif', async ({ page, request }) => {
		const member = await api.createMember(request, MEMBER)

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('tr', { hasText: 'Nora Neumitglied' })
		await row.getByRole('button', { name: 'Aktionen' }).click()
		await page.getByRole('menuitem', { name: 'Akte öffnen' }).click()

		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()
		await expect(dialog.getByRole('heading', { name: /Datenübersicht/ })).toBeVisible()

		const [popup] = await Promise.all([
			page.context().waitForEvent('page'),
			dialog.getByRole('link', { name: 'Datenübersicht öffnen' }).click(),
		])
		await popup.waitForLoadState()

		await expect(popup.getByRole('heading', { name: 'Datenübersicht' })).toBeVisible()
		await expect(popup.getByText('Nora Neumitglied')).toBeVisible()
		// Kein strukturierter Export nach Art. 20 DSGVO - der Hinweis steht
		// unübersehbar in der Kopfzeile (Spec §3.8).
		await expect(popup.getByText(/Art\. 20/)).toBeVisible()
		await popup.close()

		// Anonymisierung (Art. 17 DSGVO): ein frisches Mitglied ohne jede
		// Buchung ist nie anonymisierungsreif (Spec §3.8 "letzte zugehörige
		// Buchung") - kein Knopf, nur der erklärende Hinweis.
		await expect(dialog.getByRole('heading', { name: /Anonymisierung/ })).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Jetzt anonymisieren' })).toHaveCount(0)
		await expect(dialog.getByText(/noch keine zugehörige Buchung/i)).toBeVisible()

		await api.deleteMember(request, member.id)
	})

	test('Serverseitige Schranke: ein nicht anonymisierungsreifes Mitglied lässt sich nicht anonymisieren', async ({ request }) => {
		const member = await api.createMember(request, MEMBER)

		const resp = await api.raw(request, 'POST', `/members/${member.id}/anonymize`, { user: USERS.buchhalter })
		expect(resp.status()).toBe(400)
		const body = await resp.json()
		expect(body.message).toMatch(/anonymisierungsreif/)

		// Die Stammdaten sind unverändert - kein Teilerfolg, kein stiller Fehler.
		const reloaded = await api.raw(request, 'GET', `/members/${member.id}`, { user: USERS.buchhalter })
		const data = await reloaded.json()
		expect(data.firstName).toBe('Nora')
		expect(data.redactedAt).toBeNull()

		await api.deleteMember(request, member.id)
	})

	test('Self-Service-Kanal: "Meine Daten" zeigt die eigene Datenübersicht (IDOR-sicher über die Kontoverknüpfung)', async ({ page, request }) => {
		await api.updateSettings(request, { self_service_enabled: '1' })
		const member = await api.createMember(request, { ...MEMBER, email: 'nora.neumitglied@example.org' })
		await api.linkMember(request, member.id, USERS.ohneRolle)

		try {
			await openApp(page, USERS.ohneRolle)
			const tab = tabButton(page, 'Mein Beitrag')
			await expect(tab).toBeVisible()
			await tab.click()

			const section = visibleSection(page)
			await expect(section.getByText('Nora Neumitglied')).toBeVisible()

			const [popup] = await Promise.all([
				page.context().waitForEvent('page'),
				section.getByRole('link', { name: 'Datenübersicht öffnen' }).click(),
			])
			await popup.waitForLoadState()

			// Der Server löst die member_id ausschließlich über die
			// Kontoverknüpfung auf (ActorContextService) - die Seite zeigt
			// zwangsläufig genau die eigenen, gerade verknüpften Stammdaten.
			await expect(popup.getByRole('heading', { name: 'Datenübersicht' })).toBeVisible()
			await expect(popup.getByText('Nora Neumitglied')).toBeVisible()
			await popup.close()
		} finally {
			await api.unlinkMember(request, member.id)
			await api.deleteMember(request, member.id)
			await api.updateSettings(request, { self_service_enabled: '0' })
		}
	})
})
