import { test, expect } from '@playwright/test'
import { api, openApp, switchTab, visibleSection, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, USERS } from './fixtures/nextcloud.mjs'

// Oberflächenpolitur rund um Mitglieder (Durchlauf Testprotokoll): was dort
// zuletzt an Aufnahme-Dialog, Mitgliederliste, Akte und CSV-Import
// nachgeschärft wurde, und was bei einem Merge leicht wieder verloren geht.
//
//  1. Aufnahme-Dialog: Abschnitte „SEPA-Mandat (optional)“ und „Beitrag
//     (optional)“, der Hinweis auf die Akte, Vorname sofort bedienbar.
//  2. „nur Auffälligkeiten“: Lastschrift mit bloßem Entwurfs-Mandat fällt auf.
//  3. Deutsche Datumsanzeige (TT.MM.JJJJ statt JJJJ-MM-TT) in der Liste und
//     in der Akte („Austritt zum …“).
//  4. Akte: Anonymisierung, Löschen und Austritt je genau einmal (Regression:
//     der Abschnitt „Anonymisierung“ stand nach einem Merge doppelt da) und
//     die Verknüpfung mit dem Nextcloud-Konto einmal.
//  5. CSV-Import: „Datei wählen“ als Knopf mit Dateiname daneben, die
//     aufklappbare Spaltenhilfe, Prüflauf mit Singular/Plural, Fehlerzeilen
//     und die Zusammenfassung in der Vergangenheit nach dem Übernehmen.
//
// Bewusst NICHT abgedeckt: der Status „beendet TT.MM.JJJJ“ in der Liste. Eine
// Zuweisung darf nicht in der Vergangenheit beginnen (AssignmentService::
// assertNotInPast) und gilt bis einschließlich ihres Endes noch als aktiv –
// beendet ist sie über die Oberfläche/API erst am Tag nach `validTo`, also nie
// innerhalb eines Testlaufs. Die Beschriftung deckt memberRow.test.js ab.
//
// Jeder Test legt seine Mitglieder mit eindeutigen Nachnamen an (/reset räumt
// Mitglieder, Mandate, Zuweisungen und Beitragsgruppen NICHT ab, ein
// Wiederholungslauf fände sonst zwei gleichnamige Zeilen). Was ein Mandat, eine
// Zuweisung oder eine Forderung trägt, lässt sich nicht löschen und bleibt
// stehen – der Zufallssuffix verhindert Kollisionen.
//
// HINWEIS: lokal nicht ausgeführt (Docker-Ports belegt), nur statisch gegen
// die Vue-Komponenten und die Fixture-Helfer geprüft; Läufer ist die CI.
// Aufbau und Selektoren folgen eng 22-modal-autofocus, 23-members,
// 29-onboarding-assistant, 37-members-list-new-model und 39-mandate-management.

const GROUP_NAME = 'Testgruppe Akte und Import'
const IBAN = 'DE02120300000000202051'

const today = () => new Date().toISOString().slice(0, 10)
/** Ein Jahr in der Zukunft – eine Zuweisung darf nicht in der Vergangenheit beginnen, wohl aber später. */
const inOneYear = () => new Date(Date.now() + 365 * 24 * 3600 * 1000).toISOString().slice(0, 10)
/** JJJJ-MM-TT → TT.MM.JJJJ, wie die Oberfläche es zeigt. */
const german = (iso) => iso.split('-').reverse().join('.')
const unique = () => Math.random().toString(36).slice(2, 8)

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

/** Ein Mitglied mit Mailadresse und eindeutigem Nachnamen (siehe Kopfkommentar). */
async function createMember(request, firstName, lastName, suffix) {
	const uniqueLastName = `${lastName}-${suffix}`
	return api.createMember(request, { firstName, lastName: uniqueLastName, email: `${firstName}.${uniqueLastName}@example.org`.toLowerCase() })
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

/** Zeile in der sichtbaren Mitglieder-Tabelle (nicht in der versteckten Zuweisungs-Tabelle der Beitragsgruppen). */
function memberRow(page, name) {
	return visibleSection(page).locator('table.vbh-table:visible tr', { hasText: name })
}

/** Öffnet die Akte über den Namen der Zeile (der eine Weg dorthin); liefert den Dialog. */
async function openAkte(page, member) {
	await openApp(page, USERS.buchhalter)
	await switchTab(page, 'Beiträge')
	await memberRow(page, member.displayName).getByRole('button', { name: 'Akte öffnen' }).click()
	const dialog = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
	await expect(dialog).toBeVisible()
	return dialog
}

/** Eine CSV-Datei für setInputFiles: Semikolon, CRLF, UTF-8. */
function csvFile(name, rows) {
	const header = 'Vorname;Nachname;Mitgliedsnummer;E-Mail;IBAN;Mandat am;Beitragsgruppe;Betrag;Frequenz;Start'
	return { name, mimeType: 'text/csv', buffer: Buffer.from([header, ...rows].join('\r\n'), 'utf-8') }
}

test.describe('Mitglieder: Aufnahme-Dialog, Liste, Akte und CSV-Import', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Aufnahme-Dialog: Abschnitte „SEPA-Mandat (optional)“ und „Beitrag (optional)“, Hinweis auf die Akte, Vorname sofort bedienbar', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()

		await expect(dialog.getByRole('heading', { name: 'SEPA-Mandat (optional)', exact: true })).toBeVisible()
		await expect(dialog.getByRole('heading', { name: 'Beitrag (optional)', exact: true })).toBeVisible()
		await expect(dialog.getByText('Beides lässt sich auch später in der Akte ergänzen.', { exact: true })).toBeVisible()

		// Der Fokus liegt schon beim Öffnen im Vornamen: ohne Klick tippen. Vorher den Fokus selbst
		// prüfen (siehe 22-modal-autofocus), sonst laufen Zeichen in der Lücke zwischen „sichtbar“
		// und „fokussiert“ ins Leere.
		const firstName = dialog.getByRole('textbox', { name: 'Vorname', exact: true })
		await expect(firstName).toBeFocused()
		await page.keyboard.type('Sofort')
		await expect(firstName).toHaveValue('Sofort')

		// Nichts anlegen: Abbrechen schließt den Dialog, ein Mitglied entsteht nicht.
		await dialog.getByRole('button', { name: 'Abbrechen' }).click()
		await expect(dialog).toBeHidden()
	})

	test('Land: eine Auswahl mit allen Ländern statt eines Textfelds, mit Vorgabe; gespeichert wird der Ländercode', async ({ page, request }) => {
		const nachname = `Landtest${Date.now() % 100000}`
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Mitglied', exact: true }).click()
		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()

		// Ein neues Mitglied beginnt im Land der Person, die die App bedient (nie leer).
		const land = dialog.getByRole('combobox', { name: 'Land' })
		await expect(land).toBeVisible()
		await expect(dialog.locator('.vbh-country-select .vs__selected')).not.toHaveText('')

		// Die Liste führt alle 249 Länder, in der Sprache der Oberfläche benannt; getippt wird gefiltert.
		await land.click()
		await expect(page.locator('li.vs__dropdown-option')).toHaveCount(249)
		await land.pressSequentially('Österreich', { delay: 20 })
		await page.locator('li.vs__dropdown-option', { hasText: 'Österreich' }).first().waitFor()
		await land.press('Enter')
		await expect(dialog.locator('.vbh-country-select .vs__selected')).toHaveText('Österreich')

		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill(nachname)
		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		// Gespeichert ist der zweistellige Code, nicht der Name.
		const stored = (await api.listMembers(request)).find((m) => m.lastName === nachname)
		expect(stored?.country).toBe('AT')
	})

	test('„nur Auffälligkeiten“: Lastschrift mit bloßem Entwurfs-Mandat fällt auf, mit aktivem Mandat nicht', async ({ page, request }) => {
		const group = await ensureGroup(request)
		const suffix = unique()
		// Beide Mitglieder haben eine Mailadresse und eine aktive Lastschrift-Zuweisung – sie
		// unterscheiden sich nur im Mandat. Die Nachnamen vermeiden bewusst das Wort „Entwurf“,
		// damit die Marke in der Zeile nicht schon vom Namen stammt.
		const draft = await createMember(request, 'Edda', 'Ohnedatum', suffix)
		await createMandate(request, draft, null)
		await createAssignment(request, draft, group, { intervalMonths: 12, monthlyAmount: 10 })
		const active = await createMember(request, 'Anton', 'Mitdatum', suffix)
		await createMandate(request, active, today())
		await createAssignment(request, active, group, { intervalMonths: 12, monthlyAmount: 10 })

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')

		// Ohne Filter stehen beide da – damit steht auch fest, dass die Liste geladen ist, bevor
		// unten das Verschwinden der einen Zeile etwas beweist.
		await expect(memberRow(page, draft.displayName)).toBeVisible()
		await expect(memberRow(page, active.displayName)).toBeVisible()
		await expect(memberRow(page, draft.displayName).getByText('Entwurf', { exact: true })).toBeVisible()

		// NcCheckboxRadioSwitch legt die sichtbare Beschriftung über das Eingabefeld: dort klicken, nicht ins Feld.
		await visibleSection(page).getByText('nur Auffälligkeiten', { exact: true }).click()
		await expect(visibleSection(page).getByLabel('nur Auffälligkeiten')).toBeChecked()
		await expect(memberRow(page, active.displayName)).toHaveCount(0)
		await expect(memberRow(page, draft.displayName)).toBeVisible()
	})

	test('Datumsanzeige deutsch: Fälligkeit und „ab TT.MM.JJJJ“ in der Liste, „Austritt zum TT.MM.JJJJ.“ in der Akte', async ({ page, request }) => {
		const group = await ensureGroup(request)
		const suffix = unique()
		const startDate = inOneYear()
		const dueDate = '2031-03-18'
		const leaveDate = '2031-07-23'

		// Eine erst künftig beginnende Zuweisung (Status „ab …“) und eine Forderung (Spalte „Nächste Fälligkeit“).
		const member = await createMember(request, 'Zora', 'Zukunft', suffix)
		await createAssignment(request, member, group, { intervalMonths: 12, monthlyAmount: 10, paymentMethod: 'ueberweisung', validFrom: startDate })
		await api.raw(request, 'POST', '/claims', {
			expectOk: true,
			data: { memberId: member.id, type: 'beitrag', amount: 15, label: `Jahresbeitrag (Datumsanzeige ${suffix})`, dueDate },
		})

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const row = memberRow(page, member.displayName)
		// Spalten wie in 37: 4 = „Nächste Fälligkeit“, 5 = Zuweisungsstatus.
		await expect(row.locator('td').nth(4)).toHaveText(german(dueDate))
		await expect(row.locator('td').nth(5)).toHaveText(`ab ${german(startDate)}`)
		await expect(row).not.toContainText(dueDate)
		await expect(row).not.toContainText(startDate)

		// Austritt erklären: die Akte nennt das Datum deutsch, nicht als JJJJ-MM-TT.
		await row.getByRole('button', { name: 'Akte öffnen' }).click()
		const dialog = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('Austrittsdatum').fill(leaveDate)
		await dialog.getByRole('button', { name: 'Austritt erklären' }).click()
		await expect(dialog.getByText(`Austritt zum ${german(leaveDate)}.`)).toBeVisible()
		await expect(dialog).not.toContainText(leaveDate)
		await expect(dialog.getByRole('button', { name: 'Austritt zurücknehmen' })).toBeVisible()
	})

	test('Akte: „Anonymisierung (Art. 17 DSGVO)“, „Löschen“ und „Austritt“ stehen genau einmal, die Kontoverknüpfung einmal', async ({ page, request }) => {
		const suffix = unique()
		const member = await createMember(request, 'Karla', 'Akte', suffix)
		// Die Verknüpfung gehört zu genau einem Mitglied: eine liegengebliebene (etwa aus einer
		// abgebrochenen Spec) vorher lösen, sonst weist link() das Konto als vergeben ab.
		const holder = (await api.listMembers(request)).find((m) => m.ncUserId === USERS.englisch)
		if (holder) { await api.unlinkMember(request, holder.id) }
		await api.linkMember(request, member.id, USERS.englisch)

		try {
			const dialog = await openAkte(page, member)

			// „Löschen“ ist der letzte Abschnitt der Akte: steht er da, ist alles darüber gerendert,
			// und die Zählungen unten sehen den fertigen Stand, nicht einen halben.
			await expect(dialog.getByRole('heading', { name: 'Löschen', exact: true })).toBeVisible()
			for (const heading of ['Anonymisierung (Art. 17 DSGVO)', 'Löschen', 'Austritt']) {
				await expect(dialog.getByRole('heading', { name: heading, exact: true }), `Abschnitt „${heading}“ genau einmal`).toHaveCount(1)
			}
			await expect(dialog.getByText(`Verknüpft mit „${USERS.englisch}".`)).toHaveCount(1)
		} finally {
			// Aufräumen: eine bleibende Verknüpfung würde dem Self-Service anderer Specs ein Mitglied unterschieben.
			// Das Mitglied selbst hat weder Mandat noch Zuweisung und lässt sich löschen.
			await api.unlinkMember(request, member.id)
			await api.raw(request, 'DELETE', `/members/${member.id}`)
		}
	})

	test('CSV-Import: Datei wählen, Spaltenhilfe, Prüflauf mit Singular/Plural und Fehlerzeilen, Zusammenfassung in der Vergangenheit', async ({ page, request }) => {
		test.setTimeout(60000)
		const group = await ensureGroup(request)
		const suffix = unique()
		// Der Beginn einer Zuweisung darf nicht in der Vergangenheit liegen: heute (UTC, wie der Server) ist immer zulässig.
		const heute = german(today())
		const validRow = (firstName, lastName, number) => {
			const email = `${firstName}.${lastName}@example.org`.toLowerCase()
			return `${firstName};${lastName};${number};${email};${IBAN};${heute};${group.name};9,00;monatlich;${heute}`
		}

		const okName = `Importiert-${suffix}`
		const mailName = `Mailfehler-${suffix}`
		const ibanName = `IbanOhneDatum-${suffix}`
		// Zwei gültige Zeilen für den Plural, eine gültige und zwei fehlerhafte für Singular, Fehler und Übernahme.
		const pluralFile = csvFile(`plural-${suffix}.csv`, [
			validRow('Paula', `Plural-${suffix}`, `IMP-${suffix}-P1`),
			validRow('Paul', `Plural2-${suffix}`, `IMP-${suffix}-P2`),
		])
		const mainFile = csvFile(`mitglieder-${suffix}.csv`, [
			validRow('Ida', okName, `IMP-${suffix}-1`),
			`Emil;${mailName};IMP-${suffix}-2;das-ist-keine-mail;;;;;;`,
			`Olga;${ibanName};IMP-${suffix}-3;olga.${suffix}@example.org;${IBAN};;;;;`,
		])

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await visibleSection(page).getByRole('button', { name: 'Liste einlesen', exact: true }).click()

		const dialog = page.getByRole('dialog', { name: 'Mitgliederliste einlesen' })
		await expect(dialog).toBeVisible()
		const pick = dialog.locator('.vbh-importpick')
		const fileName = pick.locator('.vbh-filename')
		const checkButton = dialog.getByRole('button', { name: 'Prüfen', exact: true })
		const fileInput = dialog.locator('input[type="file"]')

		await test.step('„Datei wählen“ ist ein Knopf, das Dateifeld bleibt unsichtbar', async () => {
			await expect(pick.getByRole('button', { name: 'Datei wählen', exact: true })).toBeVisible()
			await expect(fileInput).toHaveCount(1)
			await expect(fileInput).toBeHidden()
			await expect(fileName).toHaveText('keine Datei gewählt')
			await expect(checkButton).toBeDisabled()
		})

		await test.step('„Welche Spalten gibt es?“ klappt auf und wieder zu', async () => {
			const help = dialog.locator('details.vbh-importhelp')
			await expect(help.locator('summary')).toHaveText('Welche Spalten gibt es?')
			await expect(help).toHaveJSProperty('open', false)
			await help.locator('summary').click()
			await expect(help).toHaveJSProperty('open', true)
			await expect(help.getByText('Reihenfolge und Schreibweise der Überschriften sind egal', { exact: false })).toBeVisible()
			await help.locator('summary').click()
			await expect(help).toHaveJSProperty('open', false)
		})

		await test.step('Prüflauf, Plural: zwei gültige Zeilen', async () => {
			await fileInput.setInputFiles(pluralFile)
			await expect(fileName).toHaveText(pluralFile.name)
			await checkButton.click()

			await expect(dialog.getByText('2 von 2 Zeilen sind in Ordnung: 2 Mandate und 2 Zuweisungen würden angelegt', { exact: false })).toBeVisible()
			// Die Bestätigung und der Knopf zählen mit.
			await expect(dialog.getByText('Die unterschriebenen Mandate für 2 Zeilen liegen vor – sie werden sofort aktiviert.', { exact: true })).toBeVisible()
			await expect(dialog.getByRole('button', { name: '2 Zeilen übernehmen', exact: true })).toBeVisible()
		})

		await test.step('Prüflauf, Singular: eine gültige Zeile, zwei Fehlerzeilen mit Meldung', async () => {
			// Eine neue Datei verwirft den Prüflauf der alten (Dateiname, Vorschau und Bestätigung).
			await fileInput.setInputFiles(mainFile)
			await expect(fileName).toHaveText(mainFile.name)
			await checkButton.click()

			await expect(dialog.getByText('1 von 3 Zeilen sind in Ordnung: 1 Mandat und 1 Zuweisung würden angelegt', { exact: false })).toBeVisible()
			await expect(dialog.getByText('Das unterschriebene Mandat liegt vor – es wird sofort aktiviert.', { exact: true })).toBeVisible()
			await expect(dialog.getByRole('button', { name: '1 Zeile übernehmen', exact: true })).toBeVisible()

			// Je Zeile ein Status-Chip: die gültige sagt, was entsteht, die fehlerhaften „Fehler“ samt Meldung.
			const okRow = dialog.locator('tr', { hasText: okName })
			await expect(okRow.getByText('Mandat und Zuweisung', { exact: true })).toBeVisible()
			await expect(okRow.getByText('Fehler', { exact: true })).toHaveCount(0)

			const mailRow = dialog.locator('tr', { hasText: mailName })
			await expect(mailRow.getByText('Fehler', { exact: true })).toBeVisible()
			await expect(mailRow.getByText('Keine gültige E-Mail-Adresse: das-ist-keine-mail', { exact: false })).toBeVisible()
			await expect(mailRow.getByText('Mandat und Zuweisung', { exact: true })).toHaveCount(0)

			const ibanRow = dialog.locator('tr', { hasText: ibanName })
			await expect(ibanRow.getByText('Fehler', { exact: true })).toBeVisible()
			await expect(ibanRow.getByText('Zu einer IBAN gehört das Datum', { exact: false })).toBeVisible()
		})

		await test.step('Übernehmen: Bestätigung, dann die Zusammenfassung in der Vergangenheit', async () => {
			const runButton = dialog.getByRole('button', { name: '1 Zeile übernehmen', exact: true })
			// Die Bestätigung ist eine NcCheckboxRadioSwitch: auf die Beschriftung klicken, nicht ins versteckte Feld.
			const confirmCheckbox = dialog.getByRole('checkbox', { name: 'Mandat liegt vor', exact: false })
			await expect(confirmCheckbox).not.toBeChecked()
			await expect(runButton).toBeDisabled()
			// Die Bestätigung gehört zum Knopf: sie steht unmittelbar darüber, nicht oberhalb der Tabelle.
			const confirmText = dialog.getByText('Mandat liegt vor – es wird sofort aktiviert', { exact: false })
			const confirmBox = await confirmText.boundingBox()
			const runBox = await runButton.boundingBox()
			expect(confirmBox.y).toBeLessThan(runBox.y)
			expect(runBox.y - (confirmBox.y + confirmBox.height)).toBeLessThan(100)
			await confirmText.click()
			await expect(confirmCheckbox).toBeChecked()
			await expect(runButton).toBeEnabled()
			await runButton.click()

			const confirmDialog = page.getByRole('dialog', { name: 'Mitglieder übernehmen' })
			await expect(confirmDialog).toBeVisible()
			await confirmDialog.getByRole('button', { name: 'Übernehmen', exact: true }).click()

			// Der Dialog schließt sich nicht selbst, sondern zeigt das Ergebnis – darauf warten, sonst
			// fragt die API-Prüfung unten vor dem Ende des Imports ab.
			await expect(dialog.getByText('1 von 3 Zeilen übernommen: 1 Mandat und 1 Zuweisung angelegt', { exact: false })).toBeVisible()
			await expect(dialog.getByText('würden angelegt', { exact: false })).toHaveCount(0)
			await expect(dialog.getByRole('button', { name: /Zeilen? übernehmen/ })).toHaveCount(0)
			await expect(dialog.getByText('Mandat liegt vor', { exact: false })).toHaveCount(0)

			// Die Zeilen zeigen jetzt das Ergebnis; die fehlerhaften bleiben, was sie waren.
			await expect(dialog.locator('tr', { hasText: okName }).getByText('Mandat und Zuweisung angelegt', { exact: true })).toBeVisible()
			await expect(dialog.locator('tr', { hasText: mailName }).getByText('Fehler', { exact: true })).toBeVisible()
			await expect(dialog.locator('tr', { hasText: ibanName }).getByText('Fehler', { exact: true })).toBeVisible()
		})

		// Entstanden ist genau das Mitglied der gültigen Zeile – mit aktivem Mandat; die Fehlerzeilen und
		// der nur geprüfte Plural-Lauf haben nichts angelegt.
		const created = (await api.listMembers(request)).filter((m) => m.displayName.includes(`-${suffix}`))
		expect(created.map((m) => m.displayName)).toEqual([`Ida ${okName}`])
		const mandates = await api.mandatesByMember(request, created[0].id)
		expect(mandates).toHaveLength(1)
		expect(mandates[0].status).toBe('aktiv')
	})
})
