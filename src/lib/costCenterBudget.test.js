import { describe, expect, it } from 'vitest'
import { budgetRows, planRows } from './costCenterBudget.js'

// Budget je Kostenstelle (Issue #335): die Zusammenfassung Plan / Ist /
// Abweichung im Bericht und die Zeilen des Dialogs „Budget planen“.

describe('planRows', () => {
	const center = { incomeCents: 220000, expenseCents: 195000, resultCents: 25000, plan: { incomeCents: 250000, expenseCents: 180000, resultCents: 70000 } }

	it('liefert Einnahmen, Ausgaben, Ergebnis mit Abweichung', () => {
		expect(planRows(center)).toEqual([
			{ key: 'income', planCents: 250000, actualCents: 220000, diffCents: -30000 },
			{ key: 'expense', planCents: 180000, actualCents: 195000, diffCents: 15000 },
			{ key: 'result', planCents: 70000, actualCents: 25000, diffCents: -45000 },
		])
	})

	it('ist leer ohne Plan', () => {
		expect(planRows({ ...center, plan: null })).toEqual([])
		expect(planRows({ incomeCents: 1, expenseCents: 2, resultCents: -1 })).toEqual([])
	})

	it('rechnet das Ergebnis des Plans als Einnahmen minus Ausgaben', () => {
		// Auch wenn der Server resultCents einmal nicht mitschickt.
		const rows = planRows({ incomeCents: 0, expenseCents: 0, resultCents: 0, plan: { incomeCents: 1000, expenseCents: 4000 } })
		expect(rows[2]).toEqual({ key: 'result', planCents: -3000, actualCents: 0, diffCents: 3000 })
	})
})

describe('budgetRows', () => {
	const costCenters = [
		{ id: 3, code: 'SEN', name: 'Senioren', active: true },
		{ id: 5, code: 'AJU', name: 'A-Junioren', active: true },
		{ id: 9, code: 'ALT', name: 'Alte Herren', active: false },
		{ id: 11, code: 'BAM', name: 'Bambini', active: false },
	]
	const budgets = [
		{ costCenterId: 3, incomeCents: 250000, expenseCents: 180000 },
		{ costCenterId: 9, incomeCents: 0, expenseCents: 50000 },
	]

	it('nimmt aktive Kostenstellen und inaktive mit Planwert', () => {
		expect(budgetRows(costCenters, budgets).map((r) => r.code)).toEqual(['AJU', 'ALT', 'SEN'])
	})

	it('lässt inaktive ohne Planwert weg', () => {
		expect(budgetRows(costCenters, budgets).some((r) => r.code === 'BAM')).toBe(false)
	})

	it('sortiert nach Kürzel', () => {
		const rows = budgetRows([{ id: 1, code: 'b2', name: '', active: true }, { id: 2, code: 'A10', name: '', active: true }, { id: 3, code: 'a2', name: '', active: true }], [])
		expect(rows.map((r) => r.code)).toEqual(['a2', 'A10', 'b2'])
	})

	it('trägt die Planwerte in Euro, ohne Plan 0', () => {
		const rows = budgetRows(costCenters, budgets)
		expect(rows.find((r) => r.code === 'SEN')).toMatchObject({ id: 3, name: 'Senioren', active: true, income: 2500, expense: 1800 })
		expect(rows.find((r) => r.code === 'AJU')).toMatchObject({ income: 0, expense: 0 })
	})
})
