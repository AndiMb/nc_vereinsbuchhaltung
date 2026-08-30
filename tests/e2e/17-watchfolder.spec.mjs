import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, fixture, authHeaders, davUrl, CAMT_STATEMENT } from './fixtures/nextcloud.mjs'

// Der Wachordner: ein per WebDAV abgelegter Kontoauszug wird vom
// Hintergrund-Job eingelesen, ganz ohne manuellen Import. Der Job wird
// über occ im Container angestoßen – so, wie ihn sonst der Cron träfe.

test.describe('Wachordner', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await api.updateSettings(request, {
			statement_watch_user: 'admin',
			statement_watch_path: 'Auszuege',
		})
	})

	test('abgelegter Auszug wird vom Hintergrund-Job importiert', async ({ request }) => {
		test.setTimeout(60000)

		// Auszug in den überwachten Ordner legen (WebDAV, wie es die Bank-App
		// oder der Sync-Client täten).
		const dav = davUrl('admin', 'Auszuege')
		await request.fetch(dav, { method: 'MKCOL', headers: authHeaders() })
		const put = await request.fetch(`${dav}/kontoauszug.csv`, {
			method: 'PUT',
			headers: { ...authHeaders(), 'Content-Type': 'text/csv' },
			data: fixture(CAMT_STATEMENT.csv),
		})
		expect([201, 204]).toContain(put.status())

		// Den Job so ausführen, wie ihn der Server-Cron ausführen würde.
		const container = getContainer()
		const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
		const job = JSON.parse(stdout).find((j) => (j.class || '').includes('ImportWatchFolderJob'))
		expect(job, 'ImportWatchFolderJob ist registriert').toBeTruthy()
		await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })

		const tx = await api.listTransactions(request)
		expect(tx).toHaveLength(CAMT_STATEMENT.txCount)
	})
})
