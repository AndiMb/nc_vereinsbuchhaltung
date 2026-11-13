/**
 * Wie eine Importregel einen Umsatz aufteilt (Issue #163) – das Gegenstueck
 * zu lib/Service/RuleSplit.php, damit der Vorschlag in „Zuzuordnen“ genau das
 * zeigt, was der Import gebucht haette.
 *
 * Erst die festen Anteile in ihrer Reihenfolge, dann der Rest aufs
 * Gegenkonto mit der Kostenstelle der Regel. Ein Rest von 0 ergibt keinen
 * Teil. Reicht der Umsatz nicht fuer alle Anteile, kommt null zurueck.
 *
 * @param {{contraAccountId: number, costCenterId?: number|null, parts?: Array<{accountId: number, amount: number, costCenterId?: number|null}>}} rule
 *        Regel wie von GET /rules (Anteile in Euro)
 * @param {number} amountCents Umsatzbetrag in Cent; das Vorzeichen zaehlt nicht
 * @return {Array<{accountId: number, amountCents: number, costCenterId: number|null}>|null}
 */
export function ruleSplitParts(rule, amountCents) {
	const total = Math.abs(amountCents)
	const parts = (rule.parts || []).map((p) => ({
		accountId: p.accountId,
		// Je Anteil auf Cent runden, wie der Server beim Speichern.
		amountCents: Math.round(Number(p.amount) * 100),
		costCenterId: p.costCenterId ?? null,
	}))
	const rest = total - parts.reduce((sum, p) => sum + p.amountCents, 0)
	if (rest < 0) { return null }
	if (rest > 0 || parts.length === 0) {
		parts.push({ accountId: rule.contraAccountId, amountCents: rest, costCenterId: rule.costCenterId ?? null })
	}
	return parts
}
