<template>
	<ol class="vbh-reimb-history" :aria-label="t('Verlauf')">
		<li v-for="event in events" :key="event.id || event.createdAt + event.action">
			<span class="vbh-reimb-history-when">{{ formatDateTime(event.createdAt) }}</span>
			<span class="vbh-reimb-history-what">{{ eventText(event) }}</span>
			<span v-if="event.comment" class="vbh-reimb-history-comment">{{ event.comment }}</span>
		</li>
	</ol>
</template>

<script>
import { formatDateTime } from '../lib/format.js'
import { reimbursementEventText } from '../lib/reimbursements.js'

/**
 * Verlauf eines Auslagenantrags. Das Mitglied sieht „Freigabe durch einen
 * Verwalter“, die Verwaltung die Namen (Design §5) - der Server liefert dem
 * Mitglied die Namen gar nicht erst, `staff` wählt nur den Satz.
 */
export default {
	name: 'ReimbursementHistory',
	props: {
		events: { type: Array, required: true },
		staff: { type: Boolean, default: false },
	},

	methods: {
		formatDateTime,

		eventText(event) { return reimbursementEventText(event, this.staff) },
	},
}
</script>

<style scoped>
.vbh-reimb-history { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }

.vbh-reimb-history li { display: grid; grid-template-columns: max-content 1fr; column-gap: 12px; row-gap: 2px; }

.vbh-reimb-history-when { color: var(--color-text-maxcontrast); font-variant-numeric: tabular-nums; }

.vbh-reimb-history-comment { grid-column: 2; color: var(--color-text-maxcontrast); font-style: italic; overflow-wrap: anywhere; }
</style>
