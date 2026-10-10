import { defineConfig } from 'vitest/config'

// Vitest deckt die zustandslosen Module unter src/ ab, dazu den Abgleich der
// Übersetzungen unter tests/l10n/ (er liest Quelltexte und l10n/*.json, braucht
// aber keine Instanz). Ohne diese Eingrenzung matcht sein Standard-Suchmuster
// (**/*.spec.*) auch die Playwright-Specs unter tests/e2e/ – die gehören aber
// zu `npm run test:e2e:run` und lassen sich von Vitest gar nicht ausführen.
export default defineConfig({
	test: {
		include: ['src/**/*.test.js', 'tests/l10n/**/*.test.js'],
	},
})
