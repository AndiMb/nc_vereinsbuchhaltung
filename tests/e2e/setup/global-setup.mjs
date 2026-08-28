import { restoreSnapshot, getContainer } from '@nextcloud/e2e-test-server'
import { existsSync, rmSync } from 'fs'
import { AUTH_DIR, BASE_URL, authHeaders } from '../fixtures/nextcloud.mjs'

// Läuft einmal vor jedem `playwright test`: spielt den "init"-Snapshot
// (Nutzer + Rollen, leerer Buchungsbestand) zurück und verwirft gecachte
// Anmeldezustände – die Sessions leben in der zurückgesetzten Datenbank.
export default async function globalSetup() {
	console.log('\n🔄 Stelle "init"-Snapshot wieder her…')
	await restoreSnapshot('init', getContainer())

	if (existsSync(AUTH_DIR)) {
		rmSync(AUTH_DIR, { recursive: true, force: true })
		console.log('🗑️  Anmelde-Cache geleert')
	}

	// Aufwärmen: der erste Request nach dem Restore trifft sonst kalte
	// Caches und reißt gern das Timeout des ersten Tests.
	for (let i = 0; i < 5; i++) {
		try {
			const resp = await fetch(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/api/permissions/me`, {
				headers: authHeaders(),
			})
			if (resp.ok && (resp.headers.get('content-type') || '').includes('json')) {
				console.log('✅ Server aufgewärmt\n')
				return
			}
		} catch { /* erneut versuchen */ }
		await new Promise((r) => setTimeout(r, 2000))
	}
	console.warn('⚠️  Aufwärmen fehlgeschlagen – Tests starten trotzdem\n')
}
