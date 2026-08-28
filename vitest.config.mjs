import { defineConfig } from 'vitest/config'

// Vitest deckt die zustandslosen Module unter src/ ab. Ohne diese Eingrenzung
// matcht sein Standard-Suchmuster (**/*.spec.*) auch die Playwright-Specs
// unter tests/e2e/ – die gehören aber zu `npm run test:e2e:run` und lassen
// sich von Vitest gar nicht ausführen.
export default defineConfig({
	test: {
		include: ['src/**/*.test.js'],
	},
})
