import { describe, expect, it } from 'vitest'
import { footerKind, moved, narrow, newGroup, newLevel, newSection, outline, validate, withLevelBy } from './reportDefinition.js'

// Dieselben Fälle wie tests/unit/ReportDefinitionTest.php: Client und Server
// sollen eine Vorlage gleich beurteilen.
function minimal(override = {}) {
	return {
		columns: [{ source: 'actual', periodOffset: 0 }],
		groups: [],
		sections: [newSection('Alles')],
		grandTotal: null,
		...override,
	}
}

describe('reportDefinition', () => {
	it('akzeptiert eine Minimalvorlage', () => {
		expect(validate(minimal())).toEqual([])
	})

	it('prüft die Spalten', () => {
		expect(validate(minimal({ columns: [] }))).toContain('Die Vorlage braucht mindestens eine Spalte.')
		expect(validate(minimal({ columns: [{ source: 'forecast', periodOffset: 0 }] }))).toContain('Unbekannte Spaltenart „forecast“.')
		expect(validate(minimal({ columns: [{ source: 'plan', periodOffset: -4 }] }))).toContain('Eine Spalte kann höchstens 3 Perioden zurückliegen.')
		expect(validate(minimal({ columns: Array(9).fill({ source: 'plan', periodOffset: 0 }) }))).toContain('Höchstens 8 Spalten sind möglich.')
	})

	it('lässt „Konto“ nur als letzte Ebene zu und jedes Merkmal nur einmal', () => {
		const section = { ...newSection(), levels: [newLevel('account'), newLevel('type')] }
		expect(validate(minimal({ sections: [section] }))).toContain('„Konto“ kann nur die letzte Gliederungsebene sein.')
		const twice = { ...newSection(), levels: [newLevel('group'), newLevel('group')] }
		expect(validate(minimal({ sections: [twice] }))).toContain('Jedes Merkmal darf in einem Abschnitt nur eine Gliederungsebene bilden.')
	})

	it('verlangt genau einen Inhalt je Abschnitt', () => {
		expect(validate(minimal({ sections: [{ ...newSection(), sections: [] }] })))
			.toContain('Ein Abschnitt enthält entweder Unterabschnitte oder Gliederungsebenen.')
		expect(validate(minimal({ sections: [{ ...newSection(), levels: [] }] })))
			.toContain('Ein Abschnitt braucht mindestens eine Gliederungsebene.')
		expect(validate(minimal({ sections: [{ ...newSection(), levels: null, sections: [] }] })))
			.toContain('Ein Abschnitt mit Unterabschnitten braucht mindestens einen davon.')
		expect(validate(minimal({ sections: [] }))).toContain('Die Vorlage braucht mindestens einen Abschnitt.')
	})

	it('erlaubt höchstens vier Ebenen Abschnitte', () => {
		const nest = (inner) => ({ ...newSection(), levels: null, sections: [inner] })
		expect(validate(minimal({ sections: [nest(nest(nest(newSection())))] }))).toEqual([])
		expect(validate(minimal({ sections: [nest(nest(nest(nest(newSection()))))] })))
			.toContain('Abschnitte lassen sich höchstens 4 Ebenen tief verschachteln.')
	})

	it('prüft die Berichtsgruppen', () => {
		const errors = validate(minimal({
			groups: [
				{ key: 'a', label: 'Alpha', costCenters: ['51'], accounts: [7] },
				{ key: 'a', label: 'Alpha 2', costCenters: ['51'], accounts: [7] },
				{ key: 'mit leerzeichen', label: '', costCenters: [], accounts: [] },
			],
		}))
		expect(errors).toContain('Die Berichtsgruppe „Alpha 2“ ist doppelt angelegt.')
		expect(errors).toContain('Die Auswertungsgruppe „51“ ist mehreren Berichtsgruppen zugeordnet.')
		expect(errors).toContain('Ein Konto ist mehreren Berichtsgruppen zugeordnet.')
		expect(errors).toContain('Ungültiger Schlüssel einer Berichtsgruppe.')
		expect(errors).toContain('Jede Berichtsgruppe braucht einen Namen.')
	})

	it('vergibt freie Gruppenschlüssel', () => {
		expect(newGroup([]).key).toBe('g1')
		expect(newGroup([{ key: 'g2' }]).key).toBe('g3')
		expect(newGroup([{ key: 'g1' }, { key: 'g3' }]).key).toBe('g4')
	})

	it('wählt Summe oder Ergebnis nach den erlaubten Kontoarten', () => {
		expect(footerKind(null)).toBe('result')
		expect(footerKind(['income', 'expense'])).toBe('result')
		expect(footerKind(['income'])).toBe('sum')
		expect(narrow(['income', 'expense'], ['expense'])).toEqual(['expense'])
		expect(narrow(null, [])).toBeNull()
	})

	it('verschiebt Einträge und lässt die Enden stehen', () => {
		expect(moved(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c'])
		expect(moved(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b'])
		const list = ['a', 'b']
		expect(moved(list, 0, -1)).toBe(list)
	})

	it('passt die Zusatzfelder an das Merkmal an', () => {
		expect(withLevelBy(newLevel('account'), 'type')).toEqual({ by: 'type', footer: null, hideEmpty: true })
		expect(withLevelBy(newLevel('type'), 'account')).toEqual({ by: 'account', footer: null, hideEmpty: true, depth: 'plan', ancestors: false })
	})

	it('zeichnet die Gliederung als Skelett', () => {
		const def = minimal({
			sections: [{
				...newSection('Einnahmen'),
				filter: { types: ['income'], spheres: null, groups: null },
				levels: [newLevel('group'), newLevel('account')],
				footer: { label: 'Gesamteinnahmen' },
			}],
			grandTotal: { label: 'Saldo' },
		})
		expect(outline(def)).toEqual([
			{ depth: 0, text: 'Einnahmen', kind: 'section' },
			{ depth: 1, text: 'je Gruppe', kind: 'level' },
			{ depth: 2, text: 'Konten', kind: 'level' },
			{ depth: 0, text: 'Gesamteinnahmen', kind: 'sum' },
			{ depth: 0, text: 'Saldo', kind: 'result' },
		])
	})
})
