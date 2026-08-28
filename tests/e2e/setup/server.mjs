#!/usr/bin/env node

import {
	startNextcloud,
	configureNextcloud,
	waitOnNextcloud,
	createSnapshot,
	getContainer,
	setupUsers,
	stopNextcloud,
	runOcc,
} from '@nextcloud/e2e-test-server'
import { BASE_URL, authHeaders } from '../fixtures/nextcloud.mjs'

// Richtet die Docker-Nextcloud für die E2E-Suite ein und beendet sich, sobald
// der "init"-Snapshot existiert. Bewusst ein eigener Schritt vor `playwright
// test`: der Serverstart dauert Minuten und soll nicht in jedem Testlauf
// stecken – `npm run test:e2e:run` läuft danach beliebig oft gegen dieselbe
// Instanz, das Global-Setup spielt nur den Snapshot zurück.
//
// `node tests/e2e/setup/server.mjs --stop` räumt den Container wieder ab.

// Die App-Rollen für die Testnutzer. test4 bekommt bewusst keine Rolle
// (Negativtests); test5 startet ebenfalls ohne Rolle und läuft auf Englisch –
// die Leserolle für die Sprachprüfung vergibt sich 16-l10n.spec.mjs selbst.
const ROLES = [
	['test1', 'verwalter'],
	['test2', 'buchhalter'],
	['test3', 'revisor'],
]

async function assignRoles() {
	for (const [uid, role] of ROLES) {
		const resp = await fetch(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/api/permissions`, {
			method: 'POST',
			headers: authHeaders(),
			body: JSON.stringify({ principalType: 'user', principalId: uid, role }),
		})
		if (!resp.ok) {
			throw new Error(`Rolle ${role} für ${uid} fehlgeschlagen: HTTP ${resp.status} ${await resp.text()}`)
		}
		console.log(`  - ${uid} → ${role}`)
	}
}

async function main() {
	if (process.argv.includes('--stop')) {
		await stopNextcloud()
		return
	}
	try {
		console.log('Starte Nextcloud-Testserver…')

		// stable34: neueste von der App unterstützte Serverlinie, dieselbe wie
		// im CI. Der zweite Parameter mountet das App-Verzeichnis (die App-ID
		// kommt aus appinfo/info.xml, der Ordnername spielt keine Rolle).
		const ip = await startNextcloud('stable34', true, {
			exposePort: 8080,
			forceRecreate: true,
		})
		console.log(`Container läuft mit IP ${ip}`)

		await waitOnNextcloud(ip)
		await configureNextcloud(['vereinsbuchhaltung'])

		const container = getContainer()
		await setupUsers(container) // test1–test5, Passwort = Nutzername

		// Die Instanz läuft auf Deutsch – der Quellsprache der App. Die Specs
		// selektieren über die deutschen Oberflächentexte. Zusätzlich pro
		// Nutzer gesetzt: der erste Login stempelt sonst die bis dahin
		// geltende Standardsprache dauerhaft in die Nutzereinstellung.
		// Das CI-Serverimage erzwingt per force_language Englisch – weg damit,
		// sonst greifen weder default_language noch die Nutzereinstellungen.
		await runOcc(['config:system:delete', 'force_language'], { container, verbose: true })
		await runOcc(['config:system:set', 'default_language', '--value', 'de'], { container, verbose: true })
		await runOcc(['config:system:set', 'default_locale', '--value', 'de_DE'], { container, verbose: true })
		for (const uid of ['admin', 'test1', 'test2', 'test3', 'test4']) {
			await runOcc(['user:setting', uid, 'core', 'lang', 'de'], { container })
		}

		// test5 läuft auf Englisch: Regressionstest für den L10n-Endpunkt
		// (bis 0.27.2 blieb die Oberfläche in jeder Sprache deutsch).
		await runOcc(['user:setting', 'test5', 'core', 'lang', 'en'], { container, verbose: true })

		console.log('Vergebe App-Rollen…')
		await assignRoles()

		// Der Snapshot enthält Nutzer und Rollen, aber keinen Buchungsbestand –
		// jede Spec-Datei setzt sich ihren Bestand selbst auf (POST /api/reset
		// plus eigenes Seeding), damit die Dateien unabhängig bleiben.
		await createSnapshot('init', container)

		console.log('✅ Snapshot "init" angelegt.')
		console.log(`Nextcloud-Testserver: ${BASE_URL}`)
		console.log('Nutzer: admin/admin (NC-Admin = App-Verwalter), test1/test1 (Verwalter),')
		console.log('        test2/test2 (Buchhalter), test3/test3 (Revisor), test4/test4 (ohne Rolle),')
		console.log('        test5/test5 (ohne Rolle, Sprache Englisch)')
		console.log('Tests starten mit: npm run test:e2e:run')
	} catch (error) {
		console.error('Testserver-Start fehlgeschlagen:', error)
		console.error('Läuft Docker?')
		process.exit(1)
	}
}

main()
