import { describe, expect, it } from 'vitest'
import { emptyReimbursementForm, missingReimbursementFields, reimbursementBookingPrefill, reimbursementDraftForm, reimbursementFormHasContent } from './reimbursements.js'
import { emptyTravel, emptyTravelItem, formatTravelPeriod, missingTravelFields, travelItemSummary, travelPayload, travelToForm, travelTotalCents } from './travelClaims.js'

function filledTravel() {
	const travel = emptyTravel()
	travel.destination = ' Kassel '
	travel.start = '2026-11-07T07:30'
	travel.end = '2026-11-07T19:15'
	Object.assign(travel.items[0], { transport: 'car', route: 'Vereinsheim – Kassel', distanceKm: '184,5', amount: 55.2 })
	travel.items.push({ ...emptyTravelItem('parking'), note: 'Parkhaus', amount: 8 })
	return travel
}

describe('Reisekosten (#353)', () => {
	it('legt eine Fahrt als erste Position an, jede mit eigenem Zeilenschlüssel', () => {
		const travel = emptyTravel()
		expect(travel.items).toHaveLength(1)
		expect(travel.items[0].type).toBe('travel')
		expect(emptyTravelItem('parking').uid).not.toBe(travel.items[0].uid)
	})

	it('überträgt Cent, Kilometer mit Komma und leere Felder als null – ohne Zeilenschlüssel', () => {
		const payload = travelPayload(filledTravel())
		expect(payload).toEqual({
			destination: 'Kassel',
			start: '2026-11-07T07:30',
			end: '2026-11-07T19:15',
			items: [
				{ type: 'travel', transport: 'car', route: 'Vereinsheim – Kassel', distanceKm: 184.5, amountCents: 5520 },
				{ type: 'parking', note: 'Parkhaus', amountCents: 800 },
			],
		})
		expect(travelTotalCents(filledTravel())).toBe(6320)
	})

	it('lädt einen Entwurf zurück ins Formular', () => {
		const form = travelToForm(travelPayload(filledTravel()))
		expect(form.destination).toBe('Kassel')
		expect(form.items[0].distanceKm).toBe('184,5')
		expect(form.items[0].amount).toBe(55.2)
		expect(form.items[1].amount).toBe(8)
		expect(travelToForm(null).items).toHaveLength(1)
	})

	it('nennt, was zum Einreichen noch fehlt', () => {
		expect(missingTravelFields(filledTravel(), '2026-11-17')).toEqual([])
		expect(missingTravelFields(emptyTravel(), '2026-11-17')).toEqual(['Reiseziel', 'Beginn und Ende', 'Betrag je Position', 'Verkehrsmittel und Strecke'])

		const reversed = { ...filledTravel(), end: '2026-11-07T06:00' }
		expect(missingTravelFields(reversed, '2026-11-17')).toEqual(['Ende nach dem Beginn'])
		const future = { ...filledTravel(), start: '2026-11-20T08:00', end: '2026-11-20T09:00' }
		expect(missingTravelFields(future, '2026-11-17')).toEqual(['Reisebeginn nicht in der Zukunft'])
		expect(missingTravelFields({ ...filledTravel(), items: [] }, '2026-11-17')).toEqual(['Position'])
	})

	it('verlangt bei Reisekosten keinen Beleg, Betrag oder Rechnungsdatum', () => {
		const form = emptyReimbursementForm('2026-11-17', { accountHolder: 'Max', iban: 'DE02120300000000202051' }, 'travel')
		form.travel = filledTravel()
		form.purpose = 'Turnier'
		form.declaration = true
		expect(missingReimbursementFields(form, '2026-11-17')).toEqual([])
		expect(reimbursementFormHasContent({ ...form, purpose: '', travel: emptyTravel() })).toBe(false)
		expect(reimbursementFormHasContent({ ...form, purpose: '' })).toBe(true)
	})

	it('übernimmt die Art aus dem Entwurf', () => {
		const form = reimbursementDraftForm({ kind: 'travel', amountCents: 6320, purpose: 'Turnier', travel: travelPayload(filledTravel()), files: [] })
		expect(form.kind).toBe('travel')
		expect(form.travel.items).toHaveLength(2)
		expect(reimbursementDraftForm({ purpose: '', files: [] }).kind).toBe('expense')
	})

	it('zeigt Zeitraum und Positionen lesbar an', () => {
		expect(formatTravelPeriod('2026-11-07T07:30', '2026-11-07T19:15')).toMatch(/07:30 – 19:15$/)
		expect(formatTravelPeriod('2026-11-07T07:30', '2026-11-08T18:00')).toMatch(/07:30 – .+ 18:00$/)
		expect(travelItemSummary({ type: 'travel', transport: 'public', route: 'Hbf – Kassel', distanceKm: 12.5 })).toBe('Bus und Bahn · Hbf – Kassel · 12,5 km')
		expect(travelItemSummary({ type: 'parking', note: null })).toBe('')
	})

	it('nennt die Reisekosten im Buchungstext', () => {
		const prefill = reimbursementBookingPrefill({ kind: 'travel', amountCents: 6320, purpose: 'Turnier', memberName: 'Max', id: 3 }, '2026-11-17')
		expect(prefill.description).toBe('Erstattung Reisekosten: Turnier (Max)')
	})
})
