import { beforeEach, describe, expect, it, vi } from 'vitest'

// useSync erkennt, ob sich der Datenbestand seit dem letzten Abgleich geaendert
// hat. Interessant ist dabei nicht der Vergleich selbst, sondern der Zeitstempel
// syncChangedSince: an ihm haengt in App.vue die Entscheidung, ob die Meldung
// "von einer anderen Person geaendert" erscheint. Eine feste Frist reichte dafuer
// nicht - der Abgleich laeuft nur alle 20 Sekunden und wird waehrend eines
// laufenden Imports (busy) zusaetzlich aufgeschoben, sodass die eigene Aktion
// laenger zurueckliegen kann als jede plausible Frist.

const revision = vi.fn()
vi.mock('../api.js', () => ({ default: { revision: () => revision() } }))

const { useSync } = await import('./useSync.js')
const { state, checkRemoteRevision } = useSync()

/** Server meldet diesen Stand beim naechsten Abgleich. */
function serverStand(rev) {
	revision.mockResolvedValue({ data: { revision: rev } })
}

describe('checkRemoteRevision', () => {
	beforeEach(() => {
		state.syncRevision = null
		state.syncSeenAt = 0
		state.syncChangedSince = 0
	})

	it('merkt sich den Stand beim ersten Abgleich, ohne eine Aenderung zu melden', async () => {
		serverStand('a')
		expect(await checkRemoteRevision(true, false)).toBe('unchanged')
		expect(state.syncRevision).toBe('a')
		expect(state.syncSeenAt).toBeGreaterThan(0)
	})

	it('meldet einen abweichenden Stand als Aenderung', async () => {
		serverStand('a')
		await checkRemoteRevision(true, false)
		serverStand('b')
		expect(await checkRemoteRevision(false, false)).toBe('changed')
		expect(state.syncRevision).toBe('b')
	})

	it('schiebt die Aenderung auf, solange die App beschaeftigt ist', async () => {
		serverStand('a')
		await checkRemoteRevision(true, false)
		serverStand('b')
		expect(await checkRemoteRevision(false, true)).toBe('busy')
		// Stand *nicht* uebernommen: der naechste Abgleich soll erneut anschlagen.
		expect(state.syncRevision).toBe('a')
		expect(await checkRemoteRevision(false, false)).toBe('changed')
	})

	it('haelt in syncChangedSince fest, seit wann der Stand ungeprueft ist', async () => {
		serverStand('a')
		await checkRemoteRevision(true, false)
		const nachErstemAbgleich = state.syncSeenAt

		// Waehrend eines laufenden Imports faellt der Abgleich zweimal aus.
		serverStand('b')
		await checkRemoteRevision(false, true)
		await checkRemoteRevision(false, true)
		expect(state.syncSeenAt).toBe(nachErstemAbgleich)

		await checkRemoteRevision(false, false)
		// Alles, was seit dem letzten bestaetigten Gleichstand geschrieben wurde,
		// kann die Abweichung erklaeren - genau diesen Zeitpunkt braucht App.vue.
		expect(state.syncChangedSince).toBe(nachErstemAbgleich)
		expect(state.syncSeenAt).toBeGreaterThanOrEqual(nachErstemAbgleich)
	})

	it('meldet einen Fehler des Servers als solchen', async () => {
		revision.mockRejectedValue(new Error('offline'))
		expect(await checkRemoteRevision(true, false)).toBe('error')
	})
})
