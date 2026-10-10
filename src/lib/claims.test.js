import { describe, expect, it } from 'vitest'
import {
	cancelBlockText,
	claimActions,
	claimTypeTag,
	debitWarning,
	dunningCell,
	dunningCompact,
	dunningNextText,
	dunningStageLabel,
	dunningSteps,
	dunningStepText,
	emptyFilters,
	filterClaims,
	filtersChanged,
	groupByMember,
	isActionable,
	isUnsettled,
	severityMark,
	worstSeverity,
} from './claims.js'

// Helfer des Segments „Forderungen“ (Issue #104): Filter, Mahnstand in
// Klartext, Gruppierung je Mitglied und die zulässigen Aktionen. Die Texte der
// Mahnstufen und der Aktionssperren sind Teil der Abnahme, deshalb stehen die
// erwarteten Wörter hier ausgeschrieben.

const TODAY = '2026-10-10'

const NO_DUNNING = { stage: null, escalated: false, notices: [], nextStage: null, nextDueOn: null }

function claim(over = {}) {
	return {
		id: 1,
		memberId: 1,
		memberDisplayName: 'Anna Muster',
		type: 'beitrag',
		amountCents: 4500,
		dueDate: '2026-10-01',
		state: 'offen',
		settlementType: null,
		deferred: false,
		debit: null,
		returned: null,
		dunning: NO_DUNNING,
		issues: [],
		...over,
	}
}

describe('Filter', () => {
	const claims = [
		claim({ id: 1, state: 'offen', dueDate: '2026-09-01' }),
		claim({ id: 2, state: 'im_einzug', dueDate: '2026-10-15', memberId: 2, memberDisplayName: 'Bernd Beispiel' }),
		claim({ id: 3, state: 'zurueckgegeben', dueDate: '2026-10-01', issues: [{ severity: 'hinweis', message: 'x' }] }),
		claim({ id: 4, state: 'erledigt', settlementType: 'paid', dueDate: '2026-08-01' }),
		claim({ id: 5, state: 'storniert', dueDate: '2026-07-01' }),
		claim({ id: 6, state: 'eingezogen', dueDate: '2026-09-15', memberId: 2, memberDisplayName: 'Bernd Beispiel' }),
		claim({ id: 7, state: 'offen', deferred: true, dueDate: '2026-09-20', issues: [{ severity: 'handlungsbedarf', message: 'y' }] }),
	]
	const ids = (filters) => filterClaims(claims, { ...emptyFilters(), ...filters }).map((c) => c.id)

	it('zeigt vorgabemäßig, was noch aussteht – offen, im Einzug, zurückgegeben', () => {
		expect(ids({})).toEqual([1, 2, 3, 7])
	})

	it('kennt „Alle Zustände“ und jeden Zustand einzeln', () => {
		expect(ids({ state: 'alle' })).toHaveLength(7)
		expect(ids({ state: 'offen' })).toEqual([1, 7])
		expect(ids({ state: 'eingezogen' })).toEqual([6])
		expect(ids({ state: 'erledigt' })).toEqual([4])
		expect(ids({ state: 'storniert' })).toEqual([5])
	})

	it('findet gestundete Forderungen über den eigenen Filter, nicht über einen Zustand', () => {
		expect(ids({ state: 'gestundet' })).toEqual([7])
	})

	it('filtert nach Störfall und seinem Schweregrad', () => {
		expect(ids({ issue: 'mit' })).toEqual([3, 7])
		expect(ids({ issue: 'handlungsbedarf' })).toEqual([7])
		expect(ids({ issue: 'hinweis' })).toEqual([3])
		expect(ids({ issue: 'ohne' })).toEqual([1, 2])
	})

	it('filtert nach Mitgliedsname, ohne auf Groß-/Kleinschreibung zu achten', () => {
		expect(ids({ member: 'bernd' })).toEqual([2])
		expect(ids({ member: '  MUSTER ' })).toEqual([1, 3, 7])
	})

	it('filtert nach genau einem Mitglied, auch wenn ein anderes ähnlich heißt', () => {
		expect(ids({ memberId: 2, state: 'alle' })).toEqual([2, 6])
	})

	it('filtert nach Fälligkeit mit beiden Grenzen einschließlich', () => {
		expect(ids({ state: 'alle', from: '2026-09-15', to: '2026-10-01' })).toEqual([3, 6, 7])
		expect(ids({ state: 'alle', from: '2026-10-15' })).toEqual([2])
		expect(ids({ state: 'alle', to: '2026-07-01' })).toEqual([5])
	})

	it('schließt Forderungen ohne Fälligkeit aus, sobald ein Zeitraum gewählt ist', () => {
		const undated = [claim({ id: 9, dueDate: null })]
		expect(filterClaims(undated, { ...emptyFilters(), from: '2026-01-01' })).toEqual([])
		expect(filterClaims(undated, emptyFilters())).toHaveLength(1)
	})

	it('kombiniert die Filter', () => {
		expect(ids({ state: 'offen', issue: 'handlungsbedarf', member: 'anna', from: '2026-09-01' })).toEqual([7])
	})

	it('erkennt, ob ein Filter vom Vorgabewert abweicht', () => {
		expect(filtersChanged(emptyFilters())).toBe(false)
		expect(filtersChanged({ ...emptyFilters(), state: 'alle' })).toBe(true)
		expect(filtersChanged({ ...emptyFilters(), member: 'a' })).toBe(true)
	})
})

describe('Schweregrad', () => {
	it('nimmt den schwersten Störfall, Handlungsbedarf vor Hinweis', () => {
		expect(worstSeverity([])).toBeNull()
		expect(worstSeverity(undefined)).toBeNull()
		expect(worstSeverity([{ severity: 'hinweis' }])).toBe('hinweis')
		expect(worstSeverity([{ severity: 'hinweis' }, { severity: 'handlungsbedarf' }])).toBe('handlungsbedarf')
	})
})

describe('Mahnstand in Klartext', () => {
	const notices = [
		{ stage: 0, sentAt: '2026-10-01T08:00:00+00:00' },
		{ stage: 1, sentAt: '2026-10-15T08:00:00+00:00' },
	]

	it('benennt die vier Stufen wie die Spec', () => {
		expect(dunningStageLabel(0)).toBe('Zahlungsaufforderung')
		expect(dunningStageLabel(1)).toBe('Zahlungserinnerung')
		expect(dunningStageLabel(2)).toBe('Mahnung')
		expect(dunningStageLabel(3)).toBe('An Vorstand eskaliert')
	})

	it('zeigt in der Liste die höchste erreichte Stufe mit Versanddatum', () => {
		const compact = dunningCompact({ ...NO_DUNNING, stage: 1, notices })
		expect(compact.label).toBe('Zahlungserinnerung')
		expect(compact.detail).toBe('versandt am 15.10.2026')
		expect(compact.escalated).toBe(false)
	})

	it('zeigt ohne Versand nichts und bei einer Eskalation die Eskalation', () => {
		expect(dunningCompact(NO_DUNNING).label).toBe('')
		const escalated = dunningCompact({ ...NO_DUNNING, stage: 2, escalated: true, notices })
		expect(escalated.label).toBe('An Vorstand eskaliert')
		expect(escalated.escalated).toBe(true)
	})

	it('legt die Mahnreihe als vier Schritte mit Wort-Status aus', () => {
		const dunning = { stage: 0, escalated: false, notices: [notices[0]], nextStage: 1, nextDueOn: '2026-10-15' }
		const steps = dunningSteps(dunning)

		expect(steps.map((s) => s.status)).toEqual(['versandt', 'naechste', 'ausstehend', 'ausstehend'])
		expect(steps[1].dueOn).toBe('2026-10-15')
		expect(steps.map((s) => s.label)).toEqual(['Zahlungsaufforderung', 'Zahlungserinnerung', 'Mahnung', 'An Vorstand eskaliert'])
	})

	it('führt die Eskalation als letzten Schritt – erst „nächste“, dann „eskaliert“', () => {
		const pending = dunningSteps({ stage: 2, escalated: false, notices: [], nextStage: 3, nextDueOn: '2026-10-29' })
		expect(pending[3].status).toBe('naechste')
		expect(pending[3].dueOn).toBe('2026-10-29')

		const done = dunningSteps({ stage: 2, escalated: true, notices: [], nextStage: null, nextDueOn: null })
		expect(done[3].status).toBe('eskaliert')
	})

	it('erklärt jeden Schritt in einem Satz, auch den überfälligen', () => {
		const sent = { status: 'versandt', sentAt: '2026-10-01 08:00:00' }
		expect(dunningStepText(sent, TODAY)).toBe('versandt am 01.10.2026 08:00')
		expect(dunningStepText({ status: 'naechste', dueOn: '2026-10-15' }, TODAY)).toBe('fällig ab 15.10.2026 (in 5 Tagen)')
		expect(dunningStepText({ status: 'naechste', dueOn: '2026-10-08' }, TODAY)).toContain('fällig seit 08.10.2026')
		expect(dunningStepText({ status: 'ausstehend' }, TODAY)).toBe('noch nicht erreicht')
		expect(dunningStepText({ status: 'eskaliert' }, TODAY)).toBe('Aufgabe für den Vorstand besteht')
	})

	it('nennt die nächste Stufe knapp – oder nichts, wenn keine mehr kommt', () => {
		expect(dunningNextText({ ...NO_DUNNING, nextStage: 2, nextDueOn: '2026-10-29' })).toBe('Mahnung ab 29.10.2026')
		expect(dunningNextText({ ...NO_DUNNING, nextStage: 3, nextDueOn: '2026-10-29' })).toBe('An Vorstand eskaliert ab 29.10.2026')
		expect(dunningNextText(NO_DUNNING)).toBe('')
	})
})

describe('Aktionen', () => {
	it('lässt bei einer offenen Forderung alles zu', () => {
		expect(claimActions(claim())).toEqual({ settle: true, waive: true, defer: true, undefer: false, cancel: true, cancelBlock: null })
	})

	it('bietet bei einer laufenden Stundung das Aufheben statt einer zweiten Stundung', () => {
		const actions = claimActions(claim({ deferred: true }))
		expect(actions.defer).toBe(false)
		expect(actions.undefer).toBe(true)
	})

	it('sperrt Storno, sobald die Forderung in einem Lauf steckt, und sagt warum', () => {
		const submitted = claimActions(claim({ state: 'eingezogen', debit: { batchId: 1, status: 'eingereicht', dueDate: '2026-10-01' } }))
		expect(submitted.cancel).toBe(false)
		expect(submitted.cancelBlock).toBe('submitted')
		// Erlass und Erledigungsvermerk bleiben möglich – „jederzeit“.
		expect(submitted.waive).toBe(true)
		expect(submitted.settle).toBe(true)

		const released = claimActions(claim({ state: 'im_einzug', debit: { batchId: 1, status: 'freigegeben', dueDate: '2026-11-01' } }))
		expect(released.cancelBlock).toBe('released')
	})

	it('trennt in den Sperrtexten Storno scharf vom Erlass', () => {
		expect(cancelBlockText('submitted')).toContain('nur vor der Einreichung')
		expect(cancelBlockText('submitted')).toContain('Erlass')
		expect(cancelBlockText('released')).toContain('Verwerfen Sie zuerst den Lauf')
		expect(cancelBlockText(null)).toBe('')
	})

	it('lässt an erledigten und stornierten Forderungen nichts mehr zu', () => {
		for (const state of ['erledigt', 'storniert']) {
			const actions = claimActions(claim({ state }))
			expect(Object.values(actions).filter((v) => v === true)).toEqual([])
		}
	})

	it('unterscheidet „nicht beglichen“ von „es lässt sich noch etwas vermerken“', () => {
		expect(isUnsettled(claim({ state: 'eingezogen' }))).toBe(false)
		expect(isActionable(claim({ state: 'eingezogen' }))).toBe(true)
		expect(isActionable(claim({ state: 'erledigt' }))).toBe(false)
	})

	it('warnt, wenn ein Vermerk den Einzug nicht aufhält', () => {
		const released = claim({ state: 'im_einzug', debit: { batchId: 1, status: 'freigegeben', dueDate: '2026-11-01' } })
		expect(debitWarning(released, TODAY)).toContain('freigegebenen Lauf vom 01.11.2026')

		const submitted = claim({ state: 'im_einzug', debit: { batchId: 1, status: 'eingereicht', dueDate: '2026-11-01' } })
		expect(debitWarning(submitted, TODAY)).toContain('bereits bei der Bank eingereicht')

		// Termin vorbei oder Rücklastschrift: nichts mehr aufzuhalten, also keine Warnung.
		const past = claim({ state: 'eingezogen', debit: { batchId: 1, status: 'eingereicht', dueDate: '2026-10-01' } })
		expect(debitWarning(past, TODAY)).toBe('')
		const returned = claim({ state: 'zurueckgegeben', debit: { batchId: 1, status: 'eingereicht', dueDate: '2026-11-01' } })
		expect(debitWarning(returned, TODAY)).toBe('')
		expect(debitWarning(claim(), TODAY)).toBe('')
	})
})

describe('Je Mitglied', () => {
	it('bündelt Forderungen, Summe, höchste Stufe, letzten Versand und nächsten Schritt', () => {
		const claims = [
			claim({ id: 1, amountCents: 4500, dunning: { stage: 0, escalated: false, notices: [{ stage: 0, sentAt: '2026-10-01T08:00:00+00:00' }], nextStage: 1, nextDueOn: '2026-10-15' } }),
			claim({ id: 2, amountCents: 1500, dunning: { stage: 1, escalated: false, notices: [{ stage: 0, sentAt: '2026-09-01T08:00:00+00:00' }, { stage: 1, sentAt: '2026-09-15T08:00:00+00:00' }], nextStage: 2, nextDueOn: '2026-09-29' } }),
		]

		const [group] = groupByMember(claims)

		expect(group.count).toBe(2)
		expect(group.sumCents).toBe(6000)
		expect(group.stage).toBe(1)
		expect(group.lastSentAt).toBe('2026-10-01T08:00:00+00:00')
		expect(group.nextStage).toBe(2)
		expect(group.nextDueOn).toBe('2026-09-29')
	})

	it('zählt den Mahnstand nur für noch nicht erledigte Forderungen', () => {
		const settled = claim({ id: 1, state: 'erledigt', settlementType: 'paid', dunning: { stage: 2, escalated: true, notices: [{ stage: 2, sentAt: '2026-09-01T08:00:00+00:00' }], nextStage: null, nextDueOn: null } })
		const [group] = groupByMember([settled])

		expect(group.count).toBe(1)
		expect(group.stage).toBeNull()
		expect(group.escalated).toBe(false)
		expect(group.lastSentAt).toBeNull()
	})

	it('zählt gestundete Forderungen und den schwersten Störfall', () => {
		const [group] = groupByMember([
			claim({ id: 1, deferred: true }),
			claim({ id: 2, issues: [{ severity: 'hinweis', message: 'a' }] }),
			claim({ id: 3, issues: [{ severity: 'handlungsbedarf', message: 'b' }] }),
		])

		expect(group.deferredCount).toBe(1)
		expect(group.severity).toBe('handlungsbedarf')
	})

	it('stellt eskalierte Mitglieder und höhere Stufen nach oben, sonst nach Namen', () => {
		const escalated = { stage: 2, escalated: true, notices: [], nextStage: null, nextDueOn: null }
		const reminded = { stage: 1, escalated: false, notices: [], nextStage: null, nextDueOn: null }
		const groups = groupByMember([
			claim({ id: 1, memberId: 1, memberDisplayName: 'Anna', dunning: reminded }),
			claim({ id: 2, memberId: 2, memberDisplayName: 'Bernd', dunning: escalated }),
			claim({ id: 3, memberId: 3, memberDisplayName: 'Clara' }),
			claim({ id: 4, memberId: 4, memberDisplayName: 'Armin' }),
		])

		expect(groups.map((g) => g.name)).toEqual(['Bernd', 'Anna', 'Armin', 'Clara'])
	})
})

describe('Anzeigeregeln der Liste', () => {
	it('das Art-Etikett steht nur bei Abweichung vom Normalfall (Beitrag)', () => {
		expect(claimTypeTag('beitrag')).toBe('')
		expect(claimTypeTag('gebuehr')).toBe('Gebühr')
		expect(claimTypeTag(undefined)).toBe('')
	})

	it('die Störfall-Marke: Handlungsbedarf warnt, ein Hinweis tritt zurück, ohne Störfall keine Marke', () => {
		expect(severityMark('handlungsbedarf')).toEqual({ label: 'Handlungsbedarf', tone: 'warning', icon: 'alert' })
		expect(severityMark('hinweis')).toEqual({ label: 'Hinweis', tone: 'muted', icon: 'info' })
		expect(severityMark(null)).toBeNull()
	})

	it('Mahnstand: der Strich nur, wenn weder eine Stufe erreicht noch eine nächste in Sicht ist', () => {
		expect(dunningCell(NO_DUNNING)).toEqual({ label: '', detail: '', next: '', none: true })

		// Noch nichts versandt, die erste Stufe steht an: kein Strich, nur die nächste Stufe.
		const upcoming = dunningCell({ ...NO_DUNNING, nextStage: 0, nextDueOn: '2026-09-01' })
		expect(upcoming).toMatchObject({ label: '', detail: '', next: 'Zahlungsaufforderung ab 01.09.2026', none: false })

		// Stufe erreicht, nächste folgt: beide Angaben.
		const reached = dunningCell({
			stage: 0,
			escalated: false,
			notices: [{ stage: 0, sentAt: '2026-09-02 08:00:00' }],
			nextStage: 1,
			nextDueOn: '2026-09-16',
		})
		expect(reached).toMatchObject({ label: 'Zahlungsaufforderung', next: 'Zahlungserinnerung ab 16.09.2026', none: false })
		expect(reached.detail).toContain('02.09.2026')
	})
})
