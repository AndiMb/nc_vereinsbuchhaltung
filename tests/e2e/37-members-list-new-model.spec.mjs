import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Mitgliederliste auf dem Domänenmodell (Folge-Scope aus Issue #69): jede
// Zeile zeigt Mandat (Mandate) und Beitrag (Assignment) eines Mitglieds, wie
// sie der Aufnahme-Assistent und der CSV-Import anlegen (lib/memberRow.js).
// Seit dem Cutover (Issue #107) gibt es keinen Alt-Bestand mehr, den die Liste
// daneben führt: die Fälle „Altmitglied" und „Inline-Bearbeitung des
// Alt-Beitrags" entfallen, die Spalte „Nächste Fälligkeit" kommt aus den
// Forderungen.
//
// Die Listenzeile wird ausdrücklich über die SICHTBARE Tabelle gesucht: die
// Beitragsgruppen-Tabelle „Zuweisungen" hängt per v-show im DOM und führt
// dieselben Mitgliedsnamen.

const GROUP_NAME = 'Testgruppe Mitgliederliste'
const IBAN = 'DE02120300000000202051'

const today = () => new Date().toISOString().slice(0, 10)
/** Ein Jahr in der Zukunft – eine Zuweisung darf nicht in der Vergangenheit beginnen, wohl aber später. */
const nextYear = () => new Date(Date.now() + 365 * 24 * 3600 * 1000).toISOString().slice(0, 10)
// Die Oberfläche zeigt Daten als TT.MM.JJJJ.
const german = (isoDate) => isoDate.split('-').reverse().join('.')

async function enableMembership(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

async function ensureGroup(request) {
	const groups = await api.getJson(request, '/contribution-groups')
	let group = groups.find((g) => g.name === GROUP_NAME)
	if (!group) {
		group = await (await api.raw(request, 'POST', '/contribution-groups', {
			expectOk: true,
			data: { name: GROUP_NAME, minMonthlyAmount: 5, defaultMonthlyAmount: 10, allowedIntervals: [1, 3, 12], defaultInterval: 12, isActive: true },
		})).json()
	}
	return group
}

async function ensureMember(request, firstName, lastName, { email = `${firstName.toLowerCase()}@example.org` } = {}) {
	const displayName = `${firstName} ${lastName}`
	const existing = (await api.listMembers(request)).find((m) => m.displayName === displayName)
	return existing ?? api.createMember(request, { firstName, lastName, email })
}

/** Ein Mandat des neuen Modells; ohne `signedAt` bleibt es Entwurf, mit wird es sofort aktiv (wie im Aufnahme-Assistenten). */
async function createMandate(request, member, signedAt) {
	const mandate = await (await api.createMandate(request, { memberId: member.id, iban: IBAN, accountHolder: member.displayName, signedAt })).json()
	if (signedAt) { await api.activateMandate(request, mandate.id) }
}

async function createAssignment(request, member, group, { intervalMonths, monthlyAmount, paymentMethod = 'direct_debit', validFrom = today() }) {
	await api.raw(request, 'POST', '/assignments', {
		expectOk: true,
		data: { memberId: member.id, groupId: group.id, intervalMonths, monthlyAmount, paymentMethod, validFrom },
	})
}

/**
 * Sechs Mitglieder, die je einen Pfad des Adapters abdecken – nur anlegen,
 * was noch fehlt (siehe ensureMemberWithFee in 11-contributions.spec.mjs).
 */
async function ensureSeed(request) {
	if ((await api.listMembers(request)).some((m) => m.displayName === 'Anna Aktiv')) { return }
	const group = await ensureGroup(request)

	const anna = await ensureMember(request, 'Anna', 'Aktiv')
	await createMandate(request, anna, '2026-01-15')
	await createAssignment(request, anna, group, { intervalMonths: 12, monthlyAmount: 10 })

	const bernd = await ensureMember(request, 'Bernd', 'Entwurf')
	await createMandate(request, bernd, null)
	await createAssignment(request, bernd, group, { intervalMonths: 3, monthlyAmount: 5 })

	const cora = await ensureMember(request, 'Cora', 'Ueberweisung')
	await createAssignment(request, cora, group, { intervalMonths: 1, monthlyAmount: 8, paymentMethod: 'ueberweisung' })

	const dieter = await ensureMember(request, 'Dieter', 'Zukunft')
	await createAssignment(request, dieter, group, { intervalMonths: 12, monthlyAmount: 10, validFrom: nextYear() })

	const hans = await ensureMember(request, 'Hans', 'Ohnemandat')
	await createAssignment(request, hans, group, { intervalMonths: 12, monthlyAmount: 6 })

	await ensureMember(request, 'Frieda', 'Nichts', { email: null })
}

/** Zeile in der sichtbaren Mitglieder-Tabelle (nicht in der versteckten Zuweisungs-Tabelle der Beitragsgruppen). */
function memberRow(page, name) {
	return visibleSection(page).locator('table.vbh-table:visible tr', { hasText: name })
}

test.describe('Mitgliederliste zeigt Mandat und Beitrag des neuen Modells', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Zeilen zeigen IBAN, Betrag je Periode und Turnus statt „kein Mandat"', async ({ page, request }) => {
		await ensureSeed(request)
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')

		// Aktives Papier-Mandat + Zuweisung: 10 €/Monat, Turnus 12 → 120 € je Periode.
		const anna = memberRow(page, 'Anna Aktiv')
		await expect(anna).toContainText(IBAN)
		await expect(anna).not.toContainText('kein Mandat')
		await expect(anna).toContainText('120,00')
		await expect(anna).toContainText('jährlich')
		// Spalte „Aktiv": bei Zuweisungen Text statt Schalter (der Zeitraum ist der Status).
		await expect(anna.locator('td').nth(5)).toHaveText('aktiv')
		// Die Fälligkeit entsteht erst mit der Forderung – ohne Forderung bleibt die Spalte leer.
		await expect(anna.locator('td').nth(4)).toHaveText('–')
		// Zuweisungen werden nicht inline bearbeitet.
		await expect(anna.getByRole('button', { name: 'Beitrag bearbeiten' })).toHaveCount(0)
		await expect(anna.getByRole('button', { name: 'Zuweisung verwalten' })).toBeVisible()

		// Entwurfs-Mandat trägt die Marke, Turnus 3 → 3 × 5 € = 15 € je Quartal.
		const bernd = memberRow(page, 'Bernd Entwurf')
		await expect(bernd).toContainText(IBAN)
		await expect(bernd).toContainText('Entwurf')
		await expect(bernd).toContainText('15,00')
		await expect(bernd).toContainText('vierteljährlich')

		// Überweisung braucht kein Mandat: kein „kein Mandat", sondern der Zahlungsweg.
		const cora = memberRow(page, 'Cora Ueberweisung')
		await expect(cora).toContainText('Überweisung')
		await expect(cora).not.toContainText('kein Mandat')
		await expect(cora).toContainText('8,00')
		await expect(cora).toContainText('monatlich')

		// Künftig beginnende Zuweisung bleibt sichtbar, samt Startdatum.
		const dieter = memberRow(page, 'Dieter Zukunft')
		await expect(dieter).toContainText('120,00')
		await expect(dieter).toContainText(`ab ${german(nextYear())}`)

		// Ohne alles bleibt es bei „kein Mandat" und Strichen.
		const frieda = memberRow(page, 'Frieda Nichts')
		await expect(frieda).toContainText('kein Mandat')

		// Die Summenzeile über der Tabelle: /reset räumt die Mitglieder-Tabellen NICHT
		// mit ab (Mitglieder, Mandate und Zuweisungen anderer Specs bleiben stehen),
		// deshalb hier nur die Form – die Rechnung (Jahresaufkommen aller aktiven
		// Beiträge, künftige Zuweisungen zählen nicht) deckt memberRow.test.js ab.
		await expect(visibleSection(page).getByText(/\d+ von \d+ Mitgliedern · \d+ mit Mandat · Beitragsaufkommen [\d.,]+\s€ im Jahr/)).toBeVisible()
	})

	test('„Nächste Fälligkeit" zeigt die früheste noch fällige Forderung – stornierte zählen nicht', async ({ page, request }) => {
		await ensureSeed(request)
		const berndId = (await api.listMembers(request)).find((m) => m.displayName === 'Bernd Entwurf').id
		const claim = async (label, dueDate) => (await api.raw(request, 'POST', '/claims', {
			expectOk: true,
			data: { memberId: berndId, type: 'beitrag', amount: 15, label, dueDate },
		})).json()
		// Die früheste (2026-01-05) wird storniert, die nächste (2026-02-10) bleibt offen, die späteste liegt dahinter.
		const cancelled = await claim('Storniert (Fälligkeitsspalte)', '2026-01-05')
		await api.raw(request, 'POST', `/claims/${cancelled.id}/cancel`, { expectOk: true, data: { reason: 'Test' } })
		await claim('Offen früh (Fälligkeitsspalte)', '2026-02-10')
		await claim('Offen spät (Fälligkeitsspalte)', '2026-03-10')

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await expect(memberRow(page, 'Bernd Entwurf').locator('td').nth(4)).toHaveText('10.02.2026')
		// Ein Mitglied ohne Forderung behält den Strich.
		await expect(memberRow(page, 'Cora Ueberweisung').locator('td').nth(4)).toHaveText('–')
	})

	test('„nur Auffälligkeiten": Lastschrift ohne Mandat fällt auf, Überweisung nicht', async ({ page, request }) => {
		await ensureSeed(request)
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')

		// NcCheckboxRadioSwitch legt die sichtbare Beschriftung über das Eingabefeld: dort klicken, nicht ins Feld.
		await visibleSection(page).getByText('nur Auffälligkeiten', { exact: true }).click()
		await expect(visibleSection(page).getByLabel('nur Auffälligkeiten')).toBeChecked()
		await expect(memberRow(page, 'Hans Ohnemandat')).toBeVisible()
		await expect(memberRow(page, 'Frieda Nichts')).toBeVisible() // keine E-Mail
		await expect(memberRow(page, 'Anna Aktiv')).toHaveCount(0)
		await expect(memberRow(page, 'Cora Ueberweisung')).toHaveCount(0)
	})

	test('„Zuweisung verwalten" führt zu den Beitragsgruppen', async ({ page, request }) => {
		await ensureSeed(request)
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')

		await memberRow(page, 'Anna Aktiv').getByRole('button', { name: 'Zuweisung verwalten' }).click()
		await expect(visibleSection(page).getByRole('button', { name: '+ Zuweisung' })).toBeVisible()
	})

	test('Aufnahme-Assistent: das neu aufgenommene Mitglied erscheint sofort mit IBAN und Beitrag', async ({ page, request }) => {
		const group = await ensureGroup(request)

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Petra')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Assistentin')
		await dialog.getByLabel('E-Mail').fill('petra.assistentin@example.org')
		await dialog.getByLabel('IBAN', { exact: true }).fill(IBAN)
		await dialog.getByLabel('Mandat unterschrieben am').fill('2026-01-15')
		await dialog.getByLabel('Beitragsgruppe').selectOption({ label: group.name })
		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		// Standardbeitrag der Gruppe: 10 €/Monat, Turnus 12.
		const row = memberRow(page, 'Petra Assistentin')
		await expect(row).toContainText(IBAN)
		await expect(row).not.toContainText('kein Mandat')
		await expect(row).toContainText('120,00')
		await expect(row).toContainText('jährlich')
	})
})

test.describe('Mitgliederliste auf dem Handy', () => {
	test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('die Karten zeigen dieselben Werte wie die Tabelle, dazu die nächste Fälligkeit', async ({ page, request }) => {
		await ensureSeed(request)
		// Eine fällige Forderung für Anna: die Karte nennt dann ihre nächste Fälligkeit.
		const dueDate = new Date(Date.now() + 14 * 24 * 3600 * 1000).toISOString().slice(0, 10)
		const annaId = (await api.listMembers(request)).find((m) => m.displayName === 'Anna Aktiv').id
		await api.raw(request, 'POST', '/claims', {
			expectOk: true,
			data: { memberId: annaId, type: 'beitrag', amount: 120, label: 'Jahresbeitrag (Karte)', dueDate },
		})

		await openApp(page, USERS.buchhalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Beiträge' }).click()

		const anna = visibleSection(page).locator('.vbh-membercard', { hasText: 'Anna Aktiv' })
		await expect(anna).toContainText(IBAN)
		await expect(anna).not.toContainText('kein Mandat')
		await expect(anna).toContainText('120,00')
		await expect(anna).toContainText('jährlich')
		await expect(anna).toContainText(`fällig ${german(dueDate)}`)
		await expect(anna.getByRole('button', { name: 'Bearbeiten', exact: true })).toHaveCount(0)
		await expect(anna.getByRole('button', { name: 'Zuweisung verwalten' })).toBeVisible()

		const cora = visibleSection(page).locator('.vbh-membercard', { hasText: 'Cora Ueberweisung' })
		await expect(cora).toContainText('Überweisung')
		await expect(cora).not.toContainText('kein Mandat')
		await expect(cora).not.toContainText('fällig')
	})
})
