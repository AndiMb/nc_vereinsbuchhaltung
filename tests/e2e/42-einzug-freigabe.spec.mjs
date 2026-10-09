import { test, expect } from '@playwright/test'
import { api, dav, openApp, switchTab, tabButton, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Einzug: Freigabe, Einreichung und Terminverschiebung (Issue #103, Spec
// §3.5/§6). Der Kassenwart führt den Lauf im Einzug-Unterreiter durch:
// Schritt 1 „Freigeben & Datei erzeugen“ (Bestätigungsdialog), XML
// herunterladen, Schritt 2 „Datei ist bei der Bank eingereicht“ – oder
// verwerfen (Pflicht-Begründung) und neu freigeben, den Termin nach hinten
// verschieben. Die Oberfläche selbst ist Gegenstand dieser Spec; die
// Statusmaschine des Backends deckt 30-debit-batch-release über die API ab.
//
// Der Bestand ist NICHT leer: `api.resetBook()` räumt Buchungen, Konten, offene
// Posten (Forderungen) und alles, was daran hängt (Läufe, Posten,
// Rücklastschriften, Mahnstufen; Issue #123), nicht aber Mitglieder, Mandate,
// Zuweisungen und die App-Config früherer Specs. Die Tests
// legen deshalb ihre eigenen, eindeutig benannten Mitglieder an (find-or-create),
// seeden ihre Forderungen im Test selbst und nutzen je Test einen eigenen
// Einzugstermin relativ zu heute (der Server rechnet mit dem echten
// Kalendertag, es gibt keine Zeitreise). Der frisch freigegebene Lauf klappt
// sich in der Oberfläche von selbst auf – die Specs halten sich an diesen
// einen aufgeklappten Lauf (`.vbh-run-detailcell`), nicht an Zeilen, die ein
// früherer Lauf am selben Datum ebenfalls tragen könnte.
//
// Der XML-Download ist ein per Link geöffneter GET (neuer Tab). Er wird hier
// mit `page.request.get(href)` geprüft: das trägt die Sitzung, aber kein
// requesttoken – genau der Fall, an dem ein fehlendes #[NoCSRFRequired] als
// 412 auffiele.

const IBAN = 'DE02120300000000202051'
const IBAN_NEW = 'DE44500105175407324931'

const iso = (date) => date.toISOString().slice(0, 10)
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const germanDate = (isoDate) => isoDate.split('-').reverse().join('.')
const currentYear = () => new Date().getUTCFullYear()

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

async function createClaim(request, memberId, dueDate, { amount = 12.5, label = 'Testbeitrag Freigabe' } = {}) {
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

/** Der jüngste Lauf zu einem Termin. */
async function latestBatch(request, dueDate) {
	const batches = (await api.getJson(request, '/debit-batches')).filter((b) => b.dueDate === dueDate)
	return batches.reduce((a, b) => (b.id > a.id ? b : a))
}

async function endToEndIds(request, batchId) {
	return (await api.getJson(request, `/debit-batches/${batchId}`)).items.map((i) => i.endToEndId)
}

/** Öffnet Beiträge → Einzug (als Buchhalter). */
async function openEinzug(page) {
	await openApp(page, USERS.buchhalter)
	await switchTab(page, 'Beiträge')
	await visibleSection(page).locator('.vbh-subtabs').getByRole('button', { name: 'Einzug', exact: true }).click()
	await expect(visibleSection(page).getByRole('tab', { name: 'Zeitstrahl & Läufe' })).toBeVisible()
}

function marker(page, dueDate) {
	return visibleSection(page).getByRole('button', { name: new RegExp(`^Einzugstermin ${germanDate(dueDate).replaceAll('.', '\\.')}`) })
}

/**
 * Fällt der Termin ins Folgejahr (Ende Dezember), erst dorthin blättern – höchstens einmal:
 * steht der Marker schon auf dem Strahl, ist das Jahr bereits gewählt (ein Test ruft das mehrfach auf).
 */
async function showYearOf(page, dueDate) {
	if (Number(dueDate.slice(0, 4)) > currentYear() && await marker(page, dueDate).count() === 0) {
		await visibleSection(page).getByRole('button', { name: 'Nächstes Beitragsjahr' }).click()
	}
}

function ghostCard(page, dueDate) {
	return visibleSection(page).getByRole('region', { name: `Vorschau des Einzugs am ${germanDate(dueDate)}` })
}

/** Wählt den Termin auf dem Zeitstrahl (per dispatchEvent: nahe Marker überdecken sich, siehe 41-einzug-reiter). */
async function selectDate(page, dueDate) {
	await showYearOf(page, dueDate)
	await marker(page, dueDate).dispatchEvent('click')
	await expect(marker(page, dueDate)).toHaveAttribute('aria-pressed', 'true')
}

/** Schritt 1 in der Oberfläche: Geisterkarte → Bestätigungsdialog → freigeben. Liefert das Detail des neuen (aufgeklappten) Laufs. */
async function releaseInUi(page, dueDate) {
	const ghost = ghostCard(page, dueDate)
	await ghost.getByRole('button', { name: 'Freigeben & Datei erzeugen' }).click()
	const dialog = page.getByRole('dialog', { name: `Einzug am ${germanDate(dueDate)} freigeben` })
	await expect(dialog).toBeVisible()
	await dialog.getByRole('button', { name: 'Freigeben & Datei erzeugen' }).click()
	await expect(dialog).toBeHidden()
	const detail = visibleSection(page).locator('.vbh-run-detailcell')
	await expect(detail).toBeVisible()
	return detail
}

/** Klappt einen per API freigegebenen Lauf in der Läufe-Liste auf. */
async function expandRun(page, batchId) {
	await visibleSection(page).locator(`button[aria-controls="vbh-run-detail-${batchId}"]`).click()
	const detail = visibleSection(page).locator('.vbh-run-detailcell')
	await expect(detail).toBeVisible()
	return detail
}

/**
 * Die Zeile des aufgeklappten Laufs in der Läufe-Liste: Termin und Status stehen nur dort, das Detail
 * darunter wiederholt sie nicht (UI-Politur: nichts doppelt).
 */
function openRunRow(page) {
	return visibleSection(page).locator('tr.vbh-run-open')
}

test.describe('Einzug: Freigabe, Einreichung, Verwerfen und Terminverschiebung', () => {
	test.beforeEach(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableModule(request)
	})

	test('freigeben, XML herunterladen und als eingereicht markieren', async ({ page, request }) => {
		const dueDate = plusDays(17)
		const { member } = await ensureMemberWithMandate(request, 'Freigabe', 'Komplett')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Freigabe komplett' })

		await openEinzug(page)
		const section = visibleSection(page)
		await selectDate(page, dueDate)
		const ghost = ghostCard(page, dueDate)
		await expect(ghost).toContainText('Freigabe Komplett')

		// Schritt 1: Bestätigungsdialog mit Termin, Anzahl, Summe, Störfällen – und der Warnung vor dem Unumkehrbaren.
		await ghost.getByRole('button', { name: 'Freigeben & Datei erzeugen' }).click()
		const dialog = page.getByRole('dialog', { name: `Einzug am ${germanDate(dueDate)} freigeben` })
		await expect(dialog).toBeVisible()
		await expect(dialog).toContainText(germanDate(dueDate))
		await expect(dialog.locator('.vbh-release-facts')).toContainText('Forderungen')
		await expect(dialog.locator('.vbh-release-facts')).toContainText('Störfälle')
		await expect(dialog).toContainText(/12,50\s*€/)
		await expect(dialog).toContainText('EndToEndId')
		await expect(dialog).toContainText('nie wiederverwendet')
		// Abbrechen legt nichts an.
		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(dialog).toBeHidden()
		expect((await api.getJson(request, '/debit-batches')).filter((b) => b.dueDate === dueDate)).toHaveLength(0)

		const detail = await releaseInUi(page, dueDate)
		await expect(openRunRow(page).getByText('freigegeben', { exact: true })).toBeVisible()
		await expect(detail).toContainText('Freigabe Komplett')
		const msgId = (await detail.locator('.vbh-rd-msgid').innerText()).trim()
		expect(msgId).not.toBe('')

		// Mehr als diesen einen Termin gibt es für die Forderung nicht: die Karte ist weg, der Lauf steht in der Liste.
		await expect(ghostCard(page, dueDate)).toHaveCount(0)
		await expect(marker(page, dueDate)).toHaveAttribute('aria-label', /Lauf freigegeben/)

		// XML herunterladen: ein Link in einem neuen Tab, der ohne requesttoken funktioniert und byte-identisch nachrendert.
		const xmlLink = detail.getByRole('link', { name: 'XML herunterladen' })
		await expect(xmlLink).toHaveAttribute('target', '_blank')
		const href = await xmlLink.getAttribute('href')
		const first = await page.request.get(href)
		expect(first.ok()).toBe(true)
		expect(first.headers()['content-disposition']).toContain('attachment')
		const xml = await first.text()
		expect(xml).toContain(msgId)
		expect(xml).toContain(IBAN)
		expect(xml).toContain(`<ReqdColltnDt>${dueDate}</ReqdColltnDt>`)
		expect(await (await page.request.get(href)).text()).toBe(xml)

		// Schritt 2: eine eigene, bewusste Aktion mit Rückfrage.
		await detail.getByRole('button', { name: 'Datei ist bei der Bank eingereicht' }).click()
		const confirm = page.getByRole('dialog', { name: 'Datei als eingereicht bestätigen' })
		await expect(confirm).toBeVisible()
		await expect(confirm).toContainText('keinen Storno mehr')
		// Zurückziehen ändert nichts.
		await confirm.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(confirm).toBeHidden()
		await expect(openRunRow(page).getByText('freigegeben', { exact: true })).toBeVisible()

		await detail.getByRole('button', { name: 'Datei ist bei der Bank eingereicht' }).click()
		await confirm.getByRole('button', { name: 'Ja, eingereicht' }).click()
		await expect(confirm).toBeHidden()
		await expect(openRunRow(page).getByText('eingereicht', { exact: true })).toBeVisible()

		// Danach kein Storno und keine Verschiebung mehr: nur der Download bleibt.
		await expect(detail.getByRole('button', { name: /verwerfen|verschieben|eingereicht/i })).toHaveCount(0)
		await expect(detail.getByRole('link', { name: 'XML herunterladen' })).toBeVisible()
		await expect(detail).toContainText('Einen Storno gibt es nicht mehr')
		await showYearOf(page, dueDate)
		await expect(marker(page, dueDate)).toHaveAttribute('aria-label', /Lauf eingereicht/)
		await expect(section.getByRole('button', { name: 'Freigeben & Datei erzeugen' })).toHaveCount(0)

		// Auch das Backend lässt nach der Einreichung nichts mehr zu.
		const batch = await latestBatch(request, dueDate)
		expect(batch.status).toBe('eingereicht')
		expect((await api.raw(request, 'POST', `/debit-batches/${batch.id}/discard`, { data: { reason: 'zu spät' } })).status()).toBe(400)
		expect((await api.raw(request, 'POST', `/debit-batches/${batch.id}/reschedule`, { data: { dueDate: plusDays(30) } })).status()).toBe(400)
	})

	test('verwerfen mit Pflicht-Begründung und neu freigeben: Historie bleibt, EndToEndIds nie wieder', async ({ page, request }) => {
		const dueDate = plusDays(18)
		const { member } = await ensureMemberWithMandate(request, 'Freigabe', 'Verwerfen')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Freigabe verwerfen' })

		await openEinzug(page)
		const section = visibleSection(page)
		await selectDate(page, dueDate)
		const detail = await releaseInUi(page, dueDate)
		const firstBatch = await latestBatch(request, dueDate)
		const firstIds = await endToEndIds(request, firstBatch.id)
		expect(firstIds).toHaveLength(1)

		// Verwerfen: ohne Begründung (auch nicht aus Leerzeichen) gesperrt.
		await detail.getByRole('button', { name: 'Lauf verwerfen' }).click()
		const dialog = page.getByRole('dialog', { name: 'Lauf verwerfen', exact: true })
		await expect(dialog).toBeVisible()
		const discard = dialog.getByRole('button', { name: 'Lauf verwerfen' })
		await expect(discard).toBeDisabled()
		const reason = dialog.getByLabel('Begründung (Pflicht)')
		await reason.fill('   ')
		await expect(discard).toBeDisabled()
		await reason.fill('Falsches Datum & Betrag gewählt')
		await expect(discard).toBeEnabled()
		await discard.click()
		await expect(dialog).toBeHidden()

		// Die Historie bleibt sichtbar (mit der Begründung, nicht HTML-escaped), der Lauf hat keine Aktionen mehr …
		await expect(detail).toContainText('Falsches Datum & Betrag gewählt')
		await expect(openRunRow(page).getByText('verworfen', { exact: true })).toBeVisible()
		await expect(detail.getByRole('button')).toHaveCount(0)
		await expect(detail.getByRole('link')).toHaveCount(0)
		// … und die Forderung ist wieder frei: die Geisterkarte des Termins bietet sie erneut an.
		const ghost = ghostCard(page, dueDate)
		await expect(ghost).toBeVisible()
		await expect(ghost).toContainText('Freigabe Verwerfen')

		// Neu freigeben: ein zweiter Lauf, der verworfene bleibt in der Liste.
		await releaseInUi(page, dueDate)
		await expect(openRunRow(page).getByText('freigegeben', { exact: true })).toBeVisible()
		const dateRe = germanDate(dueDate).replaceAll('.', '\\.')
		await expect(section.getByRole('row', { name: new RegExp(`^${dateRe} verworfen`) })).toHaveCount(1)
		await expect(section.getByRole('row', { name: new RegExp(`^${dateRe} freigegeben`) })).toHaveCount(1)

		const secondBatch = await latestBatch(request, dueDate)
		expect(secondBatch.id).not.toBe(firstBatch.id)
		const secondIds = await endToEndIds(request, secondBatch.id)
		expect(secondIds).toHaveLength(1)
		expect(secondIds.filter((id) => firstIds.includes(id))).toEqual([])
	})

	test('Termin lässt sich nur nach hinten verschieben, und die Datei trägt den neuen Termin', async ({ page, request }) => {
		const dueDate = plusDays(19)
		const newDate = plusDays(24)
		const { member } = await ensureMemberWithMandate(request, 'Freigabe', 'Verschieben')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Freigabe verschieben' })
		const batch = await releaseRun(request, dueDate)

		await openEinzug(page)
		const detail = await expandRun(page, batch.id)
		await detail.getByRole('button', { name: 'Termin verschieben' }).click()
		const dialog = page.getByRole('dialog', { name: 'Einzugstermin verschieben' })
		await expect(dialog).toBeVisible()

		const input = dialog.getByLabel('Neuer Einzugstermin')
		const confirm = dialog.getByRole('button', { name: 'Termin verschieben' })
		await expect(input).toHaveAttribute('min', plusDays(20))
		await expect(confirm).toBeDisabled()
		// Ein früheres (oder dasselbe) Datum geht gar nicht erst durch, auch nicht von Hand getippt.
		await input.fill(plusDays(10))
		await expect(confirm).toBeDisabled()
		await expect(dialog).toContainText('nur nach hinten')
		await input.fill(dueDate)
		await expect(confirm).toBeDisabled()
		await input.fill(newDate)
		await expect(confirm).toBeEnabled()
		await confirm.click()
		await expect(dialog).toBeHidden()

		await expect(openRunRow(page)).toContainText(germanDate(newDate))
		const moved = await api.getJson(request, `/debit-batches/${batch.id}`)
		expect(moved.dueDate).toBe(newDate)
		expect(moved.status).toBe('freigegeben')

		// Die neu heruntergeladene Datei trägt den neuen Termin; Kennung der Datei und EndToEndIds bleiben.
		const href = await detail.getByRole('link', { name: 'XML herunterladen' }).getAttribute('href')
		const xml = await (await page.request.get(href)).text()
		expect(xml).toContain(`<ReqdColltnDt>${newDate}</ReqdColltnDt>`)
		expect(xml).toContain(moved.msgId)
		expect(xml).toContain((await endToEndIds(request, batch.id))[0])

		// Das Backend lehnt einen früheren Termin ebenfalls ab.
		const earlier = await api.raw(request, 'POST', `/debit-batches/${batch.id}/reschedule`, { data: { dueDate: plusDays(5) } })
		expect(earlier.status()).toBe(400)
	})

	test('eine gerissene Vorlauffrist bleibt ein Hinweis: freigeben und einreichen gehen trotzdem', async ({ page, request }) => {
		// Fällig, bevor der Freigabe-Vorlauf (Einstellung, Standard 5 Tage) Zeit lässt.
		const lead = (await api.getJson(request, '/debit-batches/settings')).releaseLeadDays
		const offset = Math.max(lead - 1, 1)
		const dueDate = plusDays(offset)
		const releaseDate = plusDays(offset - lead)
		const { member } = await ensureMemberWithMandate(request, 'Freigabe', 'Verspaetet')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Freigabe verspätet' })

		await openEinzug(page)
		await selectDate(page, dueDate)
		const ghost = ghostCard(page, dueDate)
		await expect(ghost).toContainText(`Freigabe überfällig seit ${germanDate(releaseDate)}`)
		await expect(ghost).toContainText('Das blockiert nichts')

		const detail = await releaseInUi(page, dueDate)
		await expect(detail).toContainText(`Einreichung überfällig seit ${germanDate(releaseDate)}`)
		await expect(detail).toContainText('Das blockiert nichts')

		await detail.getByRole('button', { name: 'Datei ist bei der Bank eingereicht' }).click()
		await page.getByRole('dialog', { name: 'Datei als eingereicht bestätigen' }).getByRole('button', { name: 'Ja, eingereicht' }).click()
		await expect(openRunRow(page).getByText('eingereicht', { exact: true })).toBeVisible()
		// Mit der Einreichung ist nichts mehr dringend.
		await expect(detail).not.toContainText('Einreichung überfällig')
	})

	test('Drift-Warnung: die Mandatsdaten haben sich seit der Freigabe geändert, Weg über Verwerfen und Neu-Freigeben', async ({ page, request }) => {
		const dueDate = plusDays(20)
		const { member, mandate } = await ensureMemberWithMandate(request, 'Freigabe', 'Abweichung')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Freigabe Abweichung' })
		const oldIban = mandate.iban.replaceAll(/\s/g, '')
		const newIban = oldIban === IBAN ? IBAN_NEW : IBAN
		const batch = await releaseRun(request, dueDate)

		// Nach der Freigabe zieht das Mitglied um: eine neue IBAN am selben Mandat (Amendment).
		const amend = await api.raw(request, 'POST', `/mandates/${mandate.id}/amend-bank-details`, { data: { iban: newIban } })
		expect(amend.ok()).toBeTruthy()

		await openEinzug(page)
		const detail = await expandRun(page, batch.id)
		await expect(detail).toContainText(/1 Posten weicht von den aktuellen Mandatsdaten ab/)

		// Weg aus der Warnung: verwerfen und neu freigeben – die Abweichung ist als Begründung vorgeschlagen.
		await detail.getByRole('button', { name: 'Verwerfen und neu freigeben' }).click()
		const dialog = page.getByRole('dialog', { name: 'Lauf verwerfen und neu freigeben' })
		await expect(dialog).toBeVisible()
		await expect(dialog.getByLabel('Begründung (Pflicht)')).toHaveValue('Abweichung von den aktuellen Mandatsdaten')
		await dialog.getByRole('button', { name: 'Lauf verwerfen' }).click()
		await expect(dialog).toBeHidden()
		await expect(openRunRow(page).getByText('verworfen', { exact: true })).toBeVisible()

		await selectDate(page, dueDate)
		await expect(ghostCard(page, dueDate)).toContainText('Freigabe Abweichung')
		const second = await releaseInUi(page, dueDate)
		await expect(second).not.toContainText('weicht von den aktuellen Mandatsdaten ab')
		await expect(second).not.toContainText('weichen von den aktuellen Mandatsdaten ab')

		// Die neue Datei trägt die aktuelle IBAN, nicht die vom Tag der ersten Freigabe.
		const secondBatch = await latestBatch(request, dueDate)
		expect(secondBatch.id).not.toBe(batch.id)
		const xml = await (await api.raw(request, 'GET', `/debit-batches/${secondBatch.id}/xml`)).text()
		expect(xml).toContain(newIban)
		expect(xml).not.toContain(oldIban)
	})

	test('XML-Ablage eingeschaltet: der Ablageort steht am Lauf, und die Kopie liegt wirklich dort', async ({ page, request }) => {
		const dueDate = plusDays(23)
		const folder = 'E2E-Freigabe-Ablage'
		const { member } = await ensureMemberWithMandate(request, 'Freigabe', 'Ablage')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Freigabe Ablage' })

		// Die Ablage ist eine Einstellung der Verwaltung (App-Config, resetBook() räumt sie nicht) und braucht den Ablage-Nutzer.
		await api.updateSettings(request, { storage_user: 'admin', storage_path: 'Vereinsbuchhaltung/Belege' })
		const enable = await api.raw(request, 'POST', '/debit-batches/settings', { data: { xmlFolderEnabled: '1', xmlFolderPath: folder } })
		expect(enable.ok()).toBeTruthy()
		try {
			await openEinzug(page)
			await selectDate(page, dueDate)
			const detail = await releaseInUi(page, dueDate)
			await expect(detail).toContainText('Die XML-Ablage ist eingeschaltet')
			await expect(detail).toContainText(folder)

			const msgId = (await detail.locator('.vbh-rd-msgid').innerText()).trim()
			expect(await dav.exists(request, 'admin', `${folder}/${msgId}.xml`)).toBe(true)
		} finally {
			await api.raw(request, 'POST', '/debit-batches/settings', { data: { xmlFolderEnabled: '0', xmlFolderPath: 'SEPA-Einreichungen' } })
			await api.updateSettings(request, { storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
			await dav.remove(request, 'admin', folder)
		}
	})

	test('ohne XML-Ablage steht am Lauf kein Ablageort', async ({ page, request }) => {
		const dueDate = plusDays(25)
		const { member } = await ensureMemberWithMandate(request, 'Freigabe', 'OhneAblage')
		await createClaim(request, member.id, dueDate, { label: 'Beitrag Freigabe ohne Ablage' })
		// Standard: aus – falls eine frühere Spec sie angelassen hat, ausdrücklich ausschalten.
		await api.raw(request, 'POST', '/debit-batches/settings', { data: { xmlFolderEnabled: '0' } })

		await openEinzug(page)
		await selectDate(page, dueDate)
		const detail = await releaseInUi(page, dueDate)
		await expect(detail).not.toContainText('XML-Ablage')
	})

	test('Revisor sieht den Zustand ohne Schaltflächen, und das Backend lässt ihn nichts ändern', async ({ page, request }) => {
		const openDate = plusDays(21)
		const releasedDate = plusDays(22)
		const { member } = await ensureMemberWithMandate(request, 'Freigabe', 'Revisor')
		await createClaim(request, member.id, releasedDate, { label: 'Beitrag Freigabe Revisor, im Lauf' })
		const batch = await releaseRun(request, releasedDate)
		await createClaim(request, member.id, openDate, { label: 'Beitrag Freigabe Revisor, offen' })

		await openApp(page, USERS.revisor)
		// Der Willkommenshinweis für Kassenprüfer erscheint je Browserprofil einmal (siehe 08-roles).
		await expect(page.getByText('Willkommen als Kassenprüfer/in')).toBeVisible()
		await page.getByRole('button', { name: 'Verstanden' }).click()
		await expect(tabButton(page, 'Beiträge')).toBeVisible()
		await switchTab(page, 'Beiträge')
		const section = visibleSection(page)
		await expect(section.getByRole('heading', { name: /^Beitragsjahr / })).toBeVisible()

		// Die Geisterkarte zeigt die Vorschau, aber keine Freigabe.
		await selectDate(page, openDate)
		const ghost = ghostCard(page, openDate)
		await expect(ghost).toContainText('Freigabe Revisor')
		await expect(ghost.getByRole('button')).toHaveCount(0)
		await expect(section.getByRole('button', { name: 'Freigeben & Datei erzeugen' })).toHaveCount(0)

		// Der Lauf zeigt seinen Zustand, aber weder Download noch Einreichen, Verschieben, Verwerfen.
		const detail = await expandRun(page, batch.id)
		await expect(openRunRow(page).getByText('freigegeben', { exact: true })).toBeVisible()
		await expect(detail.getByRole('button')).toHaveCount(0)
		await expect(detail.getByRole('link')).toHaveCount(0)

		// Dasselbe an der API: lesen ja, alles andere nein.
		for (const [method, path, data] of [
			['POST', '/debit-batches', { dueDate: openDate }],
			['POST', `/debit-batches/${batch.id}/submit`, undefined],
			['POST', `/debit-batches/${batch.id}/discard`, { reason: 'x' }],
			['POST', `/debit-batches/${batch.id}/reschedule`, { dueDate: plusDays(30) }],
			['GET', `/debit-batches/${batch.id}/xml`, undefined],
		]) {
			const resp = await api.raw(request, method, path, { user: USERS.revisor, ...(data !== undefined ? { data } : {}) })
			expect(resp.status(), `${method} ${path}`).toBe(403)
		}
		expect((await api.raw(request, 'GET', `/debit-batches/${batch.id}`, { user: USERS.revisor })).ok()).toBeTruthy()
		expect((await api.getJson(request, `/debit-batches/${batch.id}`)).status).toBe('freigegeben')
	})
})
