import { describe, expect, it } from 'vitest'
import { isClaimItem, openItemStatusLabel } from './openItems.js'

// Die generische Offene-Posten-Sicht (Issue #121) unterscheidet freie Posten von
// Forderungen des Beitragsmoduls an `memberId`/`type`, die der Server ohnehin
// mitschickt. Die Erkennung muss zum Server passen (OpenItem::belongsToClaimModule):
// EIN Feld genügt, ein freier Posten trägt beide leer.

describe('isClaimItem', () => {
	it('erkennt eine Forderung an Mitglied und Art', () => {
		expect(isClaimItem({ id: 1, memberId: 7, type: 'beitrag' })).toBe(true)
		expect(isClaimItem({ id: 2, memberId: 7, type: 'gebuehr' })).toBe(true)
	})

	it('hält einen freien Posten für frei, auch mit den vom Server gelieferten Leerfeldern', () => {
		expect(isClaimItem({ id: 3, debtor: 'Schreinerei Holz', memberId: null, type: null })).toBe(false)
		// Ältere Antworten ohne die Felder
		expect(isClaimItem({ id: 4, debtor: 'Schreinerei Holz' })).toBe(false)
	})

	it('sperrt schon bei einem der beiden Felder', () => {
		expect(isClaimItem({ id: 5, memberId: 7, type: null })).toBe(true)
		expect(isClaimItem({ id: 6, memberId: null, type: 'beitrag' })).toBe(true)
	})

	it('nimmt die Mitglieds-ID 0 nicht für leer', () => {
		// Eine falsy-Prüfung würde 0 übersehen; der Server prüft auf NULL.
		expect(isClaimItem({ id: 7, memberId: 0, type: null })).toBe(true)
	})

	it('verträgt fehlende Posten', () => {
		expect(isClaimItem(null)).toBe(false)
		expect(isClaimItem(undefined)).toBe(false)
	})
})

describe('openItemStatusLabel', () => {
	it('beschriftet die drei Stände freier Posten und den Erlass einer Forderung', () => {
		expect(openItemStatusLabel('open')).toBe('Offen')
		expect(openItemStatusLabel('paid')).toBe('Bezahlt')
		expect(openItemStatusLabel('cancelled')).toBe('Storniert')
		expect(openItemStatusLabel('waived')).toBe('Erlassen')
	})

	it('zeigt einen unbekannten Stand unverändert', () => {
		expect(openItemStatusLabel('returned')).toBe('returned')
	})
})
