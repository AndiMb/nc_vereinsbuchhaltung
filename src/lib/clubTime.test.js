import { afterEach, describe, expect, it, vi } from 'vitest'
import { clubTimeZone, clubToday, resetClubTimeZoneForTests, setClubTimeZone } from './clubTime.js'

const page = { me: null }
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((app, key, fallback) => (key in page && page[key] !== null ? page[key] : fallback)),
}))

afterEach(() => {
	resetClubTimeZoneForTests()
	page.me = null
	vi.useRealTimers()
})

describe('clubToday() – Issue #263', () => {
	it('23:30 Uhr UTC ist in Europe/Berlin schon der nächste Tag', () => {
		expect(clubToday(new Date('2026-11-01T23:30:00Z'), 'Europe/Berlin')).toBe('2026-11-02')
		// Sommerzeit: schon ab 22:00 Uhr UTC
		expect(clubToday(new Date('2026-06-30T22:30:00Z'), 'Europe/Berlin')).toBe('2026-07-01')
	})

	it('vor Mitternacht der Vereinszone bleibt es beim Tag', () => {
		expect(clubToday(new Date('2026-11-01T22:30:00Z'), 'Europe/Berlin')).toBe('2026-11-01')
	})

	it('nimmt ohne Angabe die Zone aus der Serverantwort', () => {
		setClubTimeZone('America/New_York')
		expect(clubTimeZone()).toBe('America/New_York')
		expect(clubToday(new Date('2026-07-01T02:30:00Z'))).toBe('2026-06-30')
	})

	it('liest die Zone aus dem Initial State `me`, solange keine Antwort da ist', () => {
		page.me = { clubTimeZone: 'Pacific/Kiritimati' }
		expect(clubTimeZone()).toBe('Pacific/Kiritimati')
	})

	it('fällt ohne oder bei unbekannter Zone auf Europe/Berlin zurück', () => {
		expect(clubTimeZone()).toBe('Europe/Berlin')
		setClubTimeZone('Mars/Olympus')
		expect(clubTimeZone()).toBe('Europe/Berlin')
		expect(clubToday(new Date('2026-11-01T23:30:00Z'))).toBe('2026-11-02')
		expect(clubToday(new Date('2026-11-01T23:30:00Z'), 'Mars/Olympus')).toBe('2026-11-02')
	})

	it('Standardzeitpunkt ist jetzt', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-12-31T23:30:00Z'))
		expect(clubToday()).toBe('2027-01-01')
	})
})
