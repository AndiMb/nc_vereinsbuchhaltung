import { defineConfig, devices } from '@playwright/test'
import { BASE_URL, E2E_TIME_ZONE } from './tests/e2e/fixtures/nextcloud.mjs'

// Auch lokale Datumsrechnung im Testprozess (getDate(), getMonth()) in der
// Zone der Suite, nicht in der des Entwicklerrechners; die Worker erben die
// Variable. Warum eine Zone für alles: E2E_TIME_ZONE in fixtures/nextcloud.mjs.
process.env.TZ = E2E_TIME_ZONE

// Playwright-Konfiguration der E2E-Suite (tests/e2e/).
//
// Bewusst EIN Worker und keine Parallelität: anders als Apps mit
// isolierten Datensätzen teilt sich die Vereinsbuchhaltung EINEN
// Buchungsbestand (Application::BOOK) über alle Nutzer. Parallel laufende
// Specs würden sich denselben Bestand unter den Füßen wegändern –
// insbesondere Reset, Jahresabschluss und der Beispielverein wirken global.
// Jede Spec-Datei setzt sich ihren Bestand deshalb selbst auf
// (resetBook() im beforeAll) und verlässt sich nicht auf Vorgänger.
export default defineConfig({
	testDir: './tests/e2e',
	timeout: 30 * 1000,
	globalSetup: './tests/e2e/setup/global-setup.mjs',
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: [
		['html'],
		['list'],
	],
	use: {
		...devices['Desktop Chrome'],
		baseURL: BASE_URL,
		timezoneId: E2E_TIME_ZONE,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
})
