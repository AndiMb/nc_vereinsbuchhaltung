import { expect, test } from '@playwright/test'
import { api, dav } from './fixtures/nextcloud.mjs'

// Issue #364: Kassenbericht und Beitragsbestätigung als PDF, dazu die Ablage
// des Kassenberichts beim Abschluss eines Geschäftsjahres im Berichtsordner
// der Belegablage.

const YEAR = new Date().getFullYear()
const STORAGE = 'E2E-Belege-364'
const REPORTS = 'E2E-Berichte-364'

async function expectPdf(resp) {
	expect(resp.status()).toBe(200)
	expect(resp.headers()['content-type']).toContain('application/pdf')
	const body = await resp.body()
	expect(body.subarray(0, 5).toString()).toBe('%PDF-')
	return resp.headers()['content-disposition'] || ''
}

test.describe.serial('Berichte als PDF (#364)', () => {
	let period

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.enableMembership(request)
		await dav.mkcol(request, 'admin', STORAGE)
		await api.updateSettings(request, {
			club_name: 'TSV E2E e. V.',
			storage_mode: 'user',
			storage_user: 'admin',
			storage_path: STORAGE,
		})
		period = (await api.listPeriods(request)).find((p) => p.startDate.startsWith(String(YEAR)))
	})

	test.afterAll(async ({ request }) => {
		if (period) {
			await api.reopenPeriod(request, period.id)
		}
		await api.resetBook(request)
		await api.updateSettings(request, {
			storage_mode: 'appdata',
			storage_user: '',
			storage_path: 'Vereinsbuchhaltung/Belege',
			report_archive_on_close: '0',
			report_folder: '',
		})
		await dav.remove(request, 'admin', STORAGE)
		await dav.remove(request, 'admin', REPORTS)
	})

	test('Kassenbericht als PDF-Datei', async ({ request }) => {
		const disposition = await expectPdf(await api.raw(request, 'GET', `/export/kassenbericht/pdf?period=${period.id}`))
		expect(disposition).toContain('Kassenbericht-')
	})

	test('Beitragsbestätigung eines Mitglieds als PDF, unbekanntes Mitglied 404', async ({ request }) => {
		const member = await api.createMember(request, { firstName: 'Katrin', lastName: 'Brunner', email: 'katrin@example.org' })
		const disposition = await expectPdf(await api.raw(request, 'GET', `/export/beitragsbescheinigung/${member.id}/pdf?year=${YEAR}`))
		expect(disposition).toContain('.pdf')

		const missing = await api.raw(request, 'GET', '/export/beitragsbescheinigung/999999/pdf', { expectOk: false })
		expect(missing.status()).toBe(404)
	})

	test('Ohne eingeschaltete Ablage entsteht beim Abschluss keine Datei', async ({ request }) => {
		const data = await (await api.closePeriod(request, period.id)).json()
		expect(data.reportFile).toBeUndefined()
		expect(data.reportError).toBeUndefined()
		await api.reopenPeriod(request, period.id)
	})

	test('Abschluss legt den Kassenbericht im Berichtsordner ab, erneuter Abschluss eine zweite Datei', async ({ request }) => {
		const settings = await (await api.updateSettings(request, { report_archive_on_close: '1', report_folder: `/${REPORTS}/` })).json()
		expect(settings.report_archive_on_close).toBe(true)
		expect(settings.report_folder).toBe(REPORTS)

		const first = await (await api.closePeriod(request, period.id)).json()
		expect(first.reportFile).toMatch(new RegExp(`^${REPORTS}/Kassenbericht-.*\\.pdf$`))
		expect(await dav.exists(request, 'admin', first.reportFile)).toBe(true)

		await api.reopenPeriod(request, period.id)
		const second = await (await api.closePeriod(request, period.id)).json()
		expect(second.reportFile).not.toBe(first.reportFile)
		expect(await dav.exists(request, 'admin', first.reportFile)).toBe(true)
		expect(await dav.exists(request, 'admin', second.reportFile)).toBe(true)

		const audit = await api.getJson(request, '/audit')
		const entries = audit.filter((e) => e.action === 'Kassenbericht abgelegt')
		expect(entries.length).toBeGreaterThanOrEqual(2)
	})
})
