import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN } from './fixtures/nextcloud.mjs'
import { decodeGiroCodes, giroCodeRuntimeCheck, pngInfo } from './fixtures/girocode.mjs'
import { clearCapturedMails, startMailCapture, stopMailCapture, waitForMailsTo } from './fixtures/mail-capture.mjs'

// GiroCode-Anhang der Mahnmails in der echten Nextcloud-Laufzeit (Issue #120,
// Spec §3.6/§3.11): jede Position einer Zahlungsaufforderung bekommt ihren
// eigenen EPC-QR-Code (EPC069-12) als PNG-Anhang. Der Code hängt an einer
// Composer-Bibliothek (chillerlan/php-qrcode) und der PHP-Erweiterung gd –
// beides prüft kein PHPUnit-Test in der Laufzeit des Servers. Bis zu diesem
// Ticket hing zudem nie ein GiroCode an einer Mail: Nextcloud lädt das
// vendor/autoload.php einer App nicht, die Bibliothek war nicht ladbar, und
// der Mahnversand fing den Fehler lautlos ab.
//
// Die Mails greift ein sendmail-Ersatz im Container ab (fixtures/mail-capture.mjs,
// Begründung dort). Geprüft wird die ECHTE Mail: Empfänger, Text, Anhänge,
// Bildinhalt – und, über die Bibliothek im Container, die EPC-Nutzlast hinter
// jedem Bild. Ausgelöst wird auf den beiden echten Wegen: dem Tageslauf des
// Mahnwesens (occ background-job:execute, wie in 28/43) und dem Mandatswiderruf
// per Web-Request (Apache-PHP statt Kommandozeilen-PHP).
//
// Die Stufen 1 (Erinnerung) und 2 (Mahnung) bräuchten Zeitreise (Mahnabstand
// mindestens ein Tag); sie hängen denselben Anhang an – das deckt der Unit-Test
// DunningLadderServiceTest ab. Den Ausfall von gd oder der Bibliothek kann man
// im laufenden Container nicht herstellen, ohne die Dateien des Checkouts
// anzufassen (EpcQrCodeGeneratorTest und DunningLadderServiceTest bilden ihn nach).
//
// Der Bestand ist NICHT leer: api.resetBook() räumt Buchungen, Konten, offene
// Posten und alles, was daran hängt (Läufe, Mahnstufen), nicht aber Mitglieder
// und Mandate früherer Specs. Jeder Test
// legt deshalb ein Mitglied mit eigenem Namen an und seedet seine Forderungen
// selbst.

const iso = (date) => date.toISOString().slice(0, 10)
const plusDays = (days) => iso(new Date(Date.now() + days * 86400000))
const unique = () => Date.now().toString(36)
const CLUB_NAME = 'Testverein e.V.'

async function enableModule(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: CLUB_NAME,
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

/** Neues Mitglied mit eigener Mailadresse – der Test findet seine Mail darüber. */
async function createMember(request, lastName) {
	const email = `giro.${lastName}@example.org`.toLowerCase()
	const member = await api.createMember(request, { firstName: 'Giro', lastName, email })
	return { ...member, email }
}

async function createClaims(request, memberId, positions, dueDate) {
	const claims = []
	for (const { label, amount } of positions) {
		const resp = await api.raw(request, 'POST', '/claims', { data: { memberId, type: 'beitrag', amount, label, dueDate } })
		expect(resp.ok(), `Forderung "${label}" anlegen: HTTP ${resp.status()}`).toBeTruthy()
		claims.push(await resp.json())
	}
	return claims
}

async function runDunningJob() {
	const container = getContainer()
	const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
	const job = JSON.parse(stdout).find((j) => (j.class || '').includes('DunningLadderJob'))
	expect(job, 'DunningLadderJob ist registriert').toBeTruthy()
	await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })
}

/**
 * Die GiroCode-Anhänge einer Mail. Das Mail-Template von Nextcloud bettet außerdem das Instanz-Logo als PNG-Teil
 * (`filename: "logo"`) ein; es ist kein Anhang des Mahnversands und zählt nicht mit.
 */
function giroCodesOf(mail) {
	return mail.attachments.filter((a) => /^girocode-\d+\.png$/.test(a.filename || ''))
}

/** Eine Zahlungsaufforderung trägt je Position einen gültigen GiroCode mit den Daten dieser Position. */
async function expectGiroCodePerPosition(mail, claims, positions, dueDate) {
	// Der Verwendungszweck nennt die Fälligkeit als TT.MM.JJJJ.
	const dueDateDe = dueDate.split('-').reverse().join('.')
	expect(mail.subject).toContain(`Zahlungsaufforderung von ${CLUB_NAME}`)
	expect(mail.text, 'Der Mailtext verweist auf die GiroCodes').toContain('GiroCode')

	const giroCodes = giroCodesOf(mail)
	const byName = new Map(giroCodes.map((a) => [a.filename, a]))
	expect([...byName.keys()].sort(), 'ein Anhang je Position, kein Sammelbetrag').toEqual(claims.map((c) => `girocode-${c.id}.png`).sort())
	for (const attachment of giroCodes) {
		expect(attachment.contentType).toBe('image/png')
		const info = pngInfo(attachment.data)
		expect(info, `${attachment.filename} ist ein PNG (Signatur und IHDR)`).not.toBeNull()
		expect(info.width, 'ein QR-Code ist quadratisch').toBe(info.height)
		expect(info.width, 'groß genug zum Scannen').toBeGreaterThanOrEqual(150)
		expect(info.bytes, 'kein leeres oder abgeschnittenes PNG').toBeGreaterThan(800)
	}

	const ordered = claims.map((claim) => byName.get(`girocode-${claim.id}.png`).data)
	const payloads = await decodeGiroCodes(ordered)
	claims.forEach((claim, i) => {
		const lines = payloads[i]
		const { label, amount } = positions[i]
		const where = `GiroCode der Position "${label}": ${JSON.stringify(lines)}`
		expect(lines.slice(0, 4), where).toEqual(['BCD', '002', '1', 'SCT'])
		expect(lines[5], `Name des Zahlungsempfängers – ${where}`).toBe(CLUB_NAME)
		expect(lines[6], `IBAN – ${where}`).toBe(BANK_ACCOUNT_IBAN)
		expect(lines[7], `Betrag – ${where}`).toBe(`EUR${amount.toFixed(2)}`)
		expect(lines[10], `Verwendungszweck – ${where}`).toContain(label)
		expect(lines[10], `Verwendungszweck – ${where}`).toContain(dueDateDe)
	})
}

test.describe('GiroCode-Anhang der Zahlungsaufforderung', () => {
	test.beforeAll(async () => {
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

	test('Laufzeit: Bibliothek ladbar, gd da, PNG gültig, EPC-Nutzlast korrekt', async () => {
		test.setTimeout(60000)
		const name = 'Förderverein Müller & Söhne e.V.'
		const purpose = 'Beitrag 2026: 45,99 €, fällig 2026-10-10'

		const result = await giroCodeRuntimeCheck(name, 'de02 1203 0000 0000 2020 51', purpose)

		expect(result.gd, `PHP ${result.php} im Container hat keine gd-Erweiterung`).toBe(true)
		expect(result.gdPng, 'gd ohne PNG-Unterstützung').toBe(true)
		expect(result.generator, 'EpcQrCodeGenerator ist über den Autoloader der App ladbar').toBe(true)
		expect(result.library, 'chillerlan/php-qrcode ist nach der App-Registrierung ladbar (Application::register lädt vendor/autoload.php; fehlt vendor/, hat die CI-Stufe „composer install“ gefehlt)').toBe(true)
		expect(result.error, 'generatePng() wirft').toBeUndefined()

		const png = Buffer.from(result.png, 'base64')
		const info = pngInfo(png)
		expect(info, 'PNG-Signatur und IHDR').not.toBeNull()
		expect(info.width).toBe(info.height)
		expect(info.width).toBeGreaterThanOrEqual(150)
		expect(info.bytes).toBeGreaterThan(800)

		// Was die Banking-App aus dem Bild liest: EPC069-12, Version 002, UTF-8, BIC leer, IBAN normalisiert.
		const [lines] = await decodeGiroCodes([png])
		expect(lines).toEqual(['BCD', '002', '1', 'SCT', '', name, 'DE02120300000000202051', 'EUR45.99', '', '', purpose])
	})

	test('Tageslauf: eine Zahlungsaufforderung mit drei Positionen trägt drei GiroCodes', async ({ request }) => {
		test.setTimeout(120000)
		const dueDate = plusDays(3)
		const member = await createMember(request, `tageslauf${unique()}`)
		const positions = [
			{ label: 'Giro Beitrag A', amount: 12.5 },
			{ label: 'Giro Spende B', amount: 7 },
			{ label: 'Giro Umlage C äöü', amount: 100 },
		]
		// Ohne Mandat nie per Lastschrift: die Zahlungsaufforderung geht vor der Fälligkeit raus.
		const claims = await createClaims(request, member.id, positions, dueDate)

		await runDunningJob()

		const mails = await waitForMailsTo(member.email)
		expect(mails, 'gebündelt: eine Mail je Mitglied, nicht je Position').toHaveLength(1)
		expect(giroCodesOf(mails[0])).toHaveLength(3)
		await expectGiroCodePerPosition(mails[0], claims, positions, dueDate)
	})

	test('Mandatswiderruf per Web-Request: die sofortige Zahlungsaufforderung trägt je Position einen GiroCode', async ({ request }) => {
		test.setTimeout(120000)
		const dueDate = plusDays(30)
		const member = await createMember(request, `widerruf${unique()}`)
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: BANK_ACCOUNT_IBAN, signedAt: '2024-01-01' })).json()
		await api.activateMandate(request, mandate.id)
		const positions = [
			{ label: 'Giro Beitrag D', amount: 45.99 },
			{ label: 'Giro Umlage E', amount: 8 },
		]
		const claims = await createClaims(request, member.id, positions, dueDate)

		// Läuft im Apache-PHP des Servers, nicht auf der Kommandozeile.
		const revoked = await api.revokeMandate(request, mandate.id)
		expect(revoked.status()).toBe(200)

		const mails = await waitForMailsTo(member.email)
		expect(mails).toHaveLength(1)
		await expectGiroCodePerPosition(mails[0], claims, positions, dueDate)
	})

	test('Ohne eingestelltes Zahlungskonto geht die Mail ohne GiroCode raus', async ({ request }) => {
		test.setTimeout(120000)
		const dueDate = plusDays(3)
		const member = await createMember(request, `ohnekonto${unique()}`)
		await createClaims(request, member.id, [{ label: 'Giro Beitrag F', amount: 20 }], dueDate)
		await api.updateSettings(request, { sepa_debtor_account_id: '' })
		expect((await api.getSettings(request)).sepa_debtor_account_id, 'Vorbedingung: kein Zahlungskonto').toBeNull()

		await runDunningJob()

		const [mail] = await waitForMailsTo(member.email)
		expect(mail.subject).toContain(`Zahlungsaufforderung von ${CLUB_NAME}`)
		expect(giroCodesOf(mail), 'ohne Konto kein GiroCode').toHaveLength(0)
		expect(mail.text).toContain('Giro Beitrag F')
		expect(mail.text, 'der Text verspricht keinen Anhang, der nicht dran hängt').not.toContain('GiroCode')
	})
})
