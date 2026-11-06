import { defineConfig } from '@playwright/test'

// Playwright-Konfiguration des Upgrade-Tests (tests/e2e-migration/, CI-Job "Migration").
//
// Getrennt von der E2E-Suite, weil sie den Zustand zwischen zwei Läufen braucht:
// kein Snapshot-Rücksetzen vor dem Lauf (das Global-Setup der Suite würde den
// Altbestand wegspielen), keine Wiederholung (ein zweiter Versuch träfe auf
// halb verändertes Bestand) und nur API-Aufrufe, also kein Browser.
export default defineConfig({
	testDir: './tests/e2e-migration',
	timeout: 60 * 1000,
	forbidOnly: !!process.env.CI,
	retries: 0,
	workers: 1,
	reporter: [['list']],
})
