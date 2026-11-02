import { describe, expect, it, vi } from 'vitest'
import { takeBootState } from './bootState.js'

// loadState() liest ein verstecktes Feld der Seite; ohne Nextcloud-Seite genügt
// eine Tabelle. Wie die echte Bibliothek liefert der Nachbau bei jedem Aufruf
// denselben Wert – das „nur einmal“ muss takeBootState() selbst sicherstellen.
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((app, key, fallback) => {
		const page = { me: { role: 'revisor' }, periods: [{ id: 1 }] }
		return app === 'vereinsbuchhaltung' && key in page ? page[key] : fallback
	}),
}))

describe('takeBootState()', () => {
	it('liefert den Startwert einmal, danach null', () => {
		expect(takeBootState('me')).toEqual({ role: 'revisor' })
		expect(takeBootState('me')).toBeNull()
	})

	it('zählt je Schlüssel getrennt', () => {
		expect(takeBootState('periods')).toEqual([{ id: 1 }])
		expect(takeBootState('periods')).toBeNull()
	})

	it('liefert null, wenn die Seite den Wert nicht mitbringt (Einstellungsseite, fehlende Leserolle)', () => {
		expect(takeBootState('settings')).toBeNull()
	})
})
