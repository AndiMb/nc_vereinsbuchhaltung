<template>
	<span
		v-if="enabled && allocations.length"
		class="vbh-allocbadge"
		:title="details"
		:aria-label="tRaw('Kostenstellen: {list}', { list: details })">{{ summary }}</span>
</template>

<script>
import { toRef } from 'vue'
import { useBookingCostCenters } from '../composables/useBookingCostCenters.js'
import { allocationSummary } from '../lib/allocation.js'
import { formatMoney } from '../lib/format.js'

/**
 * Etikett an einer Buchung, die Kostenstellen trägt (Issue #7): das Kürzel,
 * bei mehreren „SEN +1"; die vollständige Aufstellung steht im Tooltip und
 * im aria-label. Ohne Verteilung – und bei ausgeschaltetem Modul – erscheint
 * nichts, die Zeile sieht dann aus wie immer.
 */
export default {
	name: 'AllocationBadge',

	props: {
		// [{ costCenterId, amount }] über alle Zeilen der Buchung
		allocations: { type: Array, default: () => [] },
	},

	setup() {
		const costCenters = useBookingCostCenters()
		return {
			enabled: toRef(costCenters.state, 'enabled'),
			costCentersById: costCenters.bookingCostCentersById,
		}
	},

	computed: {
		/** Je Kostenstelle ein Eintrag, Beträge über die Zeilen summiert. */
		perCostCenter() {
			const sums = new Map()
			for (const a of this.allocations) {
				sums.set(a.costCenterId, (sums.get(a.costCenterId) || 0) + Math.round(Number(a.amount || 0) * 100))
			}
			return [...sums].map(([costCenterId, cents]) => ({ costCenterId, amount: cents / 100 }))
		},

		summary() { return allocationSummary(this.perCostCenter, this.costCentersById) },

		details() {
			return this.perCostCenter
				.map((a) => `${this.costCentersById[a.costCenterId]?.code ?? '#' + a.costCenterId} ${formatMoney(a.amount)}`)
				.join(', ')
		},
	},
}
</script>

<style scoped>
.vbh-allocbadge {
	display: inline-block;
	margin-inline-start: 6px;
	padding: 0 6px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.8em;
	line-height: 1.5;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
	vertical-align: baseline;
}
</style>
