import { expect, test } from '@playwright/test'
import { api, USERS } from '../e2e/fixtures/nextcloud.mjs'

// Schritt 3 des Upgrade-Tests: nach `occ upgrade` auf den Stand des Pull Requests.
// Die Auslagen aus seed.spec.mjs müssen unverändert da sein, jede hat eine Nummer
// (die Migration vergibt sie nachträglich: je Jahr von 1 an, nach Einreichung), und
// der Nummernkreis läuft hinter der letzten Nummer weiter.

const YEAR = String(new Date().getFullYear())

test('nach dem Upgrade tragen die Auslagen ihre Nummern und behalten Zustand und Daten', async ({ request }) => {
	const items = (await (await api.listReimbursements(request)).json()).items
		.slice()
		.sort((a, b) => a.id - b.id)

	expect(items.map((i) => i.purpose)).toEqual(['Eingereicht', 'Teilweise freigegeben', 'Freigegeben'])
	expect(items.map((i) => i.status)).toEqual(['submitted', 'partially_approved', 'approved'])
	expect(items.map((i) => i.number)).toEqual([`AUSL-${YEAR}-0001`, `AUSL-${YEAR}-0002`, `AUSL-${YEAR}-0003`])
	expect(items.every((i) => i.transferredAt === null)).toBe(true)

	// Verschlüsselte IBAN, Historie und Hash-Kette haben das Upgrade überstanden.
	const approved = await (await api.getReimbursement(request, items[2].id)).json()
	expect(approved.iban).toBe('DE89370400440532013000')
	expect(approved.history.map((e) => e.action)).toEqual(['submitted', 'approved', 'approved'])
	approved.history.forEach((entry, i) => {
		expect(entry.seq).toBe(i + 1)
		expect(entry.prevHash).toBe(i === 0 ? null : approved.history[i - 1].hash)
	})
})

test('nach dem Upgrade vergibt der Nummernkreis die nächste Nummer und lässt sich einstellen', async ({ request }) => {
	const range = await api.reimbursementNumberRange(request)
	expect(range).toMatchObject({ kind: 'auslage', prefixPattern: 'AUSL-{JJJJ}-{NNNN}', nextNo: 4 })

	const fresh = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Nach dem Upgrade', amount: '5.00' })).json()
	expect(fresh.number).toBe(`AUSL-${YEAR}-0004`)

	// Der neue Antrag lässt sich wie jeder andere freigeben und vormerken.
	await api.approveReimbursement(request, fresh.id, { user: USERS.admin })
	await api.approveReimbursement(request, fresh.id, { user: USERS.verwalter })
	const transferred = await (await api.markReimbursementTransferred(request, fresh.id)).json()
	expect(transferred.transferredAt).not.toBeNull()
})
