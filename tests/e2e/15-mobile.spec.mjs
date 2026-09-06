import { test, expect } from '@playwright/test'
import { api, openApp, visibleSection, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Die Ansicht für unterwegs: untere Navigationsleiste, Buchungen als
// Karten statt Tabelle, schwebender Buchen-Knopf.

test.use({ viewport: { width: 375, height: 812 }, hasTouch: true })

/**
 * Lage eines Unterreiters, nachdem NUR seine eigene Leiste gescrollt wurde –
 * also mit dem, was ein Finger auf dem Gerät tatsächlich ausrichten kann.
 *
 * Bewusst weder `scrollIntoView()` noch Playwrights automatisches Einscrollen
 * vor dem Klick: beides scrollt auch Vorfahren mit und würde genau den Fehler
 * aus Issue #38 verdecken. Dort war die Leiste selbst kein Scroll-Container,
 * ihr Überhang wurde von `.vbh` (overflow: hidden) abgeschnitten – der Test
 * hätte trotzdem klicken können, die Nutzerin nicht.
 *
 * `scrollLeft` klemmt der Browser von sich aus auf das Mögliche; bei einer
 * nicht scrollbaren Leiste bleibt der Wert 0 und der Knopf liegt weiterhin
 * ausserhalb – dann schlägt `imKasten` fehl.
 *
 * @param knopf Locator eines Knopfes innerhalb von .vbh-subtabs
 */
async function lageNachEigenemScrollen(knopf) {
	return knopf.evaluate((el) => {
		const leiste = el.closest('.vbh-subtabs')
		leiste.scrollLeft = el.offsetLeft - (leiste.clientWidth - el.offsetWidth) / 2
		const k = el.getBoundingClientRect()
		const l = leiste.getBoundingClientRect()
		return {
			label: el.textContent.replace(/\s+/g, ' ').trim(),
			breite: Math.round(k.width),
			// Der Knopf liegt vollstaendig im Kasten der Leiste ...
			imKasten: k.left >= l.left - 1 && k.right <= l.right + 1,
			// ... und der Kasten liegt im Sichtfeld (sonst nuetzt das Scrollen nichts).
			imSichtfeld: l.left >= -1 && l.right <= window.innerWidth + 1,
		}
	})
}

test.describe('Mobile Ansicht', () => {
	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		const [bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		await api.createBooking(request, {
			date: '2026-05-05',
			description: 'Mobil sichtbare Buchung',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 15,
		})
	})

	test('untere Navigation führt zu den Buchungs-Karten', async ({ page }) => {
		await openApp(page, USERS.verwalter)

		const bottomNav = page.locator('.vbh-bottomnav')
		await expect(bottomNav).toBeVisible()
		await expect(page.locator('.vbh-fab')).toBeVisible()

		await bottomNav.getByRole('button', { name: 'Buchungen' }).click()
		// Gescopt auf den sichtbaren Abschnitt: die Übersicht hält dieselbe
		// Buchung als (per v-show versteckte) Karte ebenfalls im DOM.
		await expect(visibleSection(page).locator('.vbh-mcard', { hasText: 'Mobil sichtbare Buchung' }).first()).toBeVisible()
	})

	// Issue #38: auf schmalen Geraeten lief die Reiterleiste der Berichte aus
	// dem Bild und liess sich nicht scrollen - die hinteren Unterreiter und der
	// Export-Knopf waren unerreichbar. Geprueft wird deshalb nicht ein einzelner
	// Reiter, sondern dass JEDER Tab und JEDER Unterreiter auf 375px mit dem
	// Finger erreichbar ist; die Leisten sind fuer alle Tabs dieselbe Klasse,
	// und welche Reiter es gibt, haengt an Rolle und Konfiguration.
	test('jeder Tab der unteren Navigation liegt im Sichtfeld', async ({ page }) => {
		await openApp(page, USERS.verwalter)

		const breite = page.viewportSize().width
		const eintraege = page.locator('.vbh-bottomnav .vbh-bottomnav-item')
		const anzahl = await eintraege.count()
		expect(anzahl, 'untere Navigation ohne Eintraege').toBeGreaterThanOrEqual(4)

		for (let i = 0; i < anzahl; i++) {
			const eintrag = eintraege.nth(i)
			const label = (await eintrag.innerText()).replace(/\s+/g, ' ').trim()
			const box = await eintrag.boundingBox()
			expect(box, `Tab "${label}" hat keine Flaeche`).not.toBeNull()
			expect(box.x >= -1 && box.x + box.width <= breite + 1, `Tab "${label}" liegt ausserhalb des Sichtfelds`).toBe(true)

			await eintrag.click()
			await expect(eintrag).toHaveClass(/active/)
			await expect(visibleSection(page)).toBeVisible()
		}
	})

	test('jeder Unterreiter ist durch Scrollen seiner Leiste erreichbar', async ({ page }) => {
		await openApp(page, USERS.verwalter)

		const eintraege = page.locator('.vbh-bottomnav .vbh-bottomnav-item')
		const anzahl = await eintraege.count()
		let geprueft = 0

		for (let i = 0; i < anzahl; i++) {
			const eintrag = eintraege.nth(i)
			const tabName = (await eintrag.innerText()).replace(/\s+/g, ' ').trim()
			await eintrag.click()
			await expect(eintrag).toHaveClass(/active/)
			await expect(visibleSection(page)).toBeVisible()

			const leiste = visibleSection(page).locator('.vbh-subtabs')
			// Uebersicht und Konten haben keine Unterreiter.
			if (await leiste.count() === 0) { continue }
			await expect(leiste).toBeVisible()
			geprueft++

			const knoepfe = leiste.locator('button')
			const reiter = await knoepfe.count()
			expect(reiter, `Reiterleiste unter "${tabName}" ist leer`).toBeGreaterThan(0)

			for (let k = 0; k < reiter; k++) {
				const knopf = knoepfe.nth(k)
				const lage = await lageNachEigenemScrollen(knopf)
				expect(lage.breite, `Unterreiter "${lage.label}" (${tabName}) hat keine Breite`).toBeGreaterThan(0)
				expect(lage.imSichtfeld, `Reiterleiste unter "${tabName}" ragt aus dem Sichtfeld`).toBe(true)
				expect(lage.imKasten, `Unterreiter "${lage.label}" (${tabName}) ist auf ${page.viewportSize().width}px nicht erreichbar`).toBe(true)

				await knopf.click()
				await expect(knopf).toHaveClass(/active/)
			}

			// Nachtrag zur Geometrie: programmatisches Scrollen funktioniert auch
			// bei overflow-x: hidden, ein Wischen aber nicht. Die Pruefung gilt
			// bewusst nur fuer eine tatsaechlich ueberlaufende Leiste - passt
			// alles hinein oder bricht die Leiste um, ist Scrollen weder noetig
			// noch vorgeschrieben.
			const leistenLage = await leiste.evaluate((el) => ({
				ueberlaeuft: el.scrollWidth > el.clientWidth + 1,
				ueberlauf: getComputedStyle(el).overflowX,
			}))
			if (leistenLage.ueberlaeuft) {
				expect(leistenLage.ueberlauf, `Reiterleiste unter "${tabName}" laeuft ueber, laesst sich aber nicht wischen`).toMatch(/auto|scroll/)
			}
		}

		// Sicherung gegen einen stillen Leerlauf: Buchungen und Berichte haben
		// immer Unterreiter, Beitraege nur bei aktiver Beitragsverwaltung.
		expect(geprueft, 'keine einzige Reiterleiste geprueft').toBeGreaterThanOrEqual(2)
	})

	test('die Export-Aktionen der Berichte bleiben erreichbar', async ({ page }) => {
		await openApp(page, USERS.verwalter)
		await page.locator('.vbh-bottomnav').getByRole('button', { name: 'Berichte' }).click()

		const aktionen = visibleSection(page).locator('.vbh-sectiontop-actions')
		await expect(aktionen).toBeVisible()
		const box = await aktionen.boundingBox()
		expect(box.x + box.width, 'Aktionszeile ragt aus dem Sichtfeld').toBeLessThanOrEqual(page.viewportSize().width + 1)

		// Mobil wandern Kassenbericht und Kurzbericht mitsamt "seit"-Datum in das
		// Sammelmenue - als eigene Knoepfe passten sie nicht mehr in die Zeile.
		await aktionen.getByRole('button', { name: 'Weitere Exporte' }).click()
		const menue = page.locator('.v-popper__popper--shown')
		await expect(menue.getByRole('link', { name: 'Kassenbericht', exact: true })).toBeVisible()
		await expect(menue.getByRole('link', { name: 'Kurzbericht', exact: true })).toBeVisible()

		// Das Datumsfeld ist mobil ein NcActionInput; sein Wert muss weiterhin in
		// der Kurzbericht-URL landen (Date-Objekt <-> 'YYYY-MM-DD'-Bruecke).
		const datum = menue.locator('input[type=date]')
		await expect(datum).toBeVisible()
		await datum.fill('2026-03-17')
		await expect(menue.getByRole('link', { name: 'Kurzbericht', exact: true }))
			.toHaveAttribute('href', /since=2026-03-17/)
	})
})
