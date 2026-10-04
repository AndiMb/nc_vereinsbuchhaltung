import { test, expect } from '@playwright/test'
import { api, openApp, tabButton, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Aufgaben-Katalog im Flyout (Issue #117, Spec §7): die Mandat-Aufgaben (Entwurf
// auf Papier, gesperrt, erloschen bei weiter gewollter Lastschrift, ohne
// Nachweis, verfällt in N Tagen) und die aggregierten Forderungs-Aufgaben stehen
// mit Klartext, Schweregrad und Sprungziel im Flyout - und verschwinden von
// selbst, sobald ihre Ursache behoben ist (kein Quittieren).
//
// Der Bestand anderer Specs bleibt stehen (/reset räumt Mitglieder, Mandate und
// Zuweisungen NICHT ab). Jeder Test legt deshalb ein eigenes Mitglied mit
// eindeutigem Namen an und findet seine Zeile über diesen Namen; Zahlen werden
// nie als absolute Werte erwartet. Einstellungen, die ein Test ändert, stellt
// afterAll wieder auf den Standard.
//
// Nicht live seedbar: „Rücklastschrift ohne Wiedereinzug" (braucht einen
// Kontoauszug mit Rückgabegrund und bestätigter Zuordnung), „Ausgetreten mit
// offenen Forderungen" (der Austritt beendet die Zuweisungen und führt nur über
// den Tageslauf zu einem aktiven Mandat mit offenen Forderungen) und die
// Auflösung persistierter Hinweise (NC-Konto-Löschung, Migration). Diese Fälle
// decken die PHPUnit-Tests ab (ClaimFollowUpTaskServiceTest, MandateTaskServiceTest,
// TaskServiceTest).

const IBAN = 'DE02120300000000202051'
const GROUP_NAME = 'Testgruppe Aufgaben-Katalog'

const iso = (date) => date.toISOString().slice(0, 10)
const today = () => iso(new Date())
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const name = (m) => `${m.firstName} ${m.lastName}`

async function enableMembership(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

/** Standard der beiden Einstellungen, die dieser Spec ändert. */
const DEFAULT_SETTINGS = { show_missing_document_warning: '1', expiry_warning_days: '180' }

/** Ein frisches Mitglied je Aufruf: der Name trägt eine Zufallskennung, ein Vorlauf kann nichts stören. */
async function freshMember(request, firstName, lastNameStem) {
	const suffix = Math.random().toString(36).slice(2, 8)
	const lastName = `${lastNameStem}${suffix}`
	return api.createMember(request, { firstName, lastName, email: `${firstName}.${lastName}@example.org`.toLowerCase() })
}

async function ensureGroup(request) {
	const groups = await api.getJson(request, '/contribution-groups')
	let group = groups.find((g) => g.name === GROUP_NAME)
	if (!group) {
		group = await (await api.raw(request, 'POST', '/contribution-groups', {
			expectOk: true,
			data: { name: GROUP_NAME, minMonthlyAmount: 5, defaultMonthlyAmount: 10, allowedIntervals: [1, 3, 12], defaultInterval: 12, isActive: true },
		})).json()
	}
	return group
}

async function addDirectDebitAssignment(request, member) {
	const group = await ensureGroup(request)
	await api.raw(request, 'POST', '/assignments', {
		expectOk: true,
		data: { memberId: member.id, groupId: group.id, intervalMonths: 12, monthlyAmount: 10, paymentMethod: 'direct_debit', validFrom: today() },
	})
}

/** Papier-Mandat anlegen; mit `signedAt` und `activate` gleich aktiv. */
async function createMandate(request, member, { signedAt = undefined, activate = false } = {}) {
	const created = await api.createMandate(request, { memberId: member.id, iban: IBAN, accountHolder: name(member), signedAt })
	const mandate = await created.json()
	if (activate) {
		await api.activateMandate(request, mandate.id, { signedAt })
	}
	return mandate
}

/** Die Aufgaben, wie der Server sie dem Buchhalter liefert. */
async function serverTasks(request) {
	return api.getJson(request, '/tasks', { user: USERS.buchhalter })
}

const flyoutButton = (page) => page.locator('.vbh-navright').getByRole('button', { name: /^Aufgaben/ })
const flyout = (page) => page.getByRole('dialog', { name: 'Aufgaben', exact: true })

async function openFlyout(page) {
	await flyoutButton(page).click()
	await expect(flyout(page)).toBeVisible()
	return flyout(page)
}

const refresh = (dialog) => dialog.getByRole('button', { name: 'Aufgaben aktualisieren' }).click()

test.describe('Aufgaben-Katalog', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
		await api.updateSettings(request, DEFAULT_SETTINGS)
	})

	test.afterAll(async ({ request }) => {
		await api.updateSettings(request, DEFAULT_SETTINGS)
	})

	test('Papier-Mandat im Entwurf: Handlungsbedarf mit Sprung in die Akte', async ({ page, request }) => {
		const member = await freshMember(request, 'Paula', 'Entwurf')
		await createMandate(request, member)

		await openApp(page, USERS.buchhalter)
		const dialog = await openFlyout(page)
		const item = dialog.locator('.vbh-tasks-item--action', { hasText: name(member) })
		await expect(item).toContainText('Papier-Mandat ist noch ein Entwurf, die Unterschrift fehlt')
		await expect(item.locator('.vbh-typetag')).toHaveText('Mandat')

		await item.getByRole('button', { name: 'Zur Akte' }).click()

		await expect(dialog).toBeHidden()
		await expect(tabButton(page, 'Beiträge')).toHaveClass(/active/)
		await expect(page.getByRole('heading', { name: new RegExp(name(member)) })).toBeVisible()
	})

	test('der Entwurf verschwindet von selbst, sobald das Mandat aktiviert ist', async ({ page, request }) => {
		const member = await freshMember(request, 'Paul', 'Aktiviert')
		const mandate = await createMandate(request, member)

		await openApp(page, USERS.buchhalter)
		const dialog = await openFlyout(page)
		const draft = dialog.locator('.vbh-tasks-item--action', { hasText: `${name(member)}: Papier-Mandat ist noch ein Entwurf` })
		await expect(draft).toBeVisible()
		// Kein Quittieren: nichts zum Wegklicken, nur Aktualisieren und Sprung.
		await expect(draft.getByRole('button', { name: /erledigt|quittier|ausblenden|verwerfen/i })).toHaveCount(0)

		await api.activateMandate(request, mandate.id, { signedAt: today() })
		await refresh(dialog)

		await expect(draft).toHaveCount(0)
	})

	test('gesperrtes Mandat: Handlungsbedarf mit der Notiz, weg nach dem Entsperren', async ({ page, request }) => {
		const member = await freshMember(request, 'Sabine', 'Gesperrt')
		const mandate = await createMandate(request, member, { signedAt: '2025-06-01', activate: true })
		await api.suspendMandate(request, mandate.id, { note: 'Bankkonto wird gewechselt' })

		await openApp(page, USERS.buchhalter)
		const dialog = await openFlyout(page)
		const item = dialog.locator('.vbh-tasks-item--action', { hasText: name(member) })
		await expect(item).toContainText('Mandat gesperrt, Klärung offen')
		await expect(item).toContainText('Bankkonto wird gewechselt')

		await api.resumeMandate(request, mandate.id)
		await refresh(dialog)

		await expect(dialog.locator('.vbh-tasks-item--action', { hasText: `${name(member)}: Mandat gesperrt` })).toHaveCount(0)
	})

	test('Mandat ohne Nachweis ist ein Hinweis und verschwindet, wenn die Einstellung ausgeschaltet wird', async ({ page, request }) => {
		const member = await freshMember(request, 'Nora', 'Nachweislos')
		await createMandate(request, member, { signedAt: '2025-06-01', activate: true })

		await openApp(page, USERS.buchhalter)
		const dialog = await openFlyout(page)
		const hint = dialog.locator('.vbh-tasks-item--hint', { hasText: name(member) })
		await expect(hint).toContainText('Zum Mandat ist kein Nachweis hinterlegt')
		// Ein Hinweis steht im Flyout, zählt aber nicht ins Badge.
		await expect(dialog.locator('.vbh-tasks-item--action', { hasText: name(member) })).toHaveCount(0)

		await api.updateSettings(request, { show_missing_document_warning: '0' })
		await refresh(dialog)

		await expect(hint).toHaveCount(0)
	})

	test('Mandat verfällt bald: Hinweis mit Datum, die Ablauf-Vorwarnung der Verwaltung bestimmt das Fenster', async ({ page, request }) => {
		// Unterschrieben vor 36 Monaten minus 20 Tagen: die 36-Monats-Frist endet in rund 20 Tagen.
		const signed = new Date()
		signed.setMonth(signed.getMonth() - 36)
		signed.setDate(signed.getDate() + 20)
		const member = await freshMember(request, 'Vera', 'Verfallend')
		await createMandate(request, member, { signedAt: iso(signed), activate: true })

		await openApp(page, USERS.buchhalter)
		const dialog = await openFlyout(page)
		const hint = dialog.locator('.vbh-tasks-item--hint', { hasText: `${name(member)}: Mandat verfällt am` })
		await expect(hint).toContainText(/verfällt am \d{2}\.\d{2}\.\d{4} \(in \d+ Tagen?\)/)

		// Ein Fenster von 10 Tagen schließt die 20 Tage Restlaufzeit aus.
		await api.updateSettings(request, { expiry_warning_days: '10' })
		await refresh(dialog)

		await expect(hint).toHaveCount(0)
	})

	test('erloschenes Mandat bei weiter gewollter Lastschrift: genau eine Zeile statt zwei, weg mit einem neuen Mandat', async ({ page, request }) => {
		const member = await freshMember(request, 'Erwin', 'Erloschen')
		await addDirectDebitAssignment(request, member)
		const mandate = await createMandate(request, member, { signedAt: '2025-06-01', activate: true })
		await api.revokeMandate(request, mandate.id)

		// Dasselbe Problem steht nur einmal in der Liste: die genaue Aufgabe ersetzt das
		// allgemeine „kein einzugsfähiges Mandat“.
		const own = (await serverTasks(request)).filter((t) => t.message.includes(name(member)))
		expect(own).toHaveLength(1)
		expect(own[0].severity).toBe('handlungsbedarf')
		expect(own[0].message).toContain('Das Mandat ist erloschen (widerrufen), die Zuweisung verlangt aber weiter Lastschrift')
		expect(own[0].message).not.toContain('kein einzugsfähiges Mandat')

		await openApp(page, USERS.buchhalter)
		const dialog = await openFlyout(page)
		const item = dialog.locator('.vbh-tasks-item--action', { hasText: name(member) })
		await expect(item).toContainText('Neues Mandat einholen oder auf Überweisung umstellen')
		await expect(item.getByRole('button', { name: 'Zur Akte' })).toBeVisible()

		// Ursache beheben: ein neues, aktives Mandat.
		await createMandate(request, member, { signedAt: today(), activate: true })
		await refresh(dialog)

		await expect(dialog.locator('.vbh-tasks-item', { hasText: `${name(member)}: Das Mandat ist erloschen` })).toHaveCount(0)
	})

	test('offene Forderungen nach einem Widerruf: eine Zeile mit Anzahl, Sprung in den Einzug, weniger nach dem Erledigen', async ({ page, request }) => {
		const widerrufCount = (tasks) => {
			const row = tasks.find((t) => t.message.includes('nach Widerruf des Mandats weiter offen'))
			return row ? Number(row.message.match(/^(\d+) Forderung/)[1]) : 0
		}
		const member = await freshMember(request, 'Willi', 'Widerruf')
		const mandate = await createMandate(request, member, { signedAt: '2025-06-01', activate: true })
		await api.revokeMandate(request, mandate.id)
		const created = await api.raw(request, 'POST', '/claims', {
			expectOk: true,
			data: { memberId: member.id, type: 'beitrag', amount: 12.5, label: 'Beitrag nach Widerruf', dueDate: plusDays(20) },
		})
		const claim = await created.json()
		const before = widerrufCount(await serverTasks(request))
		expect(before).toBeGreaterThanOrEqual(1)

		await openApp(page, USERS.buchhalter)
		const dialog = await openFlyout(page)
		const row = dialog.locator('.vbh-tasks-item--hint', { hasText: 'nach Widerruf des Mandats weiter offen' })
		await expect(row).toHaveCount(1) // aggregiert: eine Zeile, egal wie viele Forderungen
		await expect(row.locator('.vbh-typetag')).toHaveText('Forderungen')

		await row.getByRole('button', { name: 'Zum Einzug' }).click()
		await expect(dialog).toBeHidden()
		await expect(tabButton(page, 'Beiträge')).toHaveClass(/active/)
		await expect(visibleSection(page).getByRole('tablist', { name: 'Ansicht im Einzug' })).toBeVisible()

		// Erledigen behebt die Ursache: die Zeile zählt eine Forderung weniger (oder verschwindet).
		expect((await api.raw(request, 'POST', `/claims/${claim.id}/settle`, { data: { settlementType: 'paid', note: 'bar' } })).ok()).toBeTruthy()
		expect(widerrufCount(await serverTasks(request))).toBe(before - 1)
	})

	test('die Aufgabenliste bleibt Buchhaltern vorbehalten: der Revisor wird abgewiesen', async ({ request }) => {
		const response = await api.raw(request, 'GET', '/tasks', { user: USERS.revisor })
		expect(response.status()).toBe(403)
	})
})
