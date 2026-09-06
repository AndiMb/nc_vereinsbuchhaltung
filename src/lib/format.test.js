import { describe, expect, it } from 'vitest'
import {
	amountClass,
	amountInputRaw,
	budgetDiffClass,
	errMsg,
	formatAmountInput,
	formatDate,
	formatDateTime,
	formatMoney,
	parseAmountInput,
	roleLabel,
	roundCents,
	typeLabel,
} from './format.js'

// Die Anzeigehelfer stehen in jeder Tabelle der App. Sie sind zustandslos und
// damit billig zu pruefen - anders als der Rest des Frontends, der ohne
// laufende Nextcloud-Instanz nicht sinnvoll testbar ist.

describe('formatMoney', () => {
	// Intl setzt vor das Waehrungszeichen einen geschuetzten Zwischenraum -
	// je nach ICU-Fassung U+00A0 oder U+202F. Ein direkter Zeichenvergleich
	// waere daher je nach Node-Version bruechig; \s deckt beide Varianten ab.
	const normalize = (s) => s.replace(/\s/g, ' ')

	it('schreibt Betraege in deutscher Schreibweise mit Euro', () => {
		expect(normalize(formatMoney(1234.5))).toBe('1.234,50 €')
	})

	it('zeigt negative Betraege mit Vorzeichen', () => {
		expect(normalize(formatMoney(-42))).toBe('-42,00 €')
	})

	it('behandelt fehlende Werte als null', () => {
		expect(normalize(formatMoney(null))).toBe('0,00 €')
		expect(normalize(formatMoney(undefined))).toBe('0,00 €')
		expect(normalize(formatMoney(0))).toBe('0,00 €')
	})
})

describe('formatDate', () => {
	it('dreht ein ISO-Datum in die deutsche Schreibweise', () => {
		expect(formatDate('2026-01-31')).toBe('31.01.2026')
	})

	it('schneidet einen Zeitanteil ab', () => {
		expect(formatDate('2026-01-31T14:05:00')).toBe('31.01.2026')
	})

	it('gibt bei leerer Angabe nichts aus', () => {
		expect(formatDate(null)).toBe('')
		expect(formatDate('')).toBe('')
	})

	it('laesst ein unbekanntes Format stehen', () => {
		// Lieber ein erkennbar roher Wert als ein still umgedeutetes Datum.
		expect(formatDate('31.01.2026')).toBe('31.01.2026')
		expect(formatDate('unbekannt')).toBe('unbekannt')
	})
})

describe('formatDateTime', () => {
	it('zeigt Datum und Uhrzeit ohne Sekunden', () => {
		expect(formatDateTime('2026-01-31T14:05:33')).toBe('2026-01-31 14:05')
	})

	it('gibt bei leerer Angabe nichts aus', () => {
		expect(formatDateTime(null)).toBe('')
	})
})

describe('typeLabel', () => {
	it('benennt die Kontoarten', () => {
		expect(typeLabel('income')).toBe('Ertrag')
		expect(typeLabel('expense')).toBe('Aufwand')
		expect(typeLabel('asset')).toBe('Aktiv')
		expect(typeLabel('liability')).toBe('Passiv')
		expect(typeLabel('equity')).toBe('Eigenkapital')
	})

	it('gibt eine unbekannte Art unveraendert zurueck', () => {
		expect(typeLabel('sonstwas')).toBe('sonstwas')
	})
})

describe('roleLabel', () => {
	it('benennt die Rollen', () => {
		expect(roleLabel('verwalter')).toBe('Verwalter')
		expect(roleLabel('buchhalter')).toBe('Buchhalter')
		expect(roleLabel('revisor')).toBe('Revisor')
	})

	it('gibt eine unbekannte Rolle unveraendert zurueck', () => {
		expect(roleLabel('gast')).toBe('gast')
	})
})

describe('amountClass', () => {
	it('markiert nur negative Betraege', () => {
		expect(amountClass(-1)).toBe('neg')
		expect(amountClass(0)).toBe('')
		expect(amountClass(1)).toBe('')
	})
})

describe('budgetDiffClass', () => {
	it('wertet mehr Einnahmen als geplant als gut', () => {
		expect(budgetDiffClass({ type: 'income', diff: 500 })).toBe('good')
	})

	it('wertet weniger Einnahmen als geplant als schlecht', () => {
		expect(budgetDiffClass({ type: 'income', diff: -500 })).toBe('bad')
	})

	it('wertet weniger Ausgaben als geplant als gut', () => {
		// Bei Ausgaben ist das Vorzeichen umgekehrt zu lesen: unter Plan
		// zu bleiben ist der Erfolg.
		expect(budgetDiffClass({ type: 'expense', diff: -500 })).toBe('good')
	})

	it('wertet mehr Ausgaben als geplant als schlecht', () => {
		expect(budgetDiffClass({ type: 'expense', diff: 500 })).toBe('bad')
	})

	it('markiert eine Punktlandung gar nicht', () => {
		expect(budgetDiffClass({ type: 'income', diff: 0 })).toBe('')
	})
})

describe('errMsg', () => {
	it('nimmt die Meldung des Servers, wenn es eine gibt', () => {
		const e = { response: { data: { message: 'Jahr ist festgeschrieben' } } }
		expect(errMsg(e, 'Ersatz')).toBe('Jahr ist festgeschrieben')
	})

	it('faellt auf den Ersatztext zurueck', () => {
		// Netzwerkabbruch, HTML-Fehlerseite, leerer Koerper: in all diesen
		// Faellen soll der Nutzer den fachlichen Ersatztext sehen.
		expect(errMsg(null, 'Ersatz')).toBe('Ersatz')
		expect(errMsg({}, 'Ersatz')).toBe('Ersatz')
		expect(errMsg({ response: {} }, 'Ersatz')).toBe('Ersatz')
		expect(errMsg({ response: { data: {} } }, 'Ersatz')).toBe('Ersatz')
	})
})

// Betragsfelder (AmountInput.vue): der Parser entscheidet, was aus dem
// Getippten wird - hier laufen die Formen zusammen, die Nutzer tatsaechlich
// eingeben. Issue #34.

describe('parseAmountInput', () => {
	it('liest die deutsche Schreibweise mit Tausenderpunkt', () => {
		expect(parseAmountInput('20.000,00')).toBe(20000)
		expect(parseAmountInput('1.234.567,89')).toBe(1234567.89)
		expect(parseAmountInput('1.500')).toBe(1500)
	})

	it('liest Komma und Punkt als Dezimaltrennzeichen', () => {
		expect(parseAmountInput('20000,5')).toBe(20000.5)
		expect(parseAmountInput('20000.5')).toBe(20000.5)
		expect(parseAmountInput('1,5')).toBe(1.5)
		expect(parseAmountInput('20.75')).toBe(20.75)
	})

	it('liest die englische Schreibweise, wenn beide Zeichen vorkommen', () => {
		expect(parseAmountInput('1,234.56')).toBe(1234.56)
		expect(parseAmountInput('1,234,567.89')).toBe(1234567.89)
	})

	it('ignoriert Waehrungszeichen und Leerraum', () => {
		expect(parseAmountInput('20.000,00 €')).toBe(20000)
		expect(parseAmountInput(' 20 000 ')).toBe(20000)
		expect(parseAmountInput('1.234,56 EUR')).toBe(1234.56)
		expect(parseAmountInput('20.000,00 €')).toBe(20000)
	})

	it('erkennt negative Betraege', () => {
		expect(parseAmountInput('-1.500,50')).toBe(-1500.5)
		expect(parseAmountInput('−42')).toBe(-42)
	})

	it('nimmt Zahlen unveraendert an', () => {
		expect(parseAmountInput(1234.5)).toBe(1234.5)
		expect(parseAmountInput(0)).toBe(0)
	})

	it('meldet unlesbare Eingaben als null – ein Vertipper darf keinen Wert auf 0 setzen', () => {
		expect(parseAmountInput('')).toBeNull()
		expect(parseAmountInput('   ')).toBeNull()
		expect(parseAmountInput(null)).toBeNull()
		expect(parseAmountInput('abc')).toBeNull()
		expect(parseAmountInput('12x')).toBeNull()
		expect(parseAmountInput('1.2.3')).toBeNull()
		expect(parseAmountInput(',')).toBeNull()
		expect(parseAmountInput('--5')).toBeNull()
	})

	it('verkraftet Zwischenstaende beim Tippen', () => {
		expect(parseAmountInput('1,')).toBe(1)
		expect(parseAmountInput('1.')).toBe(1)
	})
})

describe('formatAmountInput', () => {
	// wie oben: der Zwischenraum vor dem Euro haengt an der ICU-Fassung
	const normalize = (s) => s.replace(/\s/g, ' ')

	it('zeigt den Betrag wie die Tabellenspalten daneben', () => {
		expect(normalize(formatAmountInput(20000))).toBe('20.000,00 €')
		expect(normalize(formatAmountInput(1234.5))).toBe('1.234,50 €')
	})

	it('laesst das Waehrungszeichen weg, wenn daneben schon eines steht', () => {
		expect(normalize(formatAmountInput(20000, false))).toBe('20.000,00')
	})

	it('laesst leere Werte leer, damit der Platzhalter sichtbar bleibt', () => {
		expect(formatAmountInput('')).toBe('')
		expect(formatAmountInput(null)).toBe('')
		expect(formatAmountInput(undefined)).toBe('')
		expect(formatAmountInput('abc')).toBe('')
	})

	it('zeigt die Null als Betrag – 0,00 € ist ein gueltiger Planwert', () => {
		expect(normalize(formatAmountInput(0))).toBe('0,00 €')
	})
})

describe('amountInputRaw', () => {
	it('gibt den nackten Wert mit Dezimalkomma zum Bearbeiten', () => {
		expect(amountInputRaw(20000)).toBe('20000')
		expect(amountInputRaw(20000.5)).toBe('20000,5')
		expect(amountInputRaw('1234.56')).toBe('1234,56')
		expect(amountInputRaw(0)).toBe('0')
	})

	it('bleibt bei leeren Werten leer', () => {
		expect(amountInputRaw('')).toBe('')
		expect(amountInputRaw(null)).toBe('')
	})
})

describe('roundCents', () => {
	it('rundet auf ganze Cent – mehr kann das Backend nicht speichern', () => {
		expect(roundCents(12.345)).toBe(12.35)
		expect(roundCents(0.1 + 0.2)).toBe(0.3)
		expect(roundCents(-1.005)).toBe(-1)
	})
})
