import { describe, expect, it } from 'vitest'
import {
	authorLabel,
	CREDITOR_PLACEHOLDER,
	legalTextParagraphs,
	MAX_RAHMEN_LENGTH,
	normalizeRahmen,
	rahmenError,
	RESERVED_MARKER_PREFIX,
} from './mandateLegalText.js'

// Die Vorschau des Rechtstext-Editors baut den Text aus Pflichtblock und
// Rahmen so zusammen wie MandateFormRenderer::renderLegalText() das
// Mandatsformular. Der interne Rahmen-Marker darf dabei nie auftauchen.

const PFLICHTBLOCK = 'SEPA-Lastschriftmandat\n\nIch ermächtige {{creditor_name}}, Zahlungen einzuziehen.\n\nHinweis: Erstattung binnen acht Wochen.'

describe('legalTextParagraphs', () => {
	it('ersetzt den Platzhalter im Pflichtblock durch den Vereinsnamen', () => {
		const paragraphs = legalTextParagraphs(PFLICHTBLOCK, '', 'Musikverein Beispiel e.V.')

		expect(paragraphs).toContain('Ich ermächtige Musikverein Beispiel e.V., Zahlungen einzuziehen.')
		expect(paragraphs.join(' ')).not.toContain(CREDITOR_PLACEHOLDER)
	})

	it('ersetzt den Platzhalter auch im Rahmen und an mehreren Stellen', () => {
		const paragraphs = legalTextParagraphs('A {{creditor_name}} B', 'Rahmen für {{creditor_name}} und {{creditor_name}}.', 'Chor e.V.')

		expect(paragraphs).toEqual(['A Chor e.V. B', 'Rahmen für Chor e.V. und Chor e.V..'])
	})

	it('hängt den Rahmen als eigenen Absatz an', () => {
		const paragraphs = legalTextParagraphs(PFLICHTBLOCK, 'Wir ziehen im März ein.', 'Verein')

		expect(paragraphs).toHaveLength(4)
		expect(paragraphs[3]).toBe('Wir ziehen im März ein.')
	})

	it('lässt bei leerem Rahmen keinen leeren Absatz stehen', () => {
		expect(legalTextParagraphs(PFLICHTBLOCK, '', 'Verein')).toHaveLength(3)
		expect(legalTextParagraphs(PFLICHTBLOCK, '  \r\n ', 'Verein')).toHaveLength(3)
	})

	it('trennt Absätze im Rahmen an Leerzeilen und behält einfache Umbrüche', () => {
		const paragraphs = legalTextParagraphs('P', 'Zeile eins\nZeile zwei\n\n\nNeuer Absatz', 'Verein')

		expect(paragraphs).toEqual(['P', 'Zeile eins\nZeile zwei', 'Neuer Absatz'])
	})

	it('nimmt ohne Vereinsnamen dieselbe Ersatzformulierung wie das Mandatsformular', () => {
		expect(legalTextParagraphs('Ich ermächtige {{creditor_name}}.', '', '')[0]).toBe('Ich ermächtige den Verein.')
		expect(legalTextParagraphs('Ich ermächtige {{creditor_name}}.', '', '   ')[0]).toBe('Ich ermächtige den Verein.')
	})

	it('zeigt den internen Marker nie, auch wenn der Server ihn je mitschickte', () => {
		// Der Server liefert beide Teile ohne Marker; die Vorschau darf trotzdem nur
		// mit diesen Teilen rechnen - ein Marker im Eingabetext ist ein Fehler, kein Anzeigetext.
		const paragraphs = legalTextParagraphs(PFLICHTBLOCK, 'Normaler Rahmen', 'Verein')

		expect(paragraphs.join('\n')).not.toContain('vbh:')
		expect(paragraphs.join('\n')).not.toContain('<!--')
	})
})

describe('normalizeRahmen', () => {
	it('vereinheitlicht Zeilenenden und trimmt die Ränder', () => {
		expect(normalizeRahmen('\r\n  Eins\r\nZwei  \r\n')).toBe('Eins\nZwei')
		expect(normalizeRahmen('Eins\rZwei')).toBe('Eins\nZwei')
	})

	it('verträgt fehlende Werte', () => {
		expect(normalizeRahmen(null)).toBe('')
		expect(normalizeRahmen(undefined)).toBe('')
	})
})

describe('rahmenError', () => {
	it('akzeptiert normalen und leeren Text', () => {
		expect(rahmenError('')).toBeNull()
		expect(rahmenError('Ein Hinweis zum Datenschutz.')).toBeNull()
	})

	it('akzeptiert genau die Höchstlänge und lehnt ein Zeichen mehr ab', () => {
		expect(rahmenError('a'.repeat(MAX_RAHMEN_LENGTH))).toBeNull()
		expect(rahmenError('a'.repeat(MAX_RAHMEN_LENGTH + 1))).toContain(String(MAX_RAHMEN_LENGTH + 1))
	})

	it('zählt Zeichen, nicht UTF-16-Einheiten', () => {
		// Ein Emoji ist ein Zeichen, aber zwei UTF-16-Einheiten - wie mb_strlen() im Server.
		expect(rahmenError('😀'.repeat(MAX_RAHMEN_LENGTH))).toBeNull()
	})

	it('zählt den normalisierten Text (Ränder und CRLF zählen nicht mit)', () => {
		expect(rahmenError('\r\n' + 'a'.repeat(MAX_RAHMEN_LENGTH) + '\r\n')).toBeNull()
	})

	it('lehnt die für interne Marker reservierte Zeichenfolge ab', () => {
		const message = rahmenError(`Text\n${RESERVED_MARKER_PREFIX}rahmen -->\nMehr Text`)

		expect(message).not.toBeNull()
		expect(message).toContain(RESERVED_MARKER_PREFIX)
	})

	it('lässt gewöhnliche HTML-Kommentare zu', () => {
		expect(rahmenError('Text <!-- Notiz --> mehr')).toBeNull()
	})
})

describe('authorLabel', () => {
	it('benennt Verwalter und App-Update in Klartext', () => {
		expect(authorLabel('verwalter')).toBe('durch Verwalter')
		expect(authorLabel('system')).toBe('durch App-Update')
	})
})
