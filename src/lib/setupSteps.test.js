import { describe, expect, it } from 'vitest'
import { buildSetupSteps } from './setupSteps.js'

// Kernfrage von Issue #60: die Karte beschreibt den Stand des Vereins, nicht
// den des gewaehlten Geschaeftsjahres. Deshalb bekommt sie ein hasAnyBooking
// ueber alle Zeitraeume und keine Anzahl des gewaehlten - die Faelle unten
// unterscheiden genau das.

/** Ein voll eingerichteter Verein - die Faelle variieren davon jeweils eins. */
function ctx(over = {}) {
	return {
		clubName: 'Testverein e. V.',
		accounts: [
			{ number: '1200', isBank: true, type: 'asset', openingDate: null, sphere: null },
			{ number: '4100', isBank: false, type: 'income', openingDate: null, sphere: 'ideell' },
			{ number: '6300', isBank: false, type: 'expense', openingDate: null, sphere: 'ideell' },
			// Eigenkapital ist wie Geldkonten nicht ergebniswirksam und braucht
			// keine Sphaere (Account::isResultRelevant() im Backend).
			{ number: '0800', isBank: false, type: 'equity', openingDate: null, sphere: null },
		],
		permissions: [{ userId: 'buchhalter', role: 'buchhalter' }],
		hasAnyBooking: true,
		...over,
	}
}

/** Erledigt-Zustand eines Punktes. */
function done(c, id) {
	const step = buildSetupSteps(c).find((s) => s.id === id)
	if (!step) { throw new Error(`Punkt "${id}" gibt es nicht`) }
	return step.done
}

describe('buildSetupSteps', () => {
	it('haelt Buchungspunkte fuer erledigt, wenn irgendwo gebucht wurde', () => {
		// Der Aufrufer hat bereits ueber alle Zeitraeume geschaut; die Karte darf
		// nicht danach fragen, ob im gerade gewaehlten etwas steht.
		const c = ctx({ hasAnyBooking: true })
		expect(done(c, 'booking')).toBe(true)
		expect(done(c, 'opening')).toBe(true)
	})

	it('haelt den Anfangsbestand fuer erledigt, auch ohne openingDate', () => {
		// xbuc-Importe setzen openingDate nicht - der Anfangsbestand steckt dort
		// in der EB-Buchung. Ohne diese Regel blieben importierte Vereine ewig
		// bei "Anfangsbestand eintragen" haengen.
		const c = ctx({
			hasAnyBooking: true,
			accounts: ctx().accounts.map((a) => ({ ...a, openingDate: null })),
		})
		expect(done(c, 'opening')).toBe(true)
	})

	it('haelt den Anfangsbestand fuer erledigt, wenn ein Geldkonto ihn traegt', () => {
		const c = ctx({
			hasAnyBooking: false,
			accounts: ctx().accounts.map((a) => (a.isBank ? { ...a, openingDate: '2026-01-01' } : a)),
		})
		expect(done(c, 'opening')).toBe(true)
		// Gebucht wurde deswegen noch nicht.
		expect(done(c, 'booking')).toBe(false)
	})

	it('laesst beide Buchungspunkte offen, solange es keine Buchung gibt', () => {
		const c = ctx({ hasAnyBooking: false })
		expect(done(c, 'booking')).toBe(false)
		expect(done(c, 'opening')).toBe(false)
	})

	it('wertet Verein, Konten und Berechtigungen unabhaengig von Buchungen', () => {
		expect(done(ctx({ clubName: '' }), 'club')).toBe(false)
		expect(done(ctx(), 'club')).toBe(true)
		expect(done(ctx({ accounts: [] }), 'accounts')).toBe(false)
		expect(done(ctx(), 'accounts')).toBe(true)
		expect(done(ctx({ permissions: [] }), 'permissions')).toBe(false)
		expect(done(ctx(), 'permissions')).toBe(true)
	})

	it('verlangt eine Sphaere nur von ergebniswirksamen Konten', () => {
		// Geldkonto und Eigenkapital ohne Sphaere stoeren nicht ...
		expect(done(ctx(), 'spheres')).toBe(true)
		// ... ein Aufwandskonto ohne Sphaere schon.
		const c = ctx({ accounts: ctx().accounts.map((a) => (a.type === 'expense' ? { ...a, sphere: null } : a)) })
		expect(done(c, 'spheres')).toBe(false)
	})

	it('uebersetzt die Beschriftungen mit dem hereingereichten t', () => {
		const steps = buildSetupSteps({ ...ctx(), t: (s) => `[${s}]` })
		expect(steps.find((s) => s.id === 'booking').label).toBe('[Erste Buchung erfassen]')
		// Ohne t bleiben die deutschen Quelltexte stehen.
		expect(buildSetupSteps(ctx()).find((s) => s.id === 'booking').label).toBe('Erste Buchung erfassen')
	})
})
