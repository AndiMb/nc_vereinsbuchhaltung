import { expect, test } from '@playwright/test'
import { api, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, dav, INCOME_ACCOUNT } from './fixtures/nextcloud.mjs'

// Issue #366: Wird eine Zahlung einer Rechnung zugeordnet, hängt die App das
// Rechnungs-PDF als Beleg an die Zahlungsbuchung – verknüpft, nicht kopiert.
// Geprüft im Wächter-Modus mit einem Rechnungsordner außerhalb des
// Wächter-Ordners (der Fall aus #360) und in der app-internen Ablage.

const YEAR = new Date().getFullYear()
const TODAY = `${YEAR}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`
const WATCH = 'E2E-Waechter-366'
const INVOICE_FOLDER = 'E2E-Rechnungen-366'

test.describe.serial('Rechnungs-PDF als Beleg der Zahlung (#366)', () => {
	let bank
	let income
	let debtor

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		bank = await api.accountByNumber(request, BANK_ACCOUNT)
		income = await api.accountByNumber(request, INCOME_ACCOUNT)
		await api.updateAccount(request, bank.id, { ...bank, iban: BANK_ACCOUNT_IBAN })
		await dav.mkcol(request, 'admin', WATCH)
		await dav.mkcol(request, 'admin', INVOICE_FOLDER)
		await api.updateSettings(request, {
			club_name: 'TSV E2E e. V.',
			club_street: 'Hauptstr. 1',
			club_postal_code: '12345',
			club_city: 'Beispielstadt',
			club_tax_number: '12/345/67890',
			invoice_module_active: '1',
			invoice_account_id: bank.id,
			invoice_tax_mode: 'kleinunternehmer',
			invoice_folder: INVOICE_FOLDER,
			storage_mode: 'watch',
			storage_user: 'admin',
			storage_path: WATCH,
		})
		debtor = await (await api.raw(request, 'POST', '/debtors', {
			data: { kind: 'organisation', name: 'Beleg E2E GmbH', street: 'Weg 2', postalCode: '54321', city: 'Musterstadt', email: 'info@beleg-e2e.example' },
		})).json()
	})

	test.afterAll(async ({ request }) => {
		await api.resetBook(request)
		await api.updateSettings(request, { storage_mode: 'appdata', storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege', invoice_folder: '' })
		await dav.remove(request, 'admin', WATCH)
		await dav.remove(request, 'admin', INVOICE_FOLDER)
	})

	async function issueInvoice(request, cents) {
		const draft = await (await api.raw(request, 'POST', '/invoices', {
			data: { debtorId: debtor.id, issueDate: TODAY, deliveryDate: TODAY, lines: [{ name: 'Hallenmiete', quantity: 1, unitCode: 'LS', unitPriceCents: cents, accountId: income.id }] },
		})).json()
		return (await api.raw(request, 'POST', `/invoices/${draft.id}/issue`, { data: {} })).json()
	}

	const attachmentsOf = (request, journalId) => api.getJson(request, `/journal/${journalId}/attachments`)

	test('Jede Teilzahlung bekommt das PDF verknüpft, die Datei bleibt beim Zurücknehmen', async ({ request }) => {
		const invoice = await issueInvoice(request, 10000)
		const pdf = invoice.documents.find((d) => d.kind === 'pdf')
		expect(pdf, 'PDF liegt im Rechnungsordner des Ablage-Nutzers').toBeTruthy()
		expect(pdf.fileId).toBeGreaterThan(0)

		const afterFirst = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { data: { date: TODAY, accountId: bank.id, amountCents: 4000 } })).json()
		const afterSecond = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { data: { date: TODAY, accountId: bank.id } })).json()
		expect(afterSecond.status).toBe('paid')
		expect(afterSecond.payments).toHaveLength(2)

		for (const payment of afterSecond.payments) {
			const attachments = await attachmentsOf(request, payment.journalId)
			expect(attachments).toHaveLength(1)
			expect(attachments[0]).toMatchObject({ fileId: pdf.fileId, fileName: pdf.fileName, mimeType: 'application/pdf', unlinkOnly: true })
			// Der Rechnungsordner liegt nicht im Wächter-Ordner – der Beleg gilt trotzdem als vorhanden.
			expect(attachments[0].missing).toBe(false)
		}
		const download = await api.raw(request, 'GET', `/attachments/${(await attachmentsOf(request, afterFirst.payments[0].journalId))[0].id}/download`)
		expect(download.status()).toBe(200)
		expect((await download.body()).subarray(0, 5).toString()).toBe('%PDF-')

		// Zahlung zurücknehmen: Die Buchung samt Verknüpfung geht, die Datei bleibt unverändert.
		const reverted = await (await api.raw(request, 'DELETE', `/invoices/${invoice.id}/payments/${afterSecond.payments[1].id}`)).json()
		expect(reverted.remainingCents).toBe(6000)
		expect(reverted.documents.find((d) => d.kind === 'pdf').integrity).toBe('ok')
		expect(await attachmentsOf(request, afterSecond.payments[0].journalId)).toHaveLength(1)
	})

	test('In der app-internen Ablage bleibt die Zahlungsbuchung ohne Beleg', async ({ request }) => {
		await api.updateSettings(request, { storage_mode: 'appdata', storage_user: '', storage_path: 'Vereinsbuchhaltung/Belege' })
		const invoice = await issueInvoice(request, 2500)
		expect(invoice.documents.find((d) => d.kind === 'pdf').fileId).toBe(0)

		const paid = await (await api.raw(request, 'POST', `/invoices/${invoice.id}/payments/manual`, { data: { date: TODAY, accountId: bank.id } })).json()
		expect(paid.status).toBe('paid')
		expect(await attachmentsOf(request, paid.payments[0].journalId)).toEqual([])
	})
})
