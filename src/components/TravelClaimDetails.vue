<template>
	<section class="vbh-travel-details" :aria-label="t('Reiseangaben')">
		<h4 class="vbh-selfservice-subhead">
			{{ t('Reiseangaben') }}
		</h4>
		<dl class="vbh-selfservice-fields">
			<dt>{{ t('Reiseziel') }}</dt>
			<dd>{{ travel.destination }}</dd>
			<dt>{{ t('Reisezeit') }}</dt>
			<dd>{{ formatTravelPeriod(travel.start, travel.end) }}</dd>
		</dl>
		<ul class="vbh-travel-items">
			<li v-for="(item, index) in travel.items || []" :key="index" class="vbh-travel-item">
				<span class="vbh-travel-item-text">
					<span class="vbh-travel-item-type">{{ travelItemTypeLabel(item.type) }}</span>
					<span v-if="travelItemSummary(item)" class="vbh-travel-item-summary">{{ travelItemSummary(item) }}</span>
				</span>
				<span class="vbh-travel-item-amount">{{ formatMoney((item.amountCents || 0) / 100) }}</span>
			</li>
		</ul>
		<p v-if="corrected" class="vbh-travel-note">
			{{ t('Die Positionen stehen so, wie sie eingereicht wurden; der erstattete Betrag wurde korrigiert.') }}
		</p>
	</section>
</template>

<script>
import { formatMoney } from '../lib/format.js'
import { formatTravelPeriod, travelItemSummary, travelItemTypeLabel } from '../lib/travelClaims.js'

/**
 * Die Reiseangaben eines Antrags (Issue #353), nur lesend – in der Einzelansicht
 * für Mitglieder und Verwaltung. Die Positionen sind die eingereichten; eine
 * Korrektur des Betrags ändert sie nicht.
 */
export default {
	name: 'TravelClaimDetails',

	props: {
		/** Reiseangaben wie vom Server: destination, start, end, items[] */
		travel: { type: Object, required: true },
		/** Wurde der Betrag nachträglich korrigiert? Dann passt die Summe nicht mehr zum Betrag. */
		corrected: { type: Boolean, default: false },
	},

	methods: {
		formatMoney,
		formatTravelPeriod,
		travelItemSummary,
		travelItemTypeLabel,
	},
}
</script>

<style scoped>
.vbh-travel-items {
	display: grid;
	gap: 6px;
	margin: 8px 0;
	padding: 0;
	list-style: none;
}

.vbh-travel-item {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 12px;
	padding-block: 4px;
	border-bottom: 1px solid var(--color-border);
}

.vbh-travel-item-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.vbh-travel-item-type {
	font-weight: 600;
}

.vbh-travel-item-summary {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.vbh-travel-item-amount {
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.vbh-travel-note {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
