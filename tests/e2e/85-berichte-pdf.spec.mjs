import { expect, test } from '@playwright/test'
import { api, dav, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Issue #364: Kassenbericht, Gliederungsbericht (Tabellenansicht) und
// Beitragsbestätigung als PDF, dazu die Ablage
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

	test('Gliederungsbericht als PDF-Datei, unbekannte Vorlage 404', async ({ request }) => {
		const { templates } = await api.getJson(request, '/report-templates')
		expect(templates.length, 'Es muss mindestens eine Gliederungsvorlage geben').toBeGreaterThan(0)
		const template = templates[0]
		const disposition = await expectPdf(await api.raw(request, 'GET', `/export/gliederungsbericht/pdf?template=${template.id}&period=${period.id}`))
		expect(disposition).toContain('Gliederungsbericht-')
		expect(disposition).toContain('.pdf')

		const missing = await api.raw(request, 'GET', '/export/gliederungsbericht/pdf?template=999999', { expectOk: false })
		expect(missing.status()).toBe(404)
	})

	test('Visueller Gliederungsbericht als PDF-Datei, unbekannte Vorlage 404', async ({ request }) => {
		const { templates } = await api.getJson(request, '/report-templates')
		const template = templates[0]
		const disposition = await expectPdf(await api.raw(request, 'GET', `/export/gliederungsbericht-visuell/pdf?template=${template.id}&period=${period.id}&basis=time`))
		expect(disposition).toContain('Gliederungsbericht-visuell-')
		expect(disposition).toContain('.pdf')

		const missing = await api.raw(request, 'GET', '/export/gliederungsbericht-visuell/pdf?template=999999', { expectOk: false })
		expect(missing.status()).toBe(404)
	})

	test('PDF-Knopf des Gliederungsberichts in beiden Ansichten', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		const intro = page.getByRole('button', { name: 'Verstanden' })
		if (await intro.isVisible().catch(() => false)) { await intro.click() }
		await switchTab(page, 'Berichte')
		const section = visibleSection(page)
		await section.getByRole('button', { name: 'Gliederung', exact: true }).click()
		const pdf = section.getByTestId('structured-report-pdf')
		await expect(pdf).toBeVisible()
		expect(await pdf.getAttribute('href')).toContain('/export/gliederungsbericht/pdf?template=')

		await section.getByRole('button', { name: 'Visuell', exact: true }).click()
		await expect(section.getByRole('button', { name: 'Visuell', exact: true })).toHaveAttribute('aria-pressed', 'true')
		await expect(pdf).toBeVisible()
		await expect(pdf).toHaveAttribute('href', /\/export\/gliederungsbericht-visuell\/pdf\?template=.*&basis=/)
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
