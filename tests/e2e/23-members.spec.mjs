import { expect, test } from '@playwright/test'
import { setMemberLinkToken } from './fixtures/member-link.mjs'
import { api, authHeaders, BANK_ACCOUNT, BANK_ACCOUNT_IBAN, BASE_URL, neuAnlegen, openApp, switchTab, USERS, visibleSection } from './fixtures/nextcloud.mjs'

// Mitglied-Entity (Issue #65, docs/beitraege-sepa-modul-spec.md §2.2/§3.1):
// Stammdaten-CRUD unabhängig von einem SEPA-Mandat, dazu die NC-Konto-
// verknüpfung als reiner Vorschlag – erst ein ausdrücklicher Klick auf
// "Verknüpfen" setzt sie, die Mailadresse allein wählt nichts vorab aus.

async function enableMembership(request) {
	const bank = await api.accountByNumber(request, BANK_ACCOUNT)
	// Das einziehende Konto braucht eine IBAN, sonst weist die
	// Einstellungs-Prüfung das Konto als SEPA-Einzugskonto ab.
	await api.updateAccount(request, bank.id, { iban: BANK_ACCOUNT_IBAN })
	await api.updateSettings(request, {
		membership_enabled: '1',
		club_name: 'Testverein e.V.',
		sepa_creditor_id: 'DE98ZZZ09999999999',
		sepa_debtor_account_id: bank.id,
	})
}

test.describe('Mitglieder-Stammdaten', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		await enableMembership(request)
	})

	test('Mitglied ohne Bankverbindung anlegen', async ({ page }) => {
		// Erreichbar ab Buchhalter, nicht erst ab Verwalter (Spec §3.9) – die
		// Personenakte zeigt unmaskierte Kontaktdaten, anders als der
		// Einzug-Unterreiter.
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await neuAnlegen(page, 'Mitglied')

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('textbox', { name: 'Vorname', exact: true }).fill('Neu')
		await dialog.getByRole('textbox', { name: 'Nachname', exact: true }).fill('Angelegt')
		// Bewusst ohne IBAN/Betrag: ein Mitglied entsteht unabhängig von einem
		// Mandat oder Beitrag (Spec §2.2) – beides bleibt hier leer.
		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		const row = visibleSection(page).locator('tr', { hasText: 'Neu Angelegt' })
		await expect(row).toBeVisible()
		await expect(row.getByText('kein Mandat')).toBeVisible()
	})

	test('Aufnahme-Dialog: Tab führt der Reihe nach durch alle Felder, ohne den Dialog zu verlassen', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await neuAnlegen(page, 'Mitglied')
		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()

		await page.waitForTimeout(500)
		// Ab hier nur die Tastatur: Tab vorwärts, jedes Mal muss der Fokus im Dialog liegen und ein neues Element treffen.
		const visited = []
		for (let i = 0; i < 40; i++) {
			await page.keyboard.press('Tab')
			const info = await page.evaluate(() => {
				const el = document.activeElement
				const dlg = document.querySelector('[role="dialog"][aria-labelledby="vbh-modal-title-member"]')
				const all = dlg ? [...dlg.querySelectorAll('input, select, textarea, button, a[href], [tabindex]')] : []
				const lab = el.labels && el.labels[0] ? el.labels[0].textContent : ''
				return { inside: !!dlg && dlg.contains(el), label: ((el.getAttribute('aria-label') || lab || el.textContent || el.tagName).trim().slice(0, 30)) + '#' + all.indexOf(el), tag: el.tagName }
			})
			expect(info.inside, `Tab ${i + 1} landet außerhalb des Dialogs auf „${info.label}“`).toBe(true)
			visited.push(`${info.tag}:${info.label}`)
		}
		expect(new Set(visited).size, `besuchte Elemente: ${visited.join(' | ')}`).toBeGreaterThanOrEqual(8)

		// Zurück mit Umschalt+Tab bleibt ebenfalls im Dialog.
		await page.keyboard.press('Shift+Tab')
		expect(await page.evaluate(() => document.querySelector('[role="dialog"][aria-labelledby="vbh-modal-title-member"]').contains(document.activeElement))).toBe(true)
	})

	test('Organisation als Mitglied anlegen', async ({ page }) => {
		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		await neuAnlegen(page, 'Mitglied')

		const dialog = page.getByRole('dialog', { name: 'Mitglied aufnehmen' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('combobox', { name: 'Mitgliedstyp' }).selectOption('organisation')
		await dialog.getByRole('textbox', { name: 'Name der Organisation', exact: true }).fill('Musikverein Talheim e.V.')
		await dialog.getByRole('button', { name: 'Aufnehmen' }).click()
		await expect(dialog).toBeHidden()

		await expect(visibleSection(page).getByText('Musikverein Talheim e.V.').first()).toBeVisible()
	})

	test('NC-Konto verknüpfen: Vorschlag zeigen, erst nach Rückfrage setzen, gilt erst nach Bestätigung durch das Mitglied', async ({ page, browser, request }) => {
		// Eindeutiger Nachname: /reset räumt die Mitglieder nicht ab, ein Wiederholungslauf fände sonst zwei gleichnamige Zeilen.
		const lastName = `Knuepfung-${Math.random().toString(36).slice(2, 6)}`
		const email = `verknuepfung.${lastName.toLowerCase()}@example.org`
		await api.unlinkMemberByNcUser(request, USERS.revisor)
		await api.setUserEmail(request, USERS.revisor, email)
		const member = await api.createMember(request, { firstName: 'Ver', lastName, email })

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('tr', { hasText: member.displayName })
		// Der Name der Zeile öffnet die Akte (der eine Weg dorthin); das Zeilenmenü führt nur zum Mandat.
		await row.getByRole('button', { name: 'Akte öffnen' }).click()

		const dialog = page.getByRole('dialog', { name: `Mitglied: ${member.displayName}` })
		await expect(dialog).toBeVisible()
		// Kein Treffer vorausgewählt (Spec §3.1) – vor dem Klick auf "Vorschläge
		// suchen" steht noch nichts.
		await expect(dialog.getByText('Verknüpft mit')).toHaveCount(0)

		await dialog.getByRole('button', { name: 'Vorschläge suchen' }).click()
		const suggestion = dialog.locator('.vbh-linksuggestions li', { hasText: USERS.revisor })
		await expect(suggestion).toBeVisible()
		await suggestion.getByRole('button', { name: 'Verknüpfen' }).click()
		// Rückfrage mit der Benutzer-ID – nur sie unterscheidet zwei Konten sicher (#295).
		const confirm = page.getByRole('dialog', { name: 'Mit Nextcloud-Konto verknüpfen' })
		await expect(confirm).toContainText(`Benutzer-ID ${USERS.revisor}`)
		await confirm.getByRole('button', { name: 'Verknüpfen', exact: true }).click()

		// Angelegt, aber ausstehend: das Konto hat noch keinen Zugriff als dieses Mitglied.
		await expect(dialog.getByText(`Verknüpfung mit „${USERS.revisor}" wartet auf Bestätigung durch das Mitglied.`)).toBeVisible()
		let updated = (await api.listMembers(request)).find((m) => m.id === member.id)
		expect(updated.ncUserId).toBe(USERS.revisor)
		expect(updated.ncLinkConfirmed).toBe(false)

		try {
			// Das Mitglied öffnet den Link aus seiner Mail mit dem verknüpften Konto und bestätigt.
			const token = await setMemberLinkToken(member.id)
			const memberContext = await browser.newContext()
			try {
				const own = await memberContext.newPage()
				await openApp(own, USERS.revisor)
				await own.goto(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/link-account/${token}`)
				await own.getByRole('button', { name: 'Ja, das bin ich – Konto bestätigen' }).click()
				await expect(own.getByRole('button', { name: 'Ja, das bin ich – Konto bestätigen' })).toHaveCount(0)
			} finally {
				await memberContext.close()
			}

			updated = (await api.listMembers(request)).find((m) => m.id === member.id)
			expect(updated.ncLinkConfirmed).toBe(true)
			// Der Link gilt nur einmal.
			const again = await request.fetch(`${BASE_URL}/index.php/apps/vereinsbuchhaltung/link-account/${token}`, { headers: authHeaders(USERS.revisor), maxRedirects: 0 })
			expect(again.status()).toBe(404)
		} finally {
			// Aufräumen: eine bleibende Verknüpfung würde dem Self-Service anderer Specs ein Mitglied für `revisor` unterschieben.
			await api.unlinkMember(request, member.id)
		}
	})

	test('Löschsperre: aktives Mandat verhindert das Löschen mit erklärender Meldung', async ({ page, request }) => {
		// Eindeutiger Nachname: /reset räumt die Mitglieder nicht ab, ein Wiederholungslauf fände sonst zwei gleichnamige Zeilen.
		const lastName = `Mandat-${Math.random().toString(36).slice(2, 6)}`
		const member = await api.createMember(request, { firstName: 'Mit', lastName })
		const mandate = await (await api.createMandate(request, { memberId: member.id, iban: 'DE02120300000000202051', signedAt: '2026-01-15' })).json()
		await api.activateMandate(request, mandate.id)

		await openApp(page, USERS.buchhalter)
		await switchTab(page, 'Beiträge')
		const row = visibleSection(page).locator('tr', { hasText: member.displayName })
		// Der Name der Zeile öffnet die Akte (der eine Weg dorthin); das Zeilenmenü führt nur zum Mandat.
		await row.getByRole('button', { name: 'Akte öffnen' }).click()

		const dialog = page.getByRole('dialog')
		await expect(dialog.getByText('aktives SEPA-Mandat')).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Mitglied löschen' })).toHaveCount(0)

		// Auch die API lehnt das Löschen ab, mit derselben Begründung.
		const resp = await api.raw(request, 'DELETE', `/members/${member.id}`)
		expect(resp.status()).toBe(400)
		expect((await resp.json()).message).toContain('aktives SEPA-Mandat')
	})

	test('Löschsperre: auch ein Mandat im Entwurf und ein beendetes sperren, sonst bliebe eine verwaiste Zeile zurück', async ({ request }) => {
		const draft = await api.createMember(request, { firstName: 'Mit', lastName: `Entwurf-${Math.random().toString(36).slice(2, 6)}` })
		await api.createMandate(request, { memberId: draft.id, iban: 'DE02120300000000202051' })

		const ended = await api.createMember(request, { firstName: 'Mit', lastName: `Widerruf-${Math.random().toString(36).slice(2, 6)}` })
		const endedMandate = await (await api.createMandate(request, { memberId: ended.id, iban: 'DE02120300000000202051', signedAt: '2026-01-15' })).json()
		await api.activateMandate(request, endedMandate.id)
		await api.revokeMandate(request, endedMandate.id)

		for (const member of [draft, ended]) {
			const resp = await api.raw(request, 'DELETE', `/members/${member.id}`)
			expect(resp.status(), `${member.displayName} darf nicht löschbar sein`).toBe(400)
			const { message } = await resp.json()
			expect(message).toContain('SEPA-Mandat')
			expect(message).not.toContain('aktives SEPA-Mandat')
		}

		// Ohne Mandat, Zuweisung und Forderung lässt sich ein Mitglied dagegen löschen.
		const free = await api.createMember(request, { firstName: 'Ohne', lastName: `Bindung-${Math.random().toString(36).slice(2, 6)}` })
		await api.deleteMember(request, free.id)
		expect((await api.listMembers(request)).some((m) => m.id === free.id)).toBe(false)
	})
})
