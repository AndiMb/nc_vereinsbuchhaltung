import { test, expect } from '@playwright/test'
import { api, openApp, tabButton, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Aufgaben-Flyout mit Badge in der Kopfzeile (Issue #99, Spec §6/§7): die
// abgeleitete Aufgabenliste des Servers (GET /api/tasks) wird für Buchhalter
// sichtbar - Badge zählt nur Handlungsbedarf, Hinweise stehen im Flyout, jede
// Aufgabe springt in ihren Kontext, und eine Aufgabe verschwindet von selbst,
// sobald ihre Ursache behoben ist (kein Quittieren).
//
// Der Bestand anderer Specs bleibt stehen (/reset räumt Mitglieder, Mandate und
// Zuweisungen NICHT ab): ein Mitglied mit Lastschrift-Zuweisung ohne Mandat aus
// einer früheren Spec steht hier ebenfalls im Flyout. Die Zahl im Badge wird
// deshalb gegen die API gerechnet statt gegen eine feste Zahl, und die eigenen
// Fälle werden über ihre (eindeutigen) Namen gefunden.

const GROUP_NAME = 'Testgruppe Aufgaben'
const IBAN = 'DE02120300000000202051'
// Lastschrift gewollt, aber kein Mandat → Handlungsbedarf (Zuweisung → Akte).
const TESSA = { firstName: 'Tessa', lastName: 'Aufgabenfall' }
// Elektronischer Entwurf, Link frisch verschickt → bloßer Hinweis (Mandat → Akte).
const THEO = { firstName: 'Theo', lastName: 'Linkwartend' }

const today = () => new Date().toISOString().slice(0, 10)
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

async function ensureMember(request, { firstName, lastName }) {
	const displayName = `${firstName} ${lastName}`
	const existing = (await api.listMembers(request)).find((m) => m.displayName === displayName)
	return existing ?? api.createMember(request, { firstName, lastName, email: `${firstName}.${lastName}@example.org`.toLowerCase() })
}

async function ensureDirectDebitAssignment(request, member, group) {
	const existing = await api.getJson(request, `/assignments?memberId=${member.id}`)
	if (existing.length > 0) { return }
	await api.raw(request, 'POST', '/assignments', {
		expectOk: true,
		data: { memberId: member.id, groupId: group.id, intervalMonths: 12, monthlyAmount: 10, paymentMethod: 'direct_debit', validFrom: today() },
	})
}

/** Beide Aufgaben-Fälle dieser Spec; nur anlegen, was fehlt (beforeAll läuft nach jedem Fehlschlag erneut). */
async function ensureSeed(request) {
	const group = await ensureGroup(request)

	const tessa = await ensureMember(request, TESSA)
	await ensureDirectDebitAssignment(request, tessa, group)

	const theo = await ensureMember(request, THEO)
	if ((await api.mandatesByMember(request, theo.id)).length === 0) {
		const created = await api.createElectronicMandate(request, { memberId: theo.id, iban: IBAN, accountHolder: name(THEO) })
		const mandate = await created.json()
		// Der Hinweis hängt am ausstehenden Link, nicht am Mailversand: der Token
		// steht schon vor dem Senden in der Datenbank. Ein Testserver ohne
		// funktionierenden Mailweg antwortet hier mit 500 - das ist für diesen
		// Fall egal und soll ihn nicht kippen.
		await api.sendActivationLink(request, mandate.id, { expectOk: false })
	}
	return { tessa, theo }
}

/** Die Aufgaben, wie der Server sie dem Buchhalter liefert. */
async function serverTasks(request) {
	const tasks = await api.getJson(request, '/tasks', { user: USERS.buchhalter })
	return {
		all: tasks,
		action: tasks.filter((t) => t.severity === 'handlungsbedarf'),
		hints: tasks.filter((t) => t.severity === 'hinweis'),
	}
}

/** Der Knopf in der Kopfzeile; der Name trägt die Zahl („Aufgaben – 3 mit Handlungsbedarf"). */
const flyoutButton = (page) => page.locator('.vbh-navright').getByRole('button', { name: /^Aufgaben/ })
const flyoutBadge = (page) => page.locator('.vbh-navright .vbh-tasks-count')
/** Das aufgeklappte Flyout (steht außerhalb der Tab-Abschnitte, am body). */
const flyout = (page) => page.getByRole('dialog', { name: 'Aufgaben', exact: true })

async function openFlyout(page) {
	await flyoutButton(page).click()
	await expect(flyout(page)).toBeVisible()
	return flyout(page)
}

test.describe('Aufgaben-Flyout', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Badge zeigt die Zahl der Aufgaben mit Handlungsbedarf, Hinweise zählen nicht mit', async ({ page, request }) => {
		await ensureSeed(request)
		const tasks = await serverTasks(request)
		// Beide Fälle sind da, und es gibt wirklich einen Hinweis - sonst bewiese
		// die Zahl unten nicht, dass Hinweise nicht mitzählen.
		expect(tasks.action.some((t) => t.message.includes(name(TESSA)))).toBe(true)
		expect(tasks.hints.some((t) => t.message.includes(name(THEO)))).toBe(true)

		await openApp(page, USERS.buchhalter)

		await expect(flyoutBadge(page)).toHaveText(String(tasks.action.length))
		await expect(flyoutButton(page)).toHaveAccessibleName(new RegExp(`${tasks.action.length} mit Handlungsbedarf`))
		expect(tasks.all.length).toBeGreaterThan(tasks.action.length)
	})

	test('Flyout öffnet und zeigt Handlungsbedarf und Hinweise getrennt in deutschem Klartext', async ({ page, request }) => {
		await ensureSeed(request)
		const tasks = await serverTasks(request)
		await openApp(page, USERS.buchhalter)

		const dialog = await openFlyout(page)
		await expect(dialog.getByRole('heading', { name: 'Handlungsbedarf' })).toBeVisible()
		await expect(dialog.getByRole('heading', { name: 'Hinweise' })).toBeVisible()
		await expect(dialog.locator('.vbh-tasks-item--action')).toHaveCount(tasks.action.length)
		await expect(dialog.locator('.vbh-tasks-item--hint')).toHaveCount(tasks.hints.length)

		const tessa = dialog.locator('.vbh-tasks-item--action', { hasText: name(TESSA) })
		await expect(tessa).toContainText('hat eine Zuweisung mit Lastschrift, aber kein einzugsfähiges Mandat')
		await expect(tessa).toContainText('Zuweisung')

		const theo = dialog.locator('.vbh-tasks-item--hint', { hasText: name(THEO) })
		await expect(theo).toContainText('wartet seit')
		await expect(theo).toContainText('auf Zustimmung')

		// Kein Quittieren: nur Aktualisieren und Sprungknöpfe, nichts zum Wegklicken.
		await expect(dialog.getByRole('button', { name: /erledigt|quittier|ausblenden|verwerfen/i })).toHaveCount(0)

		// Escape schließt das Flyout wieder.
		await page.keyboard.press('Escape')
		await expect(dialog).toBeHidden()
	})

	test('Escape schließt das Flyout auch dann, wenn die Liste beim Öffnen noch lädt', async ({ page }) => {
		// Regression (CI-Flake von Spec 38): Hängt die Abfrage, steht im Flyout nur der
		// Ladekreis - nichts Fokussierbares. Der Fokusfang des Popovers bricht dann
		// ab, der Fokus bleibt am Auslöser, und das Popover schließt nur auf Escape
		// *im* Popover: Escape lief ins Leere. Die Verzögerung stellt den Zustand
		// her, statt auf eine langsame CI zu hoffen.
		await page.route('**/apps/vereinsbuchhaltung/api/tasks', async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 4000))
			await route.continue().catch(() => {}) // Seite ist inzwischen zu
		})
		await openApp(page, USERS.buchhalter)

		const dialog = await openFlyout(page)
		await expect(dialog.getByRole('status')).toBeVisible() // lädt wirklich noch
		await page.keyboard.press('Escape')
		await expect(dialog).toBeHidden()
	})

	test('Escape schließt das Flyout auch im allerersten Augenblick, solange der Fokus noch am Auslöser liegt', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await expect(flyoutButton(page)).toBeVisible()

		// Escape in dem Moment, in dem der Dialog ins DOM kommt: der Fokusfang des
		// Popovers zieht erst einen Frame später nach. Wer genau da Escape drückt
		// (oder dessen Fokus später verloren geht), muss das Flyout trotzdem zu
		// bekommen. Per MutationObserver statt per Zeitraten, damit es deterministisch ist.
		await page.evaluate(() => {
			const observer = new MutationObserver(() => {
				if (!document.querySelector('[role="dialog"][aria-label="Aufgaben"]')) { return }
				observer.disconnect()
				const target = document.activeElement ?? document.body
				for (const type of ['keydown', 'keyup']) {
					target.dispatchEvent(new KeyboardEvent(type, { key: 'Escape', bubbles: true, cancelable: true }))
				}
				window.__escapeSent = true
			})
			observer.observe(document.body, { childList: true, subtree: true })
		})
		await flyoutButton(page).click()
		await page.waitForFunction(() => window.__escapeSent === true)
		await expect(flyout(page)).toBeHidden()

		// Danach ist alles wieder bedienbar.
		await flyoutButton(page).click()
		await expect(flyout(page)).toBeVisible()
	})

	test('Eintrag springt in die Mitglieder-Akte', async ({ page, request }) => {
		await ensureSeed(request)
		await openApp(page, USERS.buchhalter)

		const dialog = await openFlyout(page)
		await dialog.locator('.vbh-tasks-item', { hasText: name(TESSA) }).getByRole('button', { name: 'Zur Akte' }).click()

		await expect(dialog).toBeHidden()
		await expect(tabButton(page, 'Beiträge')).toHaveClass(/active/)
		// Die Akte des Mitglieds, um das es in der Aufgabe geht - nicht irgendeine.
		await expect(page.getByRole('heading', { name: new RegExp(name(TESSA)) })).toBeVisible()
	})

	test('Hinweis aus einem Mandat springt ebenfalls in die Akte des Mitglieds', async ({ page, request }) => {
		await ensureSeed(request)
		await openApp(page, USERS.buchhalter)

		const dialog = await openFlyout(page)
		await dialog.locator('.vbh-tasks-item--hint', { hasText: name(THEO) }).getByRole('button', { name: 'Zur Akte' }).click()

		await expect(page.getByRole('heading', { name: new RegExp(name(THEO)) })).toBeVisible()
	})

	test('eine Aufgabe verschwindet von selbst, sobald ihre Ursache behoben ist', async ({ page, request }) => {
		// Eigenes, frisches Mitglied je Lauf: nach der Behebung hat es ein Mandat und
		// wäre beim nächsten Lauf (beforeAll läuft nach Fehlschlägen erneut) keine
		// Aufgabe mehr - ein fester Name machte diesen Test vom Vorlauf abhängig.
		const suffix = Math.random().toString(36).slice(2, 8)
		const member = { firstName: 'Rudi', lastName: `Behoben${suffix}` }
		const group = await ensureGroup(request)
		const created = await ensureMember(request, member)
		await ensureDirectDebitAssignment(request, created, group)

		await openApp(page, USERS.buchhalter)
		const before = (await serverTasks(request)).action.length
		await expect(flyoutBadge(page)).toHaveText(String(before))

		const dialog = await openFlyout(page)
		const item = dialog.locator('.vbh-tasks-item--action', { hasText: name(member) })
		await expect(item).toBeVisible()

		// Ursache beheben: ein aktives Papier-Mandat (über die API, nicht in der Oberfläche).
		const mandate = await (await api.createMandate(request, { memberId: created.id, iban: IBAN, accountHolder: name(member), signedAt: '2026-01-15' })).json()
		await api.activateMandate(request, mandate.id)

		await dialog.getByRole('button', { name: 'Aufgaben aktualisieren' }).click()
		await expect(item).toHaveCount(0)
		await expect(flyoutBadge(page)).toHaveText(String(before - 1))
	})

	test('Leerzustand: ohne Aufgaben steht „Nichts zu tun" im Flyout und kein Badge am Knopf', async ({ page }) => {
		// Die Antwort wird abgefangen, statt den Bestand leerzuräumen: andere Specs
		// lassen Mitglieder zurück, die sich nicht sauber löschen lassen.
		await page.route('**/apps/vereinsbuchhaltung/api/tasks', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }))
		await openApp(page, USERS.buchhalter)

		await expect(flyoutButton(page)).toBeVisible()
		await expect(flyoutBadge(page)).toHaveCount(0)
		const dialog = await openFlyout(page)
		await expect(dialog.getByText('Nichts zu tun')).toBeVisible()
	})

	test('Ladefehler: Fehlermeldung mit „Erneut versuchen", danach erscheint die Liste', async ({ page, request }) => {
		await ensureSeed(request)
		let fail = true
		await page.route('**/apps/vereinsbuchhaltung/api/tasks', (route) => {
			if (fail) { return route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"kaputt"}' }) }
			return route.continue()
		})
		await openApp(page, USERS.buchhalter)

		const dialog = await openFlyout(page)
		await expect(dialog.getByText('Aufgaben konnten nicht geladen werden')).toBeVisible()
		await expect(flyoutBadge(page)).toHaveCount(0)

		fail = false
		await dialog.getByRole('button', { name: 'Erneut versuchen' }).click()
		await expect(dialog.locator('.vbh-tasks-item', { hasText: name(TESSA) })).toBeVisible()
		await expect(dialog.getByText('Aufgaben konnten nicht geladen werden')).toHaveCount(0)
	})

	test('sichtbar ab Buchhalter: auch der Verwalter sieht das Flyout', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await expect(flyoutButton(page)).toBeVisible()
	})

	test('Revisor sieht das Flyout nicht, und die API sperrt ihn', async ({ page, request }) => {
		await openApp(page, USERS.revisor)
		await expect(tabButton(page, 'Berichte')).toBeVisible() // die App ist geladen
		await expect(flyoutButton(page)).toHaveCount(0)

		const response = await api.raw(request, 'GET', '/tasks', { user: USERS.revisor })
		expect(response.status()).toBe(403)
	})
})

test.describe('Aufgaben-Flyout auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Knopf in der Kopfzeile, Flyout passt in die Breite und der Sprung führt in die Akte', async ({ page, request }) => {
		await ensureSeed(request)
		const tasks = await serverTasks(request)
		await openApp(page, USERS.buchhalter)

		await expect(flyoutBadge(page)).toHaveText(String(tasks.action.length))
		const dialog = await openFlyout(page)

		const box = await dialog.boundingBox()
		expect(box.x).toBeGreaterThanOrEqual(0)
		expect(box.x + box.width).toBeLessThanOrEqual(375)
		expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375)

		await dialog.locator('.vbh-tasks-item', { hasText: name(TESSA) }).getByRole('button', { name: 'Zur Akte' }).click()
		await expect(dialog).toBeHidden()
		await expect(page.getByRole('heading', { name: new RegExp(name(TESSA)) })).toBeVisible()
	})
})
