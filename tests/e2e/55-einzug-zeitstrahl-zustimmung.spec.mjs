import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'
import { clearCapturedMails, startMailCapture, stopMailCapture, waitForMailsTo } from './fixtures/mail-capture.mjs'

// Oberflächen-Politur rund um Einzug, Klemmbrett und Zustimmungsseite, die der
// Durchlauf des Testprotokolls ergeben hat:
//
// - Zeitstrahl: Hilfetext unter dem Strahl (die Kreise sind anklickbar, sieht man
//   ihnen nicht an) und ein Info-Knopf mit Erklärung an jeder der vier Phasen.
// - Lauf-Detail ohne Dubletten: ein freigegebener Lauf nennt Schritt 2 genau einmal,
//   und eine Abweichung der Mandatsdaten steht samt Erklärung und Ausweg in EINEM Kasten.
// - Ein verschobener und dann verworfener Lauf hinterlässt keinen leeren Termin auf dem
//   Zeitstrahl (Backend-Regel in DebitTimelineService::datesBetween): er bleibt nur als
//   „verworfen“ in der Läufe-Liste.
// - Klemmbrett (Aufgaben-Flyout): gleichartige Meldungen stehen ab drei als eine
//   aufklappbare Zeile mit Zähler.
// - Öffentliche Zustimmungsseite: IBAN in Vierergruppen, deutsche Daten, und die lange
//   Beschriftung „Gläubiger-Identifikationsnummer“ überlagert ihren Wert nicht.
// - Vorabinfo-Mail mit deutschen Daten.
//
// Der Bestand ist NICHT leer: `api.resetBook()` räumt Buchungen, Konten, offene Posten
// (Forderungen) und alles, was daran hängt (Läufe, Posten, Rücklastschriften,
// Mahnstufen), nicht aber Mitglieder, Mandate, Zuweisungen und die App-Config früherer
// Specs. Die Tests legen deshalb ihre eigenen, eindeutig benannten Mitglieder an
// (find-or-create), seeden ihre Forderungen im Test selbst und nutzen Einzugstermine
// relativ zu heute (der Server rechnet mit dem echten Kalendertag, es gibt keine
// Zeitreise). Die Termine meiden den 1. und den 15. eines Monats: dort liegen die
// Termine des Terminplans, die frühere Specs über ihre Zuweisungen auf dem Strahl
// hinterlassen – ein verschobener Termin dürfte sonst zufällig mit einem Plantermin
// zusammenfallen und nach dem Verwerfen stehen bleiben.
//
// Die Zustimmungsseite verlangt einen versandten Einmal-Link, die Vorabinfo eine
// Mail zum Lesen: beide laufen unter dem Mail-Abgriff aus fixtures/mail-capture.mjs
// (siehe README, „Mails prüfen“). Das Abfangen steht in config.php, nicht im
// Datenbank-Snapshot: `afterAll` räumt es wieder weg.

const IBAN = 'DE02120300000000202051'
const IBAN_NEW = 'DE44500105175407324931'
const CLUB_NAME = 'Testverein e.V.'
const CREDITOR_ID = 'DE98ZZZ09999999999'
const GERMAN_DATE = /^\d{2}\.\d{2}\.\d{4}$/

const TIMELINE_HINT = 'Jeder Kreis ist ein Einzugstermin – anklicken, um darunter seine Phasen und die Vorschau zu sehen.'
const STEP_TWO_HINT = 'Schritt 2 von 2: Die Datei ist erzeugt, bei der Bank liegt sie noch nicht.'

const iso = (date) => date.toISOString().slice(0, 10)
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const germanDate = (isoDate) => isoDate.split('-').reverse().join('.')
const currentYear = () => new Date().getUTCFullYear()
const unique = () => Date.now().toString(36)

/** Frühestens `days` Tage ab heute, aber nie auf einem Tag, an dem der Terminplan einen Einzug ansetzt (1. und 15.). */
function planOffset(days) {
	let offset = days
	while ([1, 15].includes(Number(plusDays(offset).slice(8, 10)))) { offset++ }
	return offset
}

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: CLUB_NAME,
		sepa_creditor_id: CREDITOR_ID,
		sepa_debtor_account_id: bank.id,
	})
}

async function ensureMember(request, firstName, lastName) {
	const displayName = `${firstName} ${lastName}`
	const existing = (await api.listMembers(request)).find((m) => m.displayName === displayName)
	return existing ?? api.createMember(request, { firstName, lastName, email: `${firstName}.${lastName}@example.org`.toLowerCase() })
}

/** Mitglied mit aktivem Mandat (find-or-create); liefert das Mitglied und sein aktives Mandat. */
async function ensureMemberWithMandate(request, firstName, lastName, iban = IBAN) {
	const member = await ensureMember(request, firstName, lastName)
	let mandate = (await api.mandatesByMember(request, member.id)).find((m) => m.status === 'aktiv')
	if (!mandate) {
		mandate = await (await api.createMandate(request, { memberId: member.id, iban, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
	}
	return { member, mandate }
}

async function createClaim(request, memberId, dueDate, { amount = 12.5, label = 'Testbeitrag Zeitstrahl' } = {}) {
	const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type: 'beitrag', amount, label, dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

/** Freigeben über die API (für Tests, in denen die Freigabe nur Vorbedingung ist). */
async function releaseRun(request, dueDate) {
	const resp = await api.raw(request, 'POST', '/debit-batches', { data: { dueDate } })
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

/** Öffnet Beiträge → Einzug (als Buchhalter) und wartet, bis der Zeitstrahl geladen ist. */
async function openEinzug(page) {
	await openApp(page, USERS.buchhalter)
	await switchTab(page, 'Beiträge')
	await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
	await expect(visibleSection(page).getByRole('tab', { name: 'Zeitstrahl & Läufe' })).toBeVisible()
	await expect(visibleSection(page).getByRole('heading', { name: /^Beitragsjahr / })).toBeVisible()
}

function marker(page, dueDate) {
	return visibleSection(page).getByRole('button', { name: new RegExp(`^Einzugstermin ${germanDate(dueDate).replaceAll('.', '\\.')}`) })
}

function ghostCard(page, dueDate) {
	return visibleSection(page).getByRole('region', { name: `Vorschau des Einzugs am ${germanDate(dueDate)}` })
}

/**
 * Zeigt das Beitragsjahr, in das der Termin fällt (nur um den Jahreswechsel nötig). Der Zustand steht am
 * Knopf „Zum laufenden Jahr“, der nur in einem fremden Jahr da ist – so lässt sich der Aufruf beliebig
 * oft wiederholen, auch für die Prüfung, dass ein Marker FEHLT: erst wenn das fremde Jahr geladen ist,
 * sagt „kein Marker“ etwas aus.
 */
async function showYearOf(page, dueDate) {
	const section = visibleSection(page)
	const backToCurrent = section.getByRole('button', { name: 'Zum laufenden Jahr', exact: true })
	const inNextYear = Number(dueDate.slice(0, 4)) > currentYear()
	const onOtherYear = await backToCurrent.isVisible()
	if (inNextYear && !onOtherYear) {
		await section.getByRole('button', { name: 'Nächstes Beitragsjahr', exact: true }).click()
		await expect(backToCurrent).toBeVisible()
	} else if (!inNextYear && onOtherYear) {
		await backToCurrent.click()
		await expect(backToCurrent).toBeHidden()
	}
}

/** Wählt den Termin auf dem Zeitstrahl (per dispatchEvent: nahe Marker überdecken sich, siehe 41-einzug-reiter). */
async function selectDate(page, dueDate) {
	await showYearOf(page, dueDate)
	await marker(page, dueDate).dispatchEvent('click')
	await expect(marker(page, dueDate)).toHaveAttribute('aria-pressed', 'true')
}

/** Klappt einen per API freigegebenen Lauf in der Läufe-Liste auf. */
async function expandRun(page, batchId) {
	await visibleSection(page).locator(`button[aria-controls="vbh-run-detail-${batchId}"]`).click()
	const detail = visibleSection(page).locator('.vbh-run-detailcell')
	await expect(detail).toBeVisible()
	return detail
}

/** Die Zeile des aufgeklappten Laufs in der Läufe-Liste (Termin und Status stehen nur dort, nicht im Detail). */
function openRunRow(page) {
	return visibleSection(page).locator('tr.vbh-run-open')
}

test.describe('Einzug: Zeitstrahl, Lauf-Detail und Klemmbrett', () => {
	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('der Hilfetext unter dem Strahl erklärt, dass die Kreise anklickbar sind – nur wenn es Termine gibt', async ({ page, request }) => {
		const dueDate = plusDays(10)
		const { member } = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Hilfetext')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Zeitstrahl-Hilfetext' })

		await openEinzug(page)
		const section = visibleSection(page)
		await selectDate(page, dueDate)

		const hint = section.locator('.vbh-tl-hint')
		await expect(hint).toBeVisible()
		await expect(hint).toHaveText(TIMELINE_HINT)

		// Der Hilfetext steht UNTER dem Strahl und über den Phasen des gewählten Termins.
		const track = await section.locator('.vbh-tl-scroll').boundingBox()
		const hintBox = await hint.boundingBox()
		const phases = await section.locator('.vbh-tl-phases').boundingBox()
		expect(hintBox.y).toBeGreaterThanOrEqual(track.y + track.height - 1)
		expect(phases.y).toBeGreaterThanOrEqual(hintBox.y + hintBox.height - 1)

		// Ein Beitragsjahr ohne Termine zeigt nur den Leerzustand, keinen Hilfetext zu Kreisen, die es nicht gibt.
		// (Der Server liefert für ein fremdes Jahr je nach Bestand Termine; deshalb wird dessen Antwort geleert.)
		await page.route(/\/api\/debit-batches\/timeline(\?|$)/, async (route) => {
			const response = await route.fetch()
			const json = await response.json()
			await route.fulfill({ status: response.status(), json: { ...json, dates: [], next: null } })
		})
		await section.getByRole('button', { name: 'Vorheriges Beitragsjahr', exact: true }).click()
		await expect(section.getByText('In diesem Beitragsjahr gibt es noch keine Einzugstermine.')).toBeVisible()
		await expect(section.locator('.vbh-tl-hint')).toHaveCount(0)
	})

	test('jede der vier Phasen hat einen Info-Knopf: Klick öffnet die Erklärung, Escape schließt sie', async ({ page, request }) => {
		const dueDate = plusDays(11)
		const { member } = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Phaseninfo')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Zeitstrahl-Phaseninfo' })

		await openEinzug(page)
		const section = visibleSection(page)
		await selectDate(page, dueDate)

		// Die Erklärungen stehen in src/lib/debitRun.js (milestoneInfo): hier nur ein unverwechselbarer Satzanfang je Phase.
		const phases = [
			{ label: 'Vorwarnung', explanation: 'Frühwarnung: Die Forderungen für diesen Einzug entstehen' },
			{ label: 'Vorabinfo', explanation: 'Die App verschickt die Vorabinfo per E-Mail an alle Mitglieder mit Adresse' },
			{ label: 'Freigabe-Vorlauf', explanation: 'Jetzt sind Sie dran:' },
			{ label: 'Einzug', explanation: 'Die Bank belastet frühestens an diesem Tag die Konten der Mitglieder' },
		]
		const infoButtons = section.locator('.vbh-tl-milestone-item .vbh-infohint')
		await expect(infoButtons).toHaveCount(phases.length)

		for (const phase of phases) {
			const button = section.locator('.vbh-tl-milestone-item').getByRole('button', { name: `Was bedeutet „${phase.label}“?`, exact: true })
			await expect(button).toBeVisible()
			await button.click()

			// Die Erklärung hängt als Popover am body, nicht im Abschnitt; eine nach der anderen, daher je Phase eindeutig.
			const explanation = page.locator('.vbh-infohint-body', { hasText: phase.explanation })
			await expect(explanation).toBeVisible()
			await expect(explanation).toContainText(phase.label)
			await expect(explanation).toContainText('Was zu tun ist:')

			await page.keyboard.press('Escape')
			await expect(explanation).toBeHidden()
		}
	})

	test('Lauf-Detail ohne Dubletten: Schritt 2 steht einmal, die Abweichung samt Erklärung und Ausweg in einem Kasten', async ({ page, request }) => {
		test.setTimeout(60000)
		const plainOffset = planOffset(12)
		const driftOffset = planOffset(plainOffset + 2)
		const plainDate = plusDays(plainOffset)
		const driftDate = plusDays(driftOffset)

		const { member: plainMember } = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Ohnedublette')
		const { member: driftMember, mandate } = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Mitabweichung')
		await createClaim(request, plainMember.id, plainDate, { label: 'Beitrag Dublette ruhig' })
		await createClaim(request, driftMember.id, driftDate, { label: 'Beitrag Dublette Abweichung' })
		const plainRun = await releaseRun(request, plainDate)
		const driftRun = await releaseRun(request, driftDate)

		// Nach der Freigabe zieht das Mitglied um: eine neue IBAN am selben Mandat (Amendment) – nur beim zweiten Lauf.
		const oldIban = mandate.iban.replaceAll(/\s/g, '')
		const newIban = oldIban === IBAN ? IBAN_NEW : IBAN
		const amend = await api.raw(request, 'POST', `/mandates/${mandate.id}/amend-bank-details`, { data: { iban: newIban } })
		expect(amend.ok()).toBeTruthy()

		await openEinzug(page)

		// Ohne Abweichung: genau ein Hinweis zum Zustand, und zwar der von Schritt 2.
		const plain = await expandRun(page, plainRun.id)
		await expect(plain).toContainText('Zeitstrahl Ohnedublette') // die Posten sind geladen
		await expect(plain.getByText(STEP_TWO_HINT)).toHaveCount(1)
		// Der alte Satz für Lesende (DebitRunDetail) wiederholte denselben Zustand; wer schreiben darf, liest ihn nur einmal.
		await expect(plain.getByText('Die Datei ist erzeugt, bei der Bank liegt sie noch nicht.')).toHaveCount(1)
		await expect(plain).not.toContainText('Freigegeben, aber noch nicht eingereicht')
		await expect(plain.locator('.vbh-hint--info')).toHaveCount(1)
		await expect(plain.locator('.vbh-run-drift')).toHaveCount(0)

		// Mit Abweichung: Meldung, Erklärung und Ausweg bilden EINEN Kasten, Schritt 2 bleibt der einzige Zustandshinweis.
		const drifting = await expandRun(page, driftRun.id)
		await expect(drifting).toContainText('Zeitstrahl Mitabweichung')
		const drift = drifting.locator('.vbh-run-drift')
		await expect(drift).toHaveCount(1)
		await expect(drift).toContainText('1 Posten weicht von den aktuellen Mandatsdaten ab')
		await expect(drift).toContainText('Die Datei enthält noch die Daten vom Tag der Freigabe')
		await expect(drift.getByRole('button', { name: 'Verwerfen und neu freigeben', exact: true })).toBeVisible()
		await expect(drifting.getByText('weicht von den aktuellen Mandatsdaten ab')).toHaveCount(1)
		await expect(drifting.getByText(STEP_TWO_HINT)).toHaveCount(1)
		await expect(drifting).not.toContainText('Freigegeben, aber noch nicht eingereicht')
		await expect(drifting.locator('.vbh-hint--info')).toHaveCount(1)
	})

	test('verschoben und dann verworfen: der verschobene Termin ist vom Zeitstrahl verschwunden, der Lauf bleibt als verworfen', async ({ page, request }) => {
		test.setTimeout(60000)
		const dueOffset = planOffset(24)
		const movedOffset = planOffset(dueOffset + 7)
		const dueDate = plusDays(dueOffset)
		const movedDate = plusDays(movedOffset)
		const { member } = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Verschoben')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Zeitstrahl verschoben' })
		const batch = await releaseRun(request, dueDate)

		await openEinzug(page)
		const section = visibleSection(page)
		const detail = await expandRun(page, batch.id)

		// Den Lauf auf einen späteren Tag legen: dort steht jetzt ein Termin auf dem Zeitstrahl (ein lebender Lauf hängt daran).
		await detail.getByRole('button', { name: 'Termin verschieben', exact: true }).click()
		const reschedule = page.getByRole('dialog', { name: 'Einzugstermin verschieben' })
		await expect(reschedule).toBeVisible()
		await reschedule.getByLabel('Neuer Einzugstermin').fill(movedDate)
		await reschedule.getByRole('button', { name: 'Termin verschieben', exact: true }).click()
		await expect(reschedule).toBeHidden()
		await expect(openRunRow(page)).toContainText(germanDate(movedDate))
		await showYearOf(page, movedDate)
		await expect(marker(page, movedDate)).toBeVisible()
		await expect(marker(page, movedDate)).toHaveAttribute('aria-label', /Lauf freigegeben/)

		// Verwerfen: die Forderung ist wieder frei, aber nur der Planungstermin zeigt noch etwas an.
		await detail.getByRole('button', { name: 'Lauf verwerfen', exact: true }).click()
		const discard = page.getByRole('dialog', { name: 'Lauf verwerfen', exact: true })
		await expect(discard).toBeVisible()
		await discard.getByLabel('Begründung (Pflicht)').fill('Termin war falsch gewählt')
		await discard.getByRole('button', { name: 'Lauf verwerfen', exact: true }).click()
		await expect(discard).toBeHidden()

		// Der Lauf bleibt als Historie in der Läufe-Liste – unter seinem verschobenen Datum. Dass die Zeile da ist, heißt auch:
		// Zeitstrahl und Liste sind neu geladen (beide kommen aus derselben Abfrage), die folgenden Prüfungen sehen den neuen Stand.
		const movedRe = germanDate(movedDate).replaceAll('.', '\\.')
		await expect(section.getByRole('row', { name: new RegExp(`^${movedRe} verworfen`) })).toHaveCount(1)
		await expect(detail).toContainText('Termin war falsch gewählt')

		await showYearOf(page, movedDate)
		await expect(marker(page, movedDate)).toHaveCount(0)

		// Der Planungstermin dagegen steht wieder als Vorschau da – mit der freigewordenen Forderung.
		await selectDate(page, dueDate)
		await expect(marker(page, dueDate)).toHaveAttribute('aria-label', /Vorschau/)
		await expect(ghostCard(page, dueDate)).toContainText('Zeitstrahl Verschoben')
	})

	test('Klemmbrett: gleichartige Aufgaben stehen ab drei als eine aufklappbare Zeile mit Zähler', async ({ page, request }) => {
		test.setTimeout(60000)
		// „Mandat ohne Nachweis“ (Hinweis) trägt eine Typkennung und entsteht ohne Tageslauf: ein aktives Papier-Mandat ohne Beleg genügt.
		// (Die Vorabinfo-Störfälle bräuchten Forderungen im Vorabinfo-Fenster, und ein Tageslauf zwischendurch räumte sie ab.)
		const names = ['Gruppiert Eins', 'Gruppiert Zwei', 'Gruppiert Drei']
		for (const name of names) {
			const [firstName, lastName] = name.split(' ')
			await ensureMemberWithMandate(request, firstName, lastName)
		}
		// Der Dauer-Hinweis ist abschaltbar und gehört zur App-Config, die resetBook() nicht räumt.
		const before = (await api.getSettings(request)).show_missing_document_warning
		await api.updateSettings(request, { show_missing_document_warning: '1' })

		try {
			const tasks = (await api.getJson(request, '/tasks', { user: USERS.buchhalter }))
			const action = tasks.filter((t) => t.severity === 'handlungsbedarf')
			const hints = tasks.filter((t) => t.severity === 'hinweis')
			const withoutProof = hints.filter((t) => t.kind === 'mandate_without_proof')
			expect(withoutProof.length).toBeGreaterThanOrEqual(3)
			for (const name of names) {
				expect(withoutProof.some((t) => t.message.startsWith(`${name}:`)), `Hinweis zu ${name}`).toBe(true)
			}

			await openApp(page, USERS.buchhalter)
			await page.locator('.vbh-navright').getByRole('button', { name: /^Aufgaben/ }).click()
			const dialog = page.getByRole('dialog', { name: 'Aufgaben', exact: true })
			await expect(dialog).toBeVisible()

			// EINE Zeile für alle, mit der Zahl der Betroffenen – nicht eine Zeile je Mitglied.
			const grouped = dialog.locator('.vbh-tasks-item--hint.vbh-tasks-item--grouped', { hasText: 'Mandat ohne Nachweis' })
			await expect(grouped).toHaveCount(1)
			await expect(grouped.locator('.vbh-tasks-summary-row .vbh-badge')).toHaveText(String(withoutProof.length))

			// Gezählt wird, was darin steht: nichts geht beim Zusammenfassen verloren.
			const leaves = (severity) => dialog.locator(`.vbh-tasks-item--${severity}:not(.vbh-tasks-item--grouped), .vbh-tasks-item--${severity} .vbh-tasks-subitem`)
			await expect(leaves('hint')).toHaveCount(hints.length)
			await expect(leaves('action')).toHaveCount(action.length)

			// Zugeklappt steht nur die eine Zeile da, aufgeklappt jede Einzelmeldung mit Sprungknopf.
			const details = grouped.locator('details')
			const subitems = grouped.locator('.vbh-tasks-subitem')
			await expect(subitems).toHaveCount(withoutProof.length)
			await expect(details).not.toHaveAttribute('open')
			await grouped.locator('summary').click()
			await expect(details).toHaveAttribute('open')
			await expect(subitems.first()).toBeVisible()
			for (const name of names) {
				const subitem = subitems.filter({ hasText: name })
				await expect(subitem).toHaveCount(1)
				await expect(subitem).toContainText('kein Nachweis hinterlegt')
				await expect(subitem.getByRole('button', { name: 'Zur Akte', exact: true })).toBeVisible()
			}
			const first = subitems.filter({ hasText: names[0] })

			// Der Sprung führt in die Akte des betroffenen Mitglieds.
			await first.getByRole('button', { name: 'Zur Akte', exact: true }).click()
			await expect(dialog).toBeHidden()
			await expect(page.getByRole('heading', { name: new RegExp(names[0]) })).toBeVisible()
		} finally {
			await api.updateSettings(request, { show_missing_document_warning: before ? '1' : '0' })
		}
	})
})

test.describe('Einzug-Unterreiter auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('der Hilfetext zu den Kreisen entfällt: auf dem Handy sind die Termine Zeilen mit Knopf', async ({ page, request }) => {
		const { member } = await ensureMemberWithMandate(request, 'Zeitstrahl', 'Handyhilfe')
		await createClaim(request, member.id, plusDays(10), { label: 'Beitrag Zeitstrahl Handy-Hilfetext' })

		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()
		await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()

		const section = visibleSection(page)
		await expect(section.locator('.vbh-tl-list')).toBeVisible()
		await expect(section.locator('.vbh-tl-hint')).toHaveCount(0)
	})
})

test.describe('Zustimmungsseite und Vorabinfo-Mail', () => {
	test.beforeAll(async () => {
		test.setTimeout(60000)
		await startMailCapture()
	})

	test.afterAll(async () => {
		await stopMailCapture()
	})

	test.beforeEach(async ({ request }) => {
		await clearCapturedMails()
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('Zustimmungsseite: IBAN in Vierergruppen, Datum als TT.MM.JJJJ, keine überlagerte Beschriftung', async ({ page, request }) => {
		test.setTimeout(60000)
		// Ein frisches Mitglied je Lauf: die Zustimmung verbraucht das Mandat, ein zweites lebendes gibt es nicht.
		const tag = unique()
		const member = await api.createMember(request, { firstName: 'Zustimmung', lastName: `Datenblock${tag}`, email: `zustimmung.${tag}@example.org` })
		const created = await api.createElectronicMandate(request, { memberId: member.id, iban: IBAN, accountHolder: 'Zustimmung Datenblock' })
		expect(created.status()).toBe(201)
		const mandate = await created.json()
		const { activationUrl } = await (await api.sendActivationLink(request, mandate.id)).json()

		// Öffentlich, ohne Anmeldung: `page` ist ein frischer, nicht angemeldeter Browser-Kontext (wie in 27).
		await page.goto(activationUrl)
		const data = page.locator('dl.vbh-mandate-data')
		await expect(data).toBeVisible()
		const valueOf = (label) => data.locator('dt', { hasText: new RegExp(`^${label}$`) }).locator('xpath=following-sibling::dd[1]')

		await expect(valueOf('IBAN')).toHaveText('DE02 1203 0000 0000 2020 51')
		await expect(valueOf('Gläubiger-Identifikationsnummer')).toHaveText(CREDITOR_ID)
		await expect(valueOf('Datum')).toHaveText(GERMAN_DATE)

		// Keine Beschriftung überlagert ihren Wert (die Nextcloud-Grundformatierung von dt/dd schob sie ineinander).
		const labels = data.locator('dt')
		const values = data.locator('dd')
		const rows = await labels.count()
		expect(rows).toBeGreaterThanOrEqual(6)
		expect(await values.count()).toBe(rows)
		for (let i = 0; i < rows; i++) {
			const label = await labels.nth(i).boundingBox()
			const value = await values.nth(i).boundingBox()
			const name = await labels.nth(i).innerText()
			const overlaps = label.x < value.x + value.width - 1 && value.x < label.x + label.width - 1
				&& label.y < value.y + value.height - 1 && value.y < label.y + label.height - 1
			expect(overlaps, `„${name}“ überlagert ihren Wert`).toBe(false)
		}
		// Die lange Beschriftung ausdrücklich: ihr Wert beginnt rechts von ihr.
		const creditorLabel = await data.locator('dt', { hasText: 'Gläubiger-Identifikationsnummer' }).boundingBox()
		const creditorValue = await valueOf('Gläubiger-Identifikationsnummer').boundingBox()
		expect(creditorValue.x).toBeGreaterThanOrEqual(creditorLabel.x + creditorLabel.width - 1)

		// Nach der Zustimmung steht im Kasten „Bereits bestätigt“ ein deutsches Datum.
		await page.getByRole('button', { name: 'Ich stimme zu und erteile das Mandat' }).click()
		const confirmed = page.locator('.vbh-status-box.vbh-success')
		await expect(confirmed).toContainText('Bereits bestätigt')
		await expect(confirmed).toContainText(/\(am \d{2}\.\d{2}\.\d{4}\)/)
		await expect(confirmed).not.toContainText(/\d{4}-\d{2}-\d{2}/)
		await expect(valueOf('Datum')).toHaveText(GERMAN_DATE)
	})

	test('Vorabinfo-Mail nennt Fälligkeit und frühesten Einzug als TT.MM.JJJJ', async ({ request }) => {
		test.setTimeout(90000)
		// Fällig in drei Tagen: innerhalb der Vorabinfo-Frist (14 Tage), die der Tageslauf bedient. Eine manuelle Forderung
		// trägt keinen Zeitraum – die Position lautet „Bezeichnung: Betrag, fällig TT.MM.JJJJ“. (Die Zeitraum-Fassung
		// „(TT.MM.JJJJ – TT.MM.JJJJ)“ gibt es nur bei einer Forderung aus dem Terminplan; sie entsteht erst im Tageslauf
		// mit einer Fälligkeit hinter der Frist und lässt sich ohne Zeitreise nicht am selben Tag versenden.)
		const dueDate = plusDays(3)
		// Die Adresse legt ensureMember() aus dem Namen an; die Mail wird darüber gefunden.
		const email = 'vorabinfo.deutsch@example.org'
		const { member } = await ensureMemberWithMandate(request, 'Vorabinfo', 'Deutsch')
		await createClaim(request, member.id, dueDate, { label: 'Testbeitrag Vorabinfo' })

		const container = getContainer()
		const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
		const job = JSON.parse(stdout).find((j) => (j.class || '').includes('ContributionDueCycleJob'))
		expect(job, 'ContributionDueCycleJob ist registriert').toBeTruthy()
		await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })

		const mails = await waitForMailsTo(email)
		expect(mails, 'gebündelt: eine Mail je Mitglied').toHaveLength(1)
		const [mail] = mails
		expect(mail.subject).toBe(`Bevorstehender Lastschrifteinzug von ${CLUB_NAME}`)
		expect(mail.text).toMatch(new RegExp(`Testbeitrag Vorabinfo: 12,50\\s€, fällig ${germanDate(dueDate).replaceAll('.', '\\.')}`))
		expect(mail.text).toContain(`Frühester Einzug: ${germanDate(dueDate)}`)
		// Keine Datenbank-Schreibweise in der Mail.
		expect(mail.text).not.toContain(dueDate)
	})
})
