import { beforeEach, describe, expect, it, vi } from 'vitest'
import { FOLDER_PANEL_KEY, opensFolderPanel, readFolderPanelOpen, writeFolderPanelOpen } from './folderPanelMemory.js'

function fakeStorage() {
	const data = new Map()
	return {
		getItem: (k) => (data.has(k) ? data.get(k) : null),
		setItem: (k, v) => { data.set(k, String(v)) },
	}
}

describe('folderPanelMemory', () => {
	beforeEach(() => {
		vi.stubGlobal('localStorage', fakeStorage())
	})

	it('ohne gemerkte Wahl bleibt die Leiste zu', () => {
		expect(readFolderPanelOpen()).toBe(false)
	})

	it('merkt sich offen und zu', () => {
		writeFolderPanelOpen(true)
		expect(localStorage.getItem(FOLDER_PANEL_KEY)).toBe('1')
		expect(readFolderPanelOpen()).toBe(true)
		writeFolderPanelOpen(false)
		expect(readFolderPanelOpen()).toBe(false)
	})

	it('gesperrter Speicher: lesen liefert zu, schreiben wirft nicht', () => {
		vi.stubGlobal('localStorage', {
			getItem: () => { throw new Error('SecurityError') },
			setItem: () => { throw new Error('QuotaExceededError') },
		})
		expect(readFolderPanelOpen()).toBe(false)
		expect(() => writeFolderPanelOpen(true)).not.toThrow()
	})
})

describe('opensFolderPanel', () => {
	const ok = { remembered: true, watchMode: true, canWrite: true, bookingLocked: false, isMobile: false }

	it('öffnet am Desktop, wenn gemerkt und erlaubt', () => {
		expect(opensFolderPanel(ok)).toBe(true)
	})

	it.each([
		['nicht gemerkt', { remembered: false }],
		['kein Wächter-Ordner', { watchMode: false }],
		['ohne Schreibrecht', { canWrite: false }],
		['Buchung gesperrt', { bookingLocked: true }],
		['mobil (Leiste ersetzt das Formular)', { isMobile: true }],
	])('bleibt zu: %s', (_label, patch) => {
		expect(opensFolderPanel({ ...ok, ...patch })).toBe(false)
	})
})
