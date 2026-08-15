import { describe, expect, it } from 'vitest'
import { splitBalanced, splitRemainder, splitSideOf } from './split.js'

// Die Regeln der Splittbuchung entscheiden darueber, welche Nutzlast das
// Backend bekommt. Eine falsche Seite vertauscht Soll und Haben, ein falsch
// gerundeter Rest laesst eine korrekte Aufteilung als unspeicherbar erscheinen
// ("Rest 0,00 €, trotzdem nicht speicherbar") - beides faellt ohne Test erst
// beim Nutzer auf.

describe('splitSideOf', () => {
	it('teilt im Einfach-Modus bei einer Einnahme die Habenseite auf', () => {
		// Einnahme: Geld kommt auf das Bankkonto (Soll), die Kategorien stehen
		// im Haben - also ist das Haben die aufgeteilte Seite.
		expect(splitSideOf({ kind: 'income' }, 'simple')).toBe('credit')
	})

	it('teilt im Einfach-Modus bei einer Ausgabe die Sollseite auf', () => {
		expect(splitSideOf({ kind: 'expense' }, 'simple')).toBe('debit')
	})

	it('folgt im Experten-Modus der Wahl des Nutzers', () => {
		expect(splitSideOf({ splitSide: 'debit' }, 'expert')).toBe('debit')
		expect(splitSideOf({ splitSide: 'credit' }, 'expert')).toBe('credit')
	})

	it('faellt im Experten-Modus ohne Wahl auf die Habenseite zurueck', () => {
		expect(splitSideOf({}, 'expert')).toBe('credit')
		expect(splitSideOf({ splitSide: 'quatsch' }, 'expert')).toBe('credit')
	})

	it('ignoriert im Einfach-Modus eine gesetzte Seite', () => {
		// Der Einfach-Modus zeigt die Wahl gar nicht an; ein Rest aus einem
		// vorherigen Wechsel in den Experten-Modus darf nicht durchschlagen.
		expect(splitSideOf({ kind: 'income', splitSide: 'debit' }, 'simple')).toBe('credit')
	})
})

describe('splitRemainder', () => {
	it('meldet den noch nicht verteilten Betrag', () => {
		expect(splitRemainder(100, [{ amount: 30 }, { amount: 20 }])).toBe(50)
	})

	it('meldet null, wenn die Aufteilung aufgeht', () => {
		expect(splitRemainder(100, [{ amount: 60 }, { amount: 40 }])).toBe(0)
	})

	it('meldet einen negativen Rest, wenn zu viel verteilt wurde', () => {
		expect(splitRemainder(100, [{ amount: 60 }, { amount: 50 }])).toBe(-10)
	})

	it('rechnet in Cent, damit 0,1 + 0,2 aufgeht', () => {
		// In Gleitkomma waere 0.1 + 0.2 = 0.30000000000000004 und der Rest
		// damit nicht null - die Aufteilung liesse sich nicht speichern,
		// obwohl sie stimmt.
		expect(splitRemainder(0.3, [{ amount: 0.1 }, { amount: 0.2 }])).toBe(0)
	})

	it('rundet je Zeile auf Cent, wie es das Backend auch tut', () => {
		// 33,33 + 33,33 + 33,34 = 100,00
		expect(splitRemainder(100, [{ amount: 33.33 }, { amount: 33.33 }, { amount: 33.34 }])).toBe(0)
	})

	it('behandelt fehlende und leere Angaben als null', () => {
		expect(splitRemainder(50, [])).toBe(50)
		expect(splitRemainder(50, null)).toBe(50)
		expect(splitRemainder(50, [{}, { amount: null }, { amount: '' }])).toBe(50)
		expect(splitRemainder(null, [])).toBe(0)
	})

	it('nimmt Betraege auch als Zeichenkette an', () => {
		// Die Eingabefelder liefern Strings; das Formular reicht sie durch.
		expect(splitRemainder('100', [{ amount: '40' }, { amount: '60' }])).toBe(0)
	})
})

describe('splitBalanced', () => {
	it('gilt als aufgegangen, wenn nichts uebrig ist', () => {
		expect(splitBalanced(100, [{ amount: 100 }])).toBe(true)
	})

	it('gilt als offen, solange ein Cent fehlt', () => {
		expect(splitBalanced(100, [{ amount: 99.99 }])).toBe(false)
	})

	it('gilt als offen, wenn ein Cent zu viel verteilt ist', () => {
		expect(splitBalanced(100, [{ amount: 100.01 }])).toBe(false)
	})

	it('gilt als offen, wenn noch gar nichts verteilt ist', () => {
		expect(splitBalanced(100, [])).toBe(false)
	})
})
