import { defineConfig, devices } from '@playwright/test'
import { BASE_URL } from './tests/e2e/fixtures/nextcloud.mjs'

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
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
})
