import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, openApp, returnEntry, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// „Alle Daten löschen“ räumt auch den Einzug (Issue #123): Lastschrift-Läufe,
// Einzugsposten, Rücklastschriften und Mahnstufen hängen an den Forderungen, die
// der Reset ohnehin löscht – blieben sie stehen, zeigte der Einzug-Reiter auf
// offene Posten, die es nicht mehr gibt. Stammdaten (Mitglieder, Mandate,
// Zuweisungen) bleiben; der Verweis der Mandatssperre auf die gelöschte
// Rücklastschrift fällt weg, die Sperre selbst bleibt.
//
// Aufgebaut wird der ganze Weg über die API: Mitglied mit Mandat, Forderung, Lauf
// freigeben und einreichen, Rücklastschrift (AC04: Konto erloschen, das Mandat
// wird gesperrt) über einen camt-Auszug einspielen und verbuchen – das legt
// Lauf, Posten, Rücklastschrift und die Zahlungsaufforderung (Mahnstufe 0) an.
// Danach löscht `api.resetBook()` – derselbe Aufruf, mit dem jede Spec startet –
// und die Spec prüft, was übrig ist: über die API und im Einzug-Reiter, der
// dabei weder Serverfehler noch Skriptfehler zeigen darf.
//
// Die Zahlungsaufforderung braucht den NC-Mail-Modus „null“ (Mails werden
// angenommen und verworfen; ohne Zustellweg vermerkt das Mahnwesen keine Stufe),
// siehe 44-einzug-bankabgleich. Die Einstellung steht in config.php, nicht im
// Datenbank-Snapshot: in afterAll wieder entfernen.

const IBAN = 'DE02120300000000202051'
const FEE_ACCOUNT = '5400'

const iso = (date) => date.toISOString().slice(0, 10)
const today = () => iso(new Date())
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
/** Kurzes Kennzeichen dieses Durchlaufs für Mitgliedsnamen. */
const runTag = () => Date.now().toString(36)

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

/**
 * Was die Oberfläche an Skriptfehlern und Serverfehlern (5xx) der App erlebt – die Liste füllt sich, solange die Seite offen ist.
 * Ein Verweis auf eine gelöschte Forderung endet im Dienst in einem Fehler, nicht in einer 4xx-Antwort; 4xx (etwa 403 für
 * Self-Service-Aufrufe ohne verknüpftes Mitglied) gehört zum Normalbetrieb und zählt deshalb nicht.
 */
function watchAppProblems(page) {
	const problems = []
	page.on('pageerror', (error) => problems.push(`Skriptfehler: ${error.message}`))
	page.on('response', (response) => {
		if (response.url().includes('/apps/vereinsbuchhaltung/') && response.status() >= 500) {
			problems.push(`HTTP ${response.status()}: ${response.url()}`)
		}
	})
	page.on('console', (message) => {
		if (message.type() === 'error' && /vereinsbuchhaltung|TypeError|ReferenceError|Unhandled/i.test(message.text())) {
			problems.push(`Konsole: ${message.text()}`)
		}
	})
	return problems
}

test.describe('Alle Daten löschen: Einzug-Daten', () => {
	test.beforeAll(async () => {
		await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'null'], { container: getContainer() })
		// Die Zahlungsaufforderung geht aus einem Web-Request raus: Apache liest config.php über opcache
		// (Standard: Zeitstempel alle 2 s prüfen), ohne Pause sähe der erste Request noch den alten Mail-Modus.
		await new Promise((resolve) => setTimeout(resolve, 4000))
	})

	test.afterAll(async () => {
		await runOcc(['config:system:delete', 'mail_smtpmode'], { container: getContainer() })
	})

	test('Reset nach Einzug-Daten: Läufe und Forderungen sind weg, Mitglied und Mandat bleiben, der Einzug-Reiter ist leer', async ({ page, request }) => {
		test.setTimeout(120000)
		const tag = runTag()
		const dueDate = plusDays(41)
		const label = `Beitrag Reset ${tag}`

		// --- Bestand mit allem, was an einer Forderung hängt ----------------------
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
		const [income, fee] = await api.accountsByNumber(request, INCOME_ACCOUNT, FEE_ACCOUNT)
		await api.setSepaImportSettings(request, { returnFeeAccountId: fee.id, returnFeeRechargeEnabled: '0', contributionDefaultAccountId: income.id })

		const member = await api.createMember(request, { firstName: 'Reset', lastName: `Einzug ${tag}`, email: `reset.einzug.${tag}@example.org` })
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: IBAN, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
		const claim = await api.raw(request, 'POST', '/claims', { data: { memberId: member.id, type: 'beitrag', amount: 12.5, label, dueDate } })
		expect(claim.ok()).toBeTruthy()

		const batch = await api.releaseAndSubmitDebitBatch(request, dueDate)
		const item = batch.items.find((i) => i.remittanceInfo === label)
		expect(item).toBeTruthy()
		await api.importCamtStatement(request, [returnEntry({ bookingDate: today(), item, reasonCode: 'AC04' })])
		const worklist = await api.findReconciliationItem(request, `Rücklastschrift ${item.memberDisplayName}`)
		await api.decideSepaDetail(request, worklist.details[0].id, 'assign', { debitItemId: worklist.details[0].candidates[0].debitItemId })
		await api.settleSepaImport(request, worklist.bankTx.id)

		// Vorbedingungen: der Einzug ist gefüllt, das Mandat nach der Rücklastschrift gesperrt – mit Verweis darauf
		expect((await api.getJson(request, '/debit-batches')).some((b) => b.id === batch.id)).toBe(true)
		const overview = await api.getJson(request, '/claims/overview')
		expect(overview.claims.find((c) => c.description === label).dunning.stage, 'die Zahlungsaufforderung ist als Mahnstufe 0 vermerkt').toBe(0)
		const suspended = (await api.mandatesByMember(request, member.id)).find((m) => m.id === mandate.id)
		expect(suspended.status).toBe('ausgesetzt')
		expect(suspended.suspensionOrigin).toBe('ruecklastschrift')
		expect(suspended.returnedDebitId).not.toBeNull()

		// --- Zurücksetzen ------------------------------------------------------------
		await api.resetBook(request)

		// Läufe, Forderungen, Umsätze: nichts mehr da, worauf etwas zeigen könnte
		expect(await api.getJson(request, '/debit-batches')).toEqual([])
		expect((await api.getJson(request, '/claims/overview')).claims).toEqual([])
		expect(await api.getJson(request, '/claims')).toEqual([])
		expect((await api.bankReconciliation(request)).items).toEqual([])

		// Stammdaten bleiben; die Sperre auch, aber ohne Verweis auf die gelöschte Rücklastschrift
		expect((await api.listMembers(request)).some((m) => m.id === member.id)).toBe(true)
		const kept = (await api.mandatesByMember(request, member.id)).find((m) => m.id === mandate.id)
		expect(kept.status).toBe('ausgesetzt')
		expect(kept.suspensionOrigin).toBe('ruecklastschrift')
		expect(kept.returnedDebitId).toBeNull()
		// … und die Aufgabenliste kommt damit zurecht: neutraler Text statt eines Grunds, den es nicht mehr gibt
		const tasks = await api.getJson(request, '/tasks', { user: USERS.buchhalter })
		const suspensionTask = tasks.find((t) => t.message.includes(`Einzug ${tag}`) && t.message.includes('gesperrt'))
		expect(suspensionTask?.message).toContain('Mandat nach einer Rücklastschrift gesperrt, Klärung offen')

		// --- Der Einzug-Reiter zeigt den leeren Bestand ------------------------------
		const problems = watchAppProblems(page)
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
		await expect(visibleSection(page).getByText(/^Noch kein Lauf\./)).toBeVisible()
		await visibleSection(page).getByRole('tab', { name: 'Forderungen', exact: true }).click()
		await expect(visibleSection(page).getByText('Es gibt noch keine Forderung an ein Mitglied.')).toBeVisible()
		await visibleSection(page).getByRole('tab', { name: 'Bankabgleich', exact: true }).click()
		await expect(visibleSection(page).getByRole('tabpanel', { name: 'Bankabgleich' }).getByRole('heading', { name: 'Bankabgleich', exact: true })).toBeVisible()
		expect(problems, 'der Einzug-Reiter hat nach dem Reset weder Skriptfehler noch Serverfehler').toEqual([])
	})
})
