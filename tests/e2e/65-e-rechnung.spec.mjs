import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, INCOME_ACCOUNT, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// E-Rechnung (Epic #168, Stufe 2): ZUGFeRD-PDF und XRechnung zu einer
// festgeschriebenen Rechnung, Pflichtangaben je Format, Download und Oberfläche.

const YEAR = new Date().getFullYear()
const TODAY = `${YEAR}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`

test.describe.serial('E-Rechnung', () => {
	let bank
	let income
	let company
	let authority
	let first

	const draftBody = (debtor, extra = {}) => ({
		debtorId: debtor.id,
		issueDate: TODAY,
		deliveryDate: TODAY,
		lines: [{ name: 'Hallenmiete', quantity: 1, unitCode: 'LS', unitPriceCents: 8925, accountId: income.id }],
		...extra,
	})

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		bank = await api.accountByNumber(request, BANK_ACCOUNT)
		income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.updateAccount(request, bank.id, { ...bank, iban: BANK_ACCOUNT_IBAN })
		await api.updateSettings(request, {
			club_name: 'TSV E2E e. V.',
			club_street: 'Hauptstr. 1',
			club_postal_code: '12345',
			club_city: 'Beispielstadt',
			club_tax_number: '12/345/67890',
			invoice_module_active: '1',
			invoice_account_id: bank.id,
			invoice_tax_mode: 'kleinunternehmer',
		})
		company = await (await api.raw(request, 'POST', '/debtors', {
			data: { kind: 'organisation', name: 'Sportstudio E2E GmbH', street: 'Weg 2', postalCode: '54321', city: 'Musterstadt', email: 'info@studio-e2e.example' },
		})).json()
		authority = await (await api.raw(request, 'POST', '/debtors', {
			data: { kind: 'behoerde', name: 'Stadt Beispielstadt', street: 'Rathausplatz 1', postalCode: '12345', city: 'Beispielstadt', email: 'rechnung@stadt.example', leitwegId: '04011000-1234512345-35' },
		})).json()
	})

	test('Beim Festschreiben entsteht für ein Unternehmen ein ZUGFeRD-PDF neben dem PDF', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', { data: draftBody(company) })).json()
		first = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })).json()

		const kinds = first.documents.map((d) => d.kind).sort()
		expect(kinds).toEqual(['pdf', 'zugferd_pdf'])
		expect(first.eInvoice.suggested).toBe('zugferd')

		const zugferd = first.documents.find((d) => d.kind === 'zugferd_pdf')
		expect(zugferd.profile).toBe('EN 16931')
		expect(zugferd.integrity).toBe('ok')
		const download = await api.raw(request, 'GET', `/invoices/${first.id}/documents/${zugferd.id}`)
		const bytes = await download.body()
		expect(bytes.subarray(0, 5).toString()).toBe('%PDF-')
		// Das XML steckt als Anhang im PDF.
		expect(bytes.toString('latin1')).toContain('factur-x.xml')
	})

	test('XRechnung verlangt Telefon und E-Mail des Vereins; die Lücken kommen als Liste', async ({ request }) => {
		const rejected = await api.raw(request, 'POST', `/invoices/${first.id}/e-invoice`, { data: { format: 'xrechnung_cii' } })
		expect(rejected.status()).toBe(400)
		const body = await rejected.json()
		expect(body.problems).toContain('Telefonnummer des Vereins (Einstellungen)')
		expect(body.problems).toContain('E-Mail-Adresse des Vereins (Einstellungen)')
	})

	test('Mit allen Angaben entsteht die XRechnung als XML, ein zweiter Aufruf liefert dasselbe Dokument', async ({ request }) => {
		await api.updateSettings(request, { club_phone: '0123 456789', club_email: 'kasse@tsv-e2e.example' })
		const draft = await (await api.raw(request, 'POST', '/invoices', { data: draftBody(authority, { buyerReference: '04011000-1234512345-35' }) })).json()
		const issued = await (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })).json()
		expect(issued.eInvoice.suggested).toBe('xrechnung_cii')
		// Für Behörden wurde die XRechnung beim Festschreiben bereits erzeugt.
		const auto = issued.documents.find((d) => d.kind === 'xrechnung_cii')
		expect(auto, 'XRechnung beim Festschreiben erzeugt').toBeTruthy()

		const xml = (await (await api.raw(request, 'GET', `/invoices/${issued.id}/documents/${auto.id}`)).body()).toString('utf8')
		expect(xml).toContain('kosit:xrechnung_3.0')
		expect(xml).toContain(issued.number)

		const again = await (await api.raw(request, 'POST', `/invoices/${issued.id}/e-invoice`, { data: { format: 'xrechnung_cii' } })).json()
		expect(again.documents.filter((d) => d.kind === 'xrechnung_cii')).toHaveLength(1)

		const ubl = await (await api.raw(request, 'POST', `/invoices/${issued.id}/e-invoice`, { data: { format: 'xrechnung_ubl' } })).json()
		expect(ubl.documents.map((d) => d.kind)).toContain('xrechnung_ubl')
	})

	test('Entwurf und unbekanntes Format werden abgelehnt; der Revisor darf nicht erzeugen', async ({ request }) => {
		const draft = await (await api.raw(request, 'POST', '/invoices', { data: draftBody(company) })).json()
		expect((await api.raw(request, 'POST', `/invoices/${draft.id}/e-invoice`, { data: { format: 'zugferd' } })).status()).toBe(400)
		expect((await api.raw(request, 'POST', `/invoices/${first.id}/e-invoice`, { data: { format: 'edifact' } })).status()).toBe(400)
		expect((await api.raw(request, 'POST', `/invoices/${first.id}/e-invoice`, { user: USERS.revisor, data: { format: 'zugferd' } })).status()).toBe(403)
		await api.raw(request, 'DELETE', `/invoices/${draft.id}`)
	})

	test('Oberfläche: Dokumentliste zeigt das Profil und bietet fehlende Formate an', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await switchTab(page, 'Rechnungen')
		const section = visibleSection(page)
		await section.locator('tbody tr', { hasText: first.number }).click()
		const dialog = page.getByRole('dialog')
		await expect(dialog.getByText('EN 16931')).toBeVisible()
		const select = dialog.getByLabel('E-Rechnung erzeugen')
		await expect(select).toBeVisible()
		await select.selectOption('xrechnung_cii')
		await expect(dialog.getByText('Für dieses Format fehlen noch Angaben:')).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Erzeugen' })).toBeDisabled()
	})
})
