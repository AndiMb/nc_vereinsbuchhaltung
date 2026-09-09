import { test, expect } from '@playwright/test'
import { api, openApp, BANK_ACCOUNT, INCOME_ACCOUNT, USERS } from './fixtures/nextcloud.mjs'

// Geschäftsjahre (Issue #8): ein Geschäftsjahr muss nicht dem Kalenderjahr
// entsprechen. Geprüft wird beides – die Festschreibung, die es schon vorher
// gab, und die Umstellung der Regel, die dafür jede Buchung neu zuordnet und
// die Buchungsnummern neu vergibt.

test.describe('Geschäftsjahr', () => {
	let bank, income

	test.beforeAll(async ({ request }) => {
		await api.resetBook(request)
		await api.seedDefaultAccounts(request)
		;[bank, income] = await api.accountsByNumber(request, BANK_ACCOUNT, INCOME_ACCOUNT)
		// Zurück auf die Vorgabe, falls ein vorheriger Lauf umgestellt hat.
		await api.setPeriodRule(request, { preset: 'calendar' })
	})

	test.afterAll(async ({ request }) => {
		await api.setPeriodRule(request, { preset: 'calendar' })
	})

	test('abweichendes Geschäftsjahr: zwei Kalenderjahre, ein Zeitraum, fortlaufende Nummern', async ({ page, request }) => {
		await api.setPeriodRule(request, { preset: 'oct-sep' })

		// Dezember und Januar gehören bei Okt–Sep in dasselbe Geschäftsjahr.
		await api.createBooking(request, {
			date: '2030-12-15', description: 'Vor dem Jahreswechsel', debitAccountId: bank.id, creditAccountId: income.id, amount: 100,
		})
		await api.createBooking(request, {
			date: '2031-01-15', description: 'Nach dem Jahreswechsel', debitAccountId: bank.id, creditAccountId: income.id, amount: 50,
		})

		const dezember = await api.periodIdForDate(request, '2030-12-15')
		const januar = await api.periodIdForDate(request, '2031-01-15')
		expect(januar).toBe(dezember)

		const periods = await api.listPeriods(request)
		const period = periods.find((p) => p.id === dezember)
		expect(period.startDate).toBe('2030-10-01')
		expect(period.endDate).toBe('2031-09-30')
		expect(period.label).toBe('2030/31')

		// Beide Buchungen tragen denselben Nummernkreis, lückenlos ab 1.
		const journal = await api.listJournal(request, { period })
		const nummern = journal.map((item) => item.journal.entryNo).sort((a, b) => a - b)
		expect(nummern).toEqual([1, 2])

		await openApp(page, USERS.verwalter)
		await expect(page.locator('.vbh-yearsel option', { hasText: '2030/31' })).toHaveCount(1)
	})

	test('Umstellung auf Semester teilt den Zeitraum', async ({ request }) => {
		await api.setPeriodRule(request, { preset: 'semester' })

		const dezember = await api.periodIdForDate(request, '2030-12-15')
		const april = await api.periodIdForDate(request, '2031-04-15')
		expect(april).not.toBe(dezember)

		const periods = await api.listPeriods(request)
		const winter = periods.find((p) => p.id === dezember)
		expect(winter.startDate).toBe('2030-10-01')
		expect(winter.endDate).toBe('2031-03-31')
		expect(winter.label).toBe('2030/31-1')

		// Beide Buchungen liegen weiterhin zusammen, jetzt im Wintersemester.
		expect(winter.bookings).toBe(2)
	})

	test('abgeschlossener Zeitraum: Schloss in der Auswahl, Buchung wird abgewiesen', async ({ page, request }) => {
		const winter = await api.periodIdForDate(request, '2030-12-15')
		await api.closePeriod(request, winter)

		await openApp(page, USERS.verwalter)
		await expect(page.locator('.vbh-yearsel option', { hasText: '2030/31-1 🔒' })).toHaveCount(1)

		const resp = await api.createBooking(request, {
			date: '2030-12-20',
			description: 'Darf nicht durchgehen',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 5,
			expectOk: false,
		})
		expect(resp.status()).toBe(423)
	})

	test('festgeschriebener Zeitraum blockiert das Umstellen der Regel', async ({ request }) => {
		const resp = await api.setPeriodRule(request, { preset: 'calendar' }, { expectOk: false })
		expect(resp.status()).toBe(423)
	})

	test('Wiedereröffnung lässt Buchungen und die Umstellung wieder zu', async ({ request }) => {
		const winter = await api.periodIdForDate(request, '2030-12-15')
		await api.reopenPeriod(request, winter)

		const gebucht = await api.createBooking(request, {
			date: '2030-12-20',
			description: 'Nach der Wiedereröffnung',
			debitAccountId: bank.id,
			creditAccountId: income.id,
			amount: 5,
		})
		expect(gebucht.status()).toBe(201)

		// Zurück auf das Kalenderjahr: die beiden Dezember-Buchungen und die
		// Januar-Buchung liegen danach in getrennten Zeiträumen.
		await api.setPeriodRule(request, { preset: 'calendar' })
		const dezember = await api.periodIdForDate(request, '2030-12-15')
		const januar = await api.periodIdForDate(request, '2031-01-15')
		expect(januar).not.toBe(dezember)

		const periods = await api.listPeriods(request)
		expect(periods.find((p) => p.id === dezember).label).toBe('2030')
		// Jeder Zeitraum wird nach der Umstellung neu durchnummeriert.
		const journal = await api.listJournal(request, { period: januar })
		expect(journal.map((item) => item.journal.entryNo)).toEqual([1])
	})

	test('nur Verwalter dürfen abschließen und umstellen', async ({ request }) => {
		const period = await api.periodIdForDate(request, '2030-12-15')
		const abschluss = await api.closePeriod(request, period, { user: USERS.buchhalter, expectOk: false })
		expect(abschluss.status()).toBe(403)

		const regel = await api.setPeriodRule(request, { preset: 'oct-sep' }, { user: USERS.buchhalter, expectOk: false })
		expect(regel.status()).toBe(403)
	})
})
