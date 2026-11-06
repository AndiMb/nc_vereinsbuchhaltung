import { test } from '@playwright/test'
import { api, USERS } from '../e2e/fixtures/nextcloud.mjs'

// Schritt 1 des Upgrade-Tests: legt auf dem ALTEN App-Stand (Basis des Pull
// Requests) Auslagen in verschiedenen Zuständen an. Danach spielt der CI-Job den
// neuen Code ein und lässt `occ upgrade` laufen; verify.spec.mjs prüft das Ergebnis.

test('Altbestand: drei Auslagen in verschiedenen Zuständen', async ({ request }) => {
	test.setTimeout(120000)
	await api.setRole(request, USERS.buchhalter, 'verwalter')
	await api.unlinkMemberByNcUser(request, USERS.ohneRolle)
	const member = await api.createMember(request, { firstName: 'Erika', lastName: 'Altbestand', email: 'erika.altbestand@example.org' })
	await api.linkMember(request, member.id, USERS.ohneRolle)
	await api.updateSettings(request, { reimbursement_enabled: '1', reimbursement_required_approvals: '2' })

	const submitted = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Eingereicht', amount: '10.00' })).json()
	const partial = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Teilweise freigegeben', amount: '20.00' })).json()
	await api.approveReimbursement(request, partial.id, { user: USERS.admin })
	const approved = await (await api.submitReimbursement(request, { user: USERS.ohneRolle, purpose: 'Freigegeben', amount: '30.00' })).json()
	await api.approveReimbursement(request, approved.id, { user: USERS.admin })
	await api.approveReimbursement(request, approved.id, { user: USERS.verwalter })

	const items = (await (await api.listReimbursements(request)).json()).items
	if (items.length !== 3 || !submitted.id) {
		throw new Error(`Erwartet drei Auslagen im Altbestand, gefunden: ${items.length}`)
	}
})
