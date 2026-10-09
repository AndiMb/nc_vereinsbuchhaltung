import { describe, expect, it } from 'vitest'
import { collectEmails } from './memberEmails.js'

const row = (email, active = true) => ({ email, member: { active } })

describe('collectEmails', () => {
	it('sammelt die Adressen in der Reihenfolge der Zeilen', () => {
		expect(collectEmails([row('a@example.org'), row('b@example.org')]).addresses).toEqual(['a@example.org', 'b@example.org'])
	})

	it('nimmt jede Adresse nur einmal, auch bei anderer Schreibweise', () => {
		const { addresses } = collectEmails([row('familie@example.org'), row('Familie@Example.org'), row(' familie@example.org ')])
		expect(addresses).toEqual(['familie@example.org'])
	})

	it('lässt ausgetretene Mitglieder aus, ohne sie als „ohne E-Mail“ zu zählen', () => {
		expect(collectEmails([row('alt@example.org', false), row(null, false)])).toEqual({ addresses: [], withoutEmail: 0 })
	})

	it('zählt aktive Mitglieder ohne Adresse', () => {
		expect(collectEmails([row('a@example.org'), row(null), row(''), row('   ')])).toEqual({ addresses: ['a@example.org'], withoutEmail: 3 })
	})

	it('liefert bei einer leeren Liste nichts', () => {
		expect(collectEmails([])).toEqual({ addresses: [], withoutEmail: 0 })
	})
})
