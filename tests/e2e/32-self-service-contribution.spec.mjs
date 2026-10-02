import { expect, test } from '@playwright/test'
import { api, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Self-Service Beitrag-Aktionen (Issue #76, Spec §3.4): Mitglieder ändern
// ihren Monatsbeitrag (hoch frei, runter bis Untergrenze) und ihren Turnus
// (aus allowed_intervals) selbst - "sofort wirksam", mit einer Pflicht-
// Vorschau vor jedem Speichern (SelfServiceTab.vue: der Speichern-Knopf
// bleibt gesperrt, bis eine Vorschau für GENAU die eingetragenen Werte
// geladen wurde).
//
// Das Sperrfenster (Betrag/Turnus gesperrt, sobald für die Periode
// `prenotified_at` gesetzt ist - GitHub-Akzeptanzkriterium Issue #76:
// "Änderung wird abgelehnt mit Erklärung", siehe
// SelfContributionService::assertNotLocked()) lässt sich hier NICHT
// organisch herbeiführen: `prenotified_at` entsteht ausschließlich über den
// täglichen Einzugszyklus-Cron (ContributionDueCycleJob), und dessen eigene
// Nachzügler-Regel (ClaimGenerationService::dueDateWithNachzuegler())
// garantiert technisch, dass zwischen Erzeugung und Vorabinfo IMMER die
// volle Vorlauffrist (Standard 14 Tage) liegt - eine an einem Kalendertag
// frisch erzeugte Forderung ist an GENAU DIESEM Tag nie zugleich
// vorabinformierbar (siehe dortiger Klassendoc). Ohne Zeitreise (die es für
// E2E anders als ITimeFactory in PHPUnit nicht gibt) lässt sich ein
// gesperrter Zustand deshalb nicht in einem einzelnen Testlauf herstellen.
// Die Ablehnung selbst ist bereits vollständig durch
// SelfContributionServiceTest::testApplyLehntGesperrteAenderungAbUndSpeichertNichts()/
// testPreviewLehntGesperrteAenderungMitErklaerungAb() abgedeckt - hier wird
// deshalb STATISCH geprüft (Netzwerk-Mock der Vorschau-Antwort mit HTTP 400),
// dass die Oberfläche eine vom Server abgelehnte Änderung korrekt behandelt
// (keine Vorschau, Speichern bleibt gesperrt, Erklärung wird angezeigt).

const GROUP_NAME = 'Selfservice-Testgruppe'
const MEMBER = { firstName: 'Beate', lastName: 'Beitragszahler', email: 'beate.beitrag@example.org' }

test.describe('Self-Service Beitrag-Aktionen', () => {
	let memberId
	let assignmentId

	test.beforeAll(async ({ request }) => {
		// resetBook räumt auch ein verknüpftes Mitglied aus früheren Läufen/Specs
		// weg. Ein Mitglied mit Beitragsgruppen-Zuweisung lässt sich nicht per
		// DELETE entfernen (MemberService verweigert es wegen der Historie) -
		// ein gezieltes Löschen wie in 26-self-service ist hier nicht möglich.
		await api.resetBook(request)
		await api.updateSettings(request, { self_service_enabled: '1', membership_enabled: '1', club_name: 'Testverein e.V.' })

		const groupResp = await api.raw(request, 'POST', '/contribution-groups', {
			data: { name: GROUP_NAME, minMonthlyAmount: 5, defaultMonthlyAmount: 10, allowedIntervals: [1, 3, 12], defaultInterval: 12, isActive: true },
		})
		expect(groupResp.ok()).toBeTruthy()
		const group = await groupResp.json()

		const member = await api.createMember(request, MEMBER)
		memberId = member.id
		await api.linkMember(request, memberId, USERS.ohneRolle)

		// Überweiser statt Lastschrift: die Beitrags-Aktionen selbst (Betrag/
		// Turnus ändern) brauchen kein Mandat - das spart die Mandats-Anlage,
		// die für diesen Test nichts beiträgt.
		const validFrom = new Date().toISOString().slice(0, 10)
		const assignmentResp = await api.raw(request, 'POST', '/assignments', {
			data: { memberId, groupId: group.id, intervalMonths: 12, monthlyAmount: 10, paymentMethod: 'ueberweisung', validFrom },
		})
		expect(assignmentResp.ok()).toBeTruthy()
		const assignment = await assignmentResp.json()
		assignmentId = assignment.id
	})

	test.afterAll(async ({ request }) => {
		// Nur die Kontoverknüpfung lösen: das Mitglied hat eine Zuweisung (und
		// nach den Beitragsänderungen Historie) und ist deshalb per DELETE nicht
		// mehr entfernbar; die nächste Spec setzt den Bestand selbst zurück.
		if (memberId) {
			await api.unlinkMember(request, memberId)
		}
		await api.updateSettings(request, { self_service_enabled: '0', membership_enabled: '0' })
	})

	test('Betragsänderung oberhalb der Untergrenze: Speichern erst nach passender Vorschau möglich', async ({ page, request }) => {
		await openApp(page, USERS.ohneRolle)
		await switchTab(page, 'Mein Beitrag')

		const card = visibleSection(page).locator('.vbh-selfservice-assignment', { hasText: GROUP_NAME })
		await expect(card).toBeVisible()
		await expect(card).toContainText('5,00') // Untergrenze

		const saveButton = card.getByRole('button', { name: 'Speichern' })
		await expect(saveButton).toBeDisabled()

		await card.getByLabel('Monatsbeitrag (€)').fill('15')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(card.getByText(/Wirkt ab/)).toBeVisible()
		await expect(saveButton).toBeEnabled()

		await saveButton.click()
		await expect(saveButton).toBeDisabled() // Vorschau wird nach dem Speichern zurückgesetzt

		const assignments = await api.selfAssignments(request, { user: USERS.ohneRolle })
		expect(assignments.find((a) => a.id === assignmentId).monthlyAmount).toBe(15)
	})

	test('Betragsänderung unterhalb der Untergrenze: Vorschau lehnt ab, nichts wird gespeichert', async ({ page, request }) => {
		await openApp(page, USERS.ohneRolle)
		await switchTab(page, 'Mein Beitrag')

		const card = visibleSection(page).locator('.vbh-selfservice-assignment', { hasText: GROUP_NAME })
		const saveButton = card.getByRole('button', { name: 'Speichern' })

		await card.getByLabel('Monatsbeitrag (€)').fill('2') // Untergrenze ist 5 €
		await card.getByRole('button', { name: 'Vorschau' }).click()

		await expect(card.getByText(/Wirkt ab/)).toHaveCount(0)
		await expect(saveButton).toBeDisabled()

		// Serverseitig unverändert (der vorige Test hat auf 15 € geändert).
		const assignments = await api.selfAssignments(request, { user: USERS.ohneRolle })
		expect(assignments.find((a) => a.id === assignmentId).monthlyAmount).toBe(15)
	})

	test('Turnuswechsel: aus allowed_intervals wählbar, wirkt sofort', async ({ page, request }) => {
		await openApp(page, USERS.ohneRolle)
		await switchTab(page, 'Mein Beitrag')

		const card = visibleSection(page).locator('.vbh-selfservice-assignment', { hasText: GROUP_NAME })
		await card.getByLabel('Turnus (Monate)').selectOption('3')
		await card.getByRole('button', { name: 'Vorschau' }).click()
		await expect(card.getByText(/Wirkt ab/)).toBeVisible()

		await card.getByRole('button', { name: 'Speichern' }).click()
		await expect(card.getByRole('button', { name: 'Speichern' })).toBeDisabled()

		const assignments = await api.selfAssignments(request, { user: USERS.ohneRolle })
		expect(assignments.find((a) => a.id === assignmentId).intervalMonths).toBe(3)
	})

	test('Sperrfenster nach prenotified_at: eine vom Server abgelehnte Änderung zeigt die Erklärung und bleibt ungespeichert (statisch geprüft, siehe Erläuterung oben)', async ({ page }) => {
		const explanation = 'Für die laufende Periode wurde bereits eine Vorabinfo verschickt'

		await page.route('**/apps/vereinsbuchhaltung/api/self/assignments/*/preview', async (route) => {
			await route.fulfill({ status: 400, json: { message: `${explanation} – möglich wäre diese Änderung erst ab 2099-01-01.` } })
		})

		await openApp(page, USERS.ohneRolle)
		await switchTab(page, 'Mein Beitrag')

		const card = visibleSection(page).locator('.vbh-selfservice-assignment', { hasText: GROUP_NAME })
		const saveButton = card.getByRole('button', { name: 'Speichern' })

		await card.getByLabel('Monatsbeitrag (€)').fill('20')
		await card.getByRole('button', { name: 'Vorschau' }).click()

		// Die Ablehnung landet als Fehler-Toast (showError) - nicht als
		// "Wirkt ab"-Vorschau, und Speichern bleibt gesperrt.
		// .first(): neben dem Toast kann eine Bildschirmleser-Ansage mit demselben
		// Text im DOM stehen (siehe successToast() in 31-self-service-mandate).
		await expect(page.getByText(new RegExp(explanation)).first()).toBeVisible()
		await expect(card.getByText(/Wirkt ab/)).toHaveCount(0)
		await expect(saveButton).toBeDisabled()
	})
})
