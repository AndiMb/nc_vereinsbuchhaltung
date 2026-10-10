import { describe, expect, it } from 'vitest'
import { actionCount, groupTasks, hintCount, SEVERITY_ACTION, SEVERITY_HINT, sortTasks, targetLabel, taskKindLabel, taskKindTitle, taskTarget } from './tasks.js'

// Aufgaben-Flyout (Issue #99): was die Oberflaeche aus der Antwort von
// GET /api/tasks macht - sortieren, zaehlen, einordnen, Sprungziel. Der Text
// selbst kommt fertig vom Server und wird hier nicht angefasst.

const task = (over) => ({ id: 1, severity: SEVERITY_ACTION, message: 'x', objectType: null, objectId: null, memberId: null, ...over })

describe('sortTasks', () => {
	it('stellt Handlungsbedarf vor Hinweise und haelt die Reihenfolge des Servers sonst ein', () => {
		const sorted = sortTasks([
			task({ id: 'h1', severity: SEVERITY_HINT }),
			task({ id: 'a1' }),
			task({ id: 'h2', severity: SEVERITY_HINT }),
			task({ id: 'a2' }),
		])
		expect(sorted.map((t) => t.id)).toEqual(['a1', 'a2', 'h1', 'h2'])
	})

	it('veraendert die Eingabe nicht', () => {
		const input = [task({ id: 'h', severity: SEVERITY_HINT }), task({ id: 'a' })]
		sortTasks(input)
		expect(input.map((t) => t.id)).toEqual(['h', 'a'])
	})
})

describe('Zaehler', () => {
	const tasks = [task({}), task({ severity: SEVERITY_HINT }), task({}), task({ severity: SEVERITY_HINT }), task({ severity: SEVERITY_HINT })]

	it('das Badge zaehlt nur Handlungsbedarf, Hinweise zaehlen separat', () => {
		expect(actionCount(tasks)).toBe(2)
		expect(hintCount(tasks)).toBe(3)
	})

	it('eine leere Liste zaehlt null', () => {
		expect(actionCount([])).toBe(0)
		expect(hintCount([])).toBe(0)
	})
})

describe('taskTarget', () => {
	it('Mitglied, Mandat und Zuweisung fuehren in die Akte, sobald das Mitglied bekannt ist', () => {
		expect(taskTarget(task({ objectType: 'member', objectId: 4, memberId: 4 }))).toEqual({ kind: 'member', memberId: 4 })
		expect(taskTarget(task({ objectType: 'mandate', objectId: 9, memberId: 4 }))).toEqual({ kind: 'member', memberId: 4 })
		expect(taskTarget(task({ objectType: 'assignment', objectId: 9, memberId: 4 }))).toEqual({ kind: 'member', memberId: 4 })
	})

	it('eine Forderung fuehrt in die Akte des Mitglieds, ohne auffindbares Mitglied in den Einzug', () => {
		expect(taskTarget(task({ objectType: 'claim', objectId: 9, memberId: 4 }))).toEqual({ kind: 'member', memberId: 4 })
		expect(taskTarget(task({ objectType: 'claim', objectId: 9, memberId: null }))).toEqual({ kind: 'batch' })
	})

	it('ein Mitglied ohne Akte (geloescht, Datenuebernahme) fuehrt in die Mitgliederliste', () => {
		expect(taskTarget(task({ objectType: 'member', objectId: null, memberId: null }))).toEqual({ kind: 'members' })
	})

	it('Laeufe und aggregierte Einzug-Aufgaben fuehren in den Einzug-Unterreiter', () => {
		expect(taskTarget(task({ objectType: 'debit_batch', objectId: 3 }))).toEqual({ kind: 'batch' })
		expect(taskTarget(task({ objectType: null }))).toEqual({ kind: 'batch' })
		expect(taskTarget({ severity: SEVERITY_HINT, message: 'x' })).toEqual({ kind: 'batch' })
	})

	it('die Mandat-Aufgaben des Katalogs springen mit ihrer memberId in die Akte', () => {
		// Entwurf, gesperrt, erloschen, ohne Nachweis, verfaellt, ausgetreten: alle objectType 'mandate'.
		const mandateTask = task({ objectType: 'mandate', objectId: 12, memberId: 7, severity: SEVERITY_HINT })
		expect(taskTarget(mandateTask)).toEqual({ kind: 'member', memberId: 7 })
		expect(targetLabel(taskTarget(mandateTask))).toBe('Zur Akte')
	})

	it('aggregierte Forderungs-Aufgaben (Ruecklastschrift, Widerruf) fuehren in den Einzug, nicht in eine Akte', () => {
		const aggregated = task({ objectType: 'claims', objectId: null, memberId: null })
		expect(taskTarget(aggregated)).toEqual({ kind: 'batch' })
		expect(targetLabel(taskTarget(aggregated))).toBe('Zum Einzug')
	})

	it('ein Mandat oder eine Zuweisung ohne auffindbares Mitglied und unbekannte Typen haben kein Ziel', () => {
		expect(taskTarget(task({ objectType: 'mandate', objectId: 9, memberId: null }))).toBeNull()
		expect(taskTarget(task({ objectType: 'assignment', objectId: 9, memberId: null }))).toBeNull()
		expect(taskTarget(task({ objectType: 'irgendwas', objectId: 9, memberId: 4 }))).toBeNull()
	})

	it('eine memberId, die keine ganze Zahl ist, zaehlt nicht', () => {
		expect(taskTarget(task({ objectType: 'mandate', objectId: 9, memberId: '4' }))).toBeNull()
	})
})

describe('Beschriftungen', () => {
	it('ordnet jeder Aufgabe einen Bereich zu', () => {
		expect(taskKindLabel(task({ objectType: 'member' }))).toBe('Mitglied')
		expect(taskKindLabel(task({ objectType: 'mandate' }))).toBe('Mandat')
		expect(taskKindLabel(task({ objectType: 'assignment' }))).toBe('Zuweisung')
		expect(taskKindLabel(task({ objectType: 'claim' }))).toBe('Forderung')
		expect(taskKindLabel(task({ objectType: 'claims' }))).toBe('Forderungen')
		expect(taskKindLabel(task({ objectType: 'debit_batch' }))).toBe('Einzug')
		expect(taskKindLabel(task({ objectType: null }))).toBe('Einzug')
	})

	it('beschriftet den Sprung nach seinem Ziel', () => {
		expect(targetLabel({ kind: 'member', memberId: 1 })).toBe('Zur Akte')
		expect(targetLabel({ kind: 'members' })).toBe('Zur Mitgliederliste')
		expect(targetLabel({ kind: 'batch' })).toBe('Zum Einzug')
	})
})

describe('groupTasks', () => {
	const late = (id, over = {}) => task({ id, kind: 'prenotification_late', objectType: 'claim', objectId: id, ...over })

	it('fasst gleichartige Meldungen ab drei zu einer Gruppe zusammen und behält ihre Reihenfolge', () => {
		const list = groupTasks([task({ id: 'a' }), late(1), late(2), late(3), task({ id: 'b' })])

		expect(list.map((e) => e.type)).toEqual(['task', 'group', 'task'])
		const group = list[1]
		expect(group.title).toBe('Vorabinfo nicht rechtzeitig verschickt')
		expect(group.tasks.map((t) => t.id)).toEqual([1, 2, 3])
		expect(group.severity).toBe(SEVERITY_ACTION)
	})

	it('lässt zwei gleichartige Meldungen als Einzelzeilen stehen', () => {
		const list = groupTasks([late(1), late(2)])
		expect(list.map((e) => e.type)).toEqual(['task', 'task'])
	})

	it('gruppiert nur Meldungen mit bekannter Typkennung und gleichem Schweregrad', () => {
		const list = groupTasks([
			late(1),
			late(2),
			late(3, { severity: SEVERITY_HINT }),
			task({ id: 'x', kind: 'unbekannt' }),
			task({ id: 'y', kind: 'unbekannt' }),
			task({ id: 'z', kind: 'unbekannt' }),
		])
		expect(list.every((e) => e.type === 'task')).toBe(true)
	})

	it('setzt die Gruppe an die Stelle der ersten Meldung, auch wenn andere dazwischen stehen', () => {
		const list = groupTasks([late(1), task({ id: 'mitte' }), late(2), late(3)])
		expect(list.map((e) => (e.type === 'group' ? 'G' : e.task.id))).toEqual(['G', 'mitte'])
	})

	it('kennt die Überschriften der Typkennungen und sonst keine', () => {
		expect(taskKindTitle('mandate_without_proof')).toBe('Mandat ohne Nachweis')
		expect(taskKindTitle('irgendwas')).toBeNull()
	})
})
