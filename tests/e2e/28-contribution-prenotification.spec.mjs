import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { test, expect } from '@playwright/test'
import { api, USERS } from './fixtures/nextcloud.mjs'

// Einzugszyklus-Cron, Vorabinfo-Versand-Durchlauf (Issue #70, Spec §3.5/§3.11):
// eine faellige Forderung bekommt beim taeglichen Cron ihre Vorabinfo-Mail
// und wird damit gesperrt (EffectivityRuleService greift ab jetzt scharf,
// siehe ContributionPreNotificationService-Klassendoc). Der Job wird wie in
// 17-watchfolder.spec.mjs ueber `occ background-job:execute` angestossen,
// nicht in Echtzeit abgewartet.
//
// Bewusst eine MANUELLE Einzelforderung (Issue #68 ClaimService::createManual)
// statt einer per Terminplan generierten: ihr Faelligkeitsdatum ist frei
// waehlbar ("heute + 3 Tage"), unabhaengig vom tatsaechlichen Kalendertag
// beim Testlauf sicher innerhalb der 14-Tage-Standardvorlauffrist. Eine ueber
// den Terminplan generierte Forderung haette ein vom Beitragsjahr-Raster
// abhaengiges Datum, das sich nicht ohne Zeitreise-Fixture (die es fuer E2E
// nicht gibt, anders als ITimeFactory in PHPUnit) zuverlaessig auf "bald
// faellig" bringen liesse.
test.describe('Einzugszyklus: Vorabinfo-Versand', () => {
	let memberId
	let claimId

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.updateSettings(request, { membership_enabled: '1', club_name: 'Testverein e.V.' })

		const member = await api.createMember(request, {
			firstName: 'Vera',
			lastName: 'Vorabinfo',
			email: 'vera.vorabinfo@example.org',
		})
		memberId = member.id

		const mandateResp = await api.createMandate(request, {
			memberId,
			iban: 'DE12500105170648489890',
			signedAt: '2024-01-01',
		})
		const mandate = await mandateResp.json()
		await api.activateMandate(request, mandate.id)

		const dueDate = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10)
		const claimResp = await api.raw(request, 'POST', '/claims', {
			data: { memberId, type: 'beitrag', amount: 12.5, label: 'Testbeitrag Vorabinfo', dueDate },
		})
		expect(claimResp.ok()).toBeTruthy()
		const claim = await claimResp.json()
		claimId = claim.id
		expect(claim.prenotifiedAt).toBeNull()
	})

	test('Cron verschickt die Vorabinfo und setzt prenotifiedAt, idempotent bei erneutem Lauf', async ({ request }) => {
		test.setTimeout(60000)

		const container = getContainer()
		const { stdout } = await runOcc(['background-job:list', '--output', 'json'], { container })
		const job = JSON.parse(stdout).find((j) => (j.class || '').includes('ContributionDueCycleJob'))
		expect(job, 'ContributionDueCycleJob ist registriert').toBeTruthy()
		await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })

		const claims = await api.getJson(request, '/claims')
		const claimAfterFirstRun = claims.find((c) => c.id === claimId)
		expect(claimAfterFirstRun.prenotifiedAt).not.toBeNull()

		// Idempotenz: ein zweiter Lauf am selben Tag verschickt nichts erneut -
		// prenotifiedAt bleibt unveraendert, kein Fehler.
		await runOcc(['background-job:execute', String(job.id), '--force-execute'], { container })
		const claimsAfterSecondRun = await api.getJson(request, '/claims')
		const claimAfterSecondRun = claimsAfterSecondRun.find((c) => c.id === claimId)
		expect(claimAfterSecondRun.prenotifiedAt).toBe(claimAfterFirstRun.prenotifiedAt)
	})

	test('Die Vorwarn-Aufgabe listet den bevorstehenden Lauf', async ({ request }) => {
		const tasks = await api.getJson(request, '/tasks', { user: USERS.buchhalter })
		const upcoming = tasks.find((t) => typeof t.message === 'string' && t.message.startsWith('Nächster Lauf am'))
		expect(upcoming, 'Vorwarn-Aufgabe (D-21) vorhanden').toBeTruthy()
	})
})
