/**
 * Budget je Kostenstelle (Issue #335): reine Ableitungen für den Bericht und
 * den Dialog „Budget planen“.
 */

/**
 * Plan, Ist und Abweichung (Ist − Plan) einer Kostenstelle für Einnahmen,
 * Ausgaben und Ergebnis. Leer, wenn die Kostenstelle keinen Plan hat.
 *
 * @param {object} center Kostenstelle aus dem Bericht (incomeCents, expenseCents, resultCents, plan)
 * @return {Array<{key: string, planCents: number, actualCents: number, diffCents: number}>}
 */
export function planRows(center) {
	const plan = center?.plan
	if (!plan) { return [] }
	const planned = {
		income: plan.incomeCents || 0,
		expense: plan.expenseCents || 0,
	}
	planned.result = planned.income - planned.expense
	const actual = { income: center.incomeCents || 0, expense: center.expenseCents || 0, result: center.resultCents || 0 }
	return ['income', 'expense', 'result'].map((key) => ({
		key,
		planCents: planned[key],
		actualCents: actual[key],
		diffCents: actual[key] - planned[key],
	}))
}

/**
 * Zeilen des Dialogs: jede aktive Kostenstelle, dazu inaktive, die für den
 * Zeitraum einen Planwert tragen (sonst ließe er sich nicht mehr ändern).
 * Beträge in Euro, sortiert nach Kürzel.
 *
 * @param {Array} costCenters Kostenstellen (id, code, name, active)
 * @param {Array} budgets Planwerte des Zeitraums (costCenterId, incomeCents, expenseCents)
 * @return {Array<{id: number, code: string, name: string, active: boolean, income: number, expense: number}>}
 */
export function budgetRows(costCenters, budgets) {
	const byId = new Map((budgets || []).map((b) => [b.costCenterId, b]))
	return (costCenters || [])
		.filter((cc) => cc.active || byId.has(cc.id))
		.map((cc) => ({
			id: cc.id,
			code: cc.code,
			name: cc.name,
			active: !!cc.active,
			income: (byId.get(cc.id)?.incomeCents || 0) / 100,
			expense: (byId.get(cc.id)?.expenseCents || 0) / 100,
		}))
		.sort((a, b) => String(a.code).localeCompare(String(b.code), undefined, { numeric: true, sensitivity: 'base' }))
}
