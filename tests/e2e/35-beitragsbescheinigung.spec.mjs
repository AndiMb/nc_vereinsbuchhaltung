import { expect, test } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, USERS } from './fixtures/nextcloud.mjs'

// Informelle Beitragsbestätigung (Issue #77, Spec §3.7): eine druckfertige
// Live-Ansicht je Beitragsjahr - keine amtliche Zuwendungsbestätigung nach
// §10b EStG (separates Upstream-Issue #10), kein gespeichertes Dokument.
//
// Diese Spec prüft den Kassenwart-Kanal (Stellvertretung über die
// Admin-Akte, siehe MemberDialog.vue) statt des Self-Service-Kanals: er
// braucht keine NC-Konto-Verknüpfung und keinen self_service_enabled-Schalter,
// deckt aber denselben Renderer ({@see BeitragsbescheinigungRenderer}) ab wie
// der Self-Service-Kanal (SelfController::certificate()) - die IDOR-Schutz-
// Eigenschaft des Self-Service-Pfads selbst ist bereits durch
// SelfControllerTest (PHPUnit) abgedeckt.
//
// Kernanforderung des Akzeptanzkriteriums: ein Mitglied mit gemischten
// Beitrags-/Gebühren-Forderungen zeigt in der Bestätigung nur die
// Beitragsposten, korrekt summiert - die Gebühr fließt nirgends ein.

const MEMBER = { firstName: 'Petra', lastName: 'Beitragspflichtig' }

test.describe('Beitragsbestätigung', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.updateSettings(request, { membership_enabled: '1', club_name: 'Testverein e.V.' })
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, { membership_enabled: '0' })
	})

	test('zeigt bei gemischten Beitrags-/Gebühren-Forderungen nur die Beitragsposten korrekt summiert', async ({ page, request }) => {
		const member = await api.createMember(request, MEMBER)
		const today = new Date().toISOString().slice(0, 10)

		// Zwei bezahlte Beitrags-Forderungen (15 € + 20 € = 35 €) ...
		const beitrag1 = await api.raw(request, 'POST', '/claims', {
			data: { memberId: member.id, type: 'beitrag', amount: 15, label: 'Quartalsbeitrag Q1', dueDate: today },
		})
		expect(beitrag1.ok()).toBeTruthy()
		const beitrag2 = await api.raw(request, 'POST', '/claims', {
			data: { memberId: member.id, type: 'beitrag', amount: 20, label: 'Quartalsbeitrag Q2', dueDate: today },
		})
		expect(beitrag2.ok()).toBeTruthy()
		// ... und eine bezahlte Gebühren-Forderung, die NIE einfließen darf
		// (Spec §3.7: "Gebühren-Forderungen fließen nie ein").
		const gebuehr = await api.raw(request, 'POST', '/claims', {
			data: { memberId: member.id, type: 'gebuehr', amount: 50, label: 'Verwaltungsgebühr', dueDate: today },
		})
		expect(gebuehr.ok()).toBeTruthy()

		for (const resp of [beitrag1, beitrag2, gebuehr]) {
			const item = await resp.json()
			const settleResp = await api.raw(request, 'POST', `/claims/${item.id}/settle`, { data: { settlementType: 'paid' } })
			expect(settleResp.ok()).toBeTruthy()
		}

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('tr', { hasText: 'Petra Beitragspflichtig' })
		await row.getByRole('button', { name: 'Aktionen' }).click()
		await page.getByRole('menuitem', { name: 'Akte öffnen' }).click()

		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()

		// Die Jahresauswahl lädt asynchron nach - vor dem Klick abwarten, sonst
		// wäre der Test von einer Wettlaufsituation zwischen Laden und Klicken
		// abhängig (auch wenn der Server ohne Jahresangabe ohnehin auf das
		// laufende Beitragsjahr zurückfiele).
		const currentYear = String(new Date().getFullYear())
		const yearSelect = dialog.getByRole('combobox', { name: 'Beitragsjahr' })
		await expect(yearSelect).toHaveValue(currentYear)

		const [popup] = await Promise.all([
			page.context().waitForEvent('page'),
			// exact: der Knopf daneben heißt "Datenübersicht öffnen" - getByRole
			// sucht Teilstrings ohne Beachtung der Groß-/Kleinschreibung.
			dialog.getByRole('link', { name: 'Öffnen', exact: true }).click(),
		])
		await popup.waitForLoadState()

		await expect(popup.getByRole('heading', { name: /Beitragsbestätigung/ })).toBeVisible()
		await expect(popup.getByText('Petra Beitragspflichtig')).toBeVisible()

		// Beide Beitragsposten sind einzeln aufgeführt ...
		await expect(popup.getByText('Quartalsbeitrag Q1')).toBeVisible()
		await expect(popup.getByText('Quartalsbeitrag Q2')).toBeVisible()
		// ... die Gebühr taucht an keiner Stelle auf ...
		await expect(popup.getByText('Verwaltungsgebühr')).toHaveCount(0)
		await expect(popup.getByText('50,00 €')).toHaveCount(0)
		// ... und die Summenzeile zählt ausschließlich die beiden Beiträge.
		await expect(popup.getByText('35,00 €')).toBeVisible()

		// Der Verweis auf die separate, amtliche Zuwendungsbestätigung
		// (Issue #10) steht unübersehbar auf der Seite (Spec §3.7).
		await expect(popup.getByText(/§ ?10b EStG/)).toBeVisible()

		await popup.close()
	})
})
