<template>
	<section class="vbh-visual-dist">
		<h5 class="vbh-visual-dist__title">
			{{ title }}
		</h5>
		<p v-if="total === 0" class="vbh-empty">
			{{ t('Keine Konten in dieser Gruppe.') }}
		</p>
		<template v-else>
			<div class="vbh-visual-dist__bar">
				<button
					v-for="entry in visibleEntries"
					:key="entry.level"
					type="button"
					class="vbh-visual-dist__segment"
					:class="[`vbh-visual-tone--${entry.meta.tone}`, { 'is-active': active === entry.level }]"
					:style="{ flexGrow: entry.count }"
					:aria-pressed="active === entry.level ? 'true' : 'false'"
					:aria-label="entry.meta.label + ': ' + entry.count"
					@click="toggle(entry.level)" />
			</div>
			<ul class="vbh-visual-dist__legend">
				<li v-for="entry in entries" :key="entry.level">
					<button
						type="button"
						class="vbh-visual-dist__legend-item"
						:class="[`vbh-visual-tone--${entry.meta.tone}`, { 'is-active': active === entry.level }]"
						:aria-pressed="active === entry.level ? 'true' : 'false'"
						@click="toggle(entry.level)">
						<span class="vbh-visual-dist__icon" aria-hidden="true">{{ entry.meta.icon }}</span>
						<span>{{ entry.meta.label }}</span>
						<span class="vbh-visual-dist__count">{{ entry.count }}</span>
					</button>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import { statusMeta } from '../lib/budgetVisual.js'

const ORDER = ['crit', 'unplanned', 'warn', 'good']

/**
 * Verteilung der Konten auf die Ampelstufen als Balken mit Legende. Segmente
 * und Legende sind Schalter: ein Klick wählt die Stufe, ein zweiter auf die
 * aktive Stufe hebt die Auswahl wieder auf (`select` mit null).
 */
export default {
	name: 'BudgetStatusDistribution',
	props: {
		title: { type: String, default: '' },
		nature: { type: String, default: 'expense' },
		counts: { type: Object, required: true },
		active: { type: String, default: null },
	},

	emits: ['select'],

	computed: {
		entries() {
			return ORDER.map((level) => ({
				level,
				count: Number(this.counts[level]) || 0,
				meta: statusMeta(level, this.nature),
			}))
		},

		visibleEntries() {
			return this.entries.filter((entry) => entry.count > 0)
		},

		total() {
			return this.entries.reduce((sum, entry) => sum + entry.count, 0)
		},
	},

	methods: {
		toggle(level) {
			this.$emit('select', this.active === level ? null : level)
		},
	},
}
</script>

<style scoped>
.vbh-visual-dist { min-width: 0; }

.vbh-visual-dist__title { margin: 0 0 8px; font-weight: 600; }

.vbh-visual-dist__bar {
	display: flex;
	gap: 2px;
	height: 24px;
	overflow: hidden;
	border-radius: 6px;
	background-color: var(--color-background-dark);
}

.vbh-visual-dist__segment {
	flex: 1 1 0;
	min-width: 6px;
	height: 100%;
	padding: 0;
	border: 0;
	border-radius: 0;
	background-color: var(--vbh-visual-tone, var(--color-text-maxcontrast));
	cursor: pointer;
}

.vbh-visual-dist__segment.vbh-visual-tone--serious {
	background-image: repeating-linear-gradient(45deg, var(--color-main-background) 0 2px, transparent 2px 5px);
}

.vbh-visual-dist__segment.is-active { outline: 2px solid var(--color-main-text); outline-offset: -2px; }

.vbh-visual-dist__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 12px;
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.vbh-visual-dist__legend-item {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 2px 8px;
	border: 2px solid var(--vbh-visual-tone, var(--color-text-maxcontrast));
	border-radius: 10px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.vbh-visual-dist__legend-item.vbh-visual-tone--serious { border-style: dashed; }

.vbh-visual-dist__legend-item.is-active { background-color: var(--color-background-dark); font-weight: 700; }

.vbh-visual-dist__icon { color: var(--vbh-visual-tone, var(--color-text-maxcontrast)); font-weight: 700; }

.vbh-visual-dist__count { font-variant-numeric: tabular-nums; font-weight: 700; }

.vbh-visual-tone--good { --vbh-visual-tone: var(--color-element-success); }

.vbh-visual-tone--warn { --vbh-visual-tone: var(--color-element-warning); }

.vbh-visual-tone--crit { --vbh-visual-tone: var(--color-element-error); }

.vbh-visual-tone--serious { --vbh-visual-tone: var(--color-element-error); }

.vbh-visual-tone--none { --vbh-visual-tone: var(--color-text-maxcontrast); }
</style>
