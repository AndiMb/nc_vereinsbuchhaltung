<template>
	<section class="vbh-visual-dev">
		<h5 class="vbh-visual-dev__title">
			{{ title }}
		</h5>
		<p v-if="items.length === 0" class="vbh-empty">
			{{ emptyText }}
		</p>
		<ul v-else class="vbh-visual-dev__list">
			<li v-for="item in items" :key="item.accountId" class="vbh-visual-dev__item">
				<component
					:is="linkable ? 'button' : 'span'"
					:type="linkable ? 'button' : null"
					class="vbh-visual-dev__entry"
					:class="{ 'vbh-visual-dev__entry--link': linkable }"
					@click="linkable && $emit('open-account', item.accountId)">
					<BudgetStatusPill :level="item.level" :nature="nature" compact />
					<span class="vbh-visual-dev__label">{{ item.number }} {{ item.label }}</span>
					<span class="vbh-visual-dev__amount">{{ money(item.amount) }}</span>
				</component>
			</li>
		</ul>
	</section>
</template>

<script>
import BudgetStatusPill from './BudgetStatusPill.vue'
import { formatSigned } from '../lib/format.js'

/**
 * Liste der größten Abweichungen. Nur mit `linkable` sind die Einträge
 * Schaltflächen, die `open-account` auslösen; sonst bleiben sie reiner Text.
 * Beträge (Abweichung) in Cent.
 */
export default {
	name: 'BudgetDeviationList',
	components: { BudgetStatusPill },
	props: {
		title: { type: String, default: '' },
		nature: { type: String, default: 'expense' },
		items: { type: Array, default: () => [] },
		emptyText: { type: String, default: '' },
		linkable: { type: Boolean, default: false },
	},

	emits: ['open-account'],

	methods: {
		money(cents) {
			return formatSigned(cents / 100)
		},
	},
}
</script>

<style scoped>
.vbh-visual-dev { min-width: 0; }

.vbh-visual-dev__title { margin: 0 0 8px; font-weight: 600; }

.vbh-visual-dev__list { margin: 0; padding: 0; list-style: none; }

.vbh-visual-dev__entry {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 4px 0;
	border: 0;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
}

.vbh-visual-dev__entry--link { cursor: pointer; border-radius: var(--border-radius); }

.vbh-visual-dev__entry--link:hover { background-color: var(--color-background-hover); }

.vbh-visual-dev__label { flex: 1 1 auto; min-width: 0; overflow-wrap: break-word; hyphens: auto; }

.vbh-visual-dev__amount { flex: 0 0 auto; font-variant-numeric: tabular-nums; font-weight: 600; }
</style>
