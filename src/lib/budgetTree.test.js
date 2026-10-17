import { describe, expect, it } from 'vitest'
import { allExpandable, attentionCounts, initialExpanded, visibleRows } from './budgetTree.js'

// 5 → 544 → 544 01 (Plan) → 544 01 01 / 544 01 02; daneben 546 mit Ist ohne Plan.
const rows = [
	{ accountId: 1, treeParentId: null, hasChildren: true, expanded: true, flags: [] },
	{ accountId: 2, treeParentId: 1, hasChildren: true, expanded: true, flags: [] },
	{ accountId: 3, treeParentId: 2, hasChildren: true, expanded: false, flags: ['overrun'] },
	{ accountId: 4, treeParentId: 3, hasChildren: false, expanded: false, flags: [] },
	{ accountId: 5, treeParentId: 3, hasChildren: false, expanded: false, flags: [] },
	{ accountId: 6, treeParentId: 1, hasChildren: true, expanded: false, flags: ['unplannedBelow'] },
	{ accountId: 7, treeParentId: 6, hasChildren: false, expanded: false, flags: ['unplanned'] },
]
const ids = (list) => list.map((r) => r.accountId)

describe('budgetTree', () => {
	it('öffnet bis zur Planungsebene', () => {
		expect(ids(visibleRows(rows, initialExpanded(rows)))).toEqual([1, 2, 3, 6])
	})

	it('zeigt beim Aufklappen alle Unterkonten', () => {
		expect(ids(visibleRows(rows, allExpandable(rows)))).toEqual([1, 2, 3, 4, 5, 6, 7])
	})

	it('zeigt eingeklappt nur die Wurzeln', () => {
		expect(ids(visibleRows(rows, new Set()))).toEqual([1])
	})

	it('filtert auf Auffälligkeiten samt Vorfahren, auch in eingeklappten Zweigen', () => {
		expect(ids(visibleRows(rows, new Set(), true))).toEqual([1, 2, 3, 6, 7])
	})

	it('zählt Auffälligkeiten', () => {
		expect(attentionCounts(rows)).toEqual({ overrun: 1, unplanned: 1, conflict: 0 })
	})
})
