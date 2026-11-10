<template>
	<section class="vbh-visual-tile">
		<h5 class="vbh-visual-tile__title">
			{{ title }}
		</h5>
		<div class="vbh-visual-tile__head">
			<span class="vbh-visual-tile__actual">{{ money(kpi.actual) }}</span>
			<BudgetStatusPill :level="status.level" :nature="nature" />
		</div>
		<BudgetBullet
			:plan="kpi.plan"
			:actual="kpi.actual"
			:timeFactor="timeFactor"
			:tone="meta.tone" />
		<dl class="vbh-visual-tile__rows">
			<dt>{{ t('Ist') }}</dt>
			<dd>{{ money(kpi.actual) }}</dd>
			<dt>{{ t('Plan') }}</dt>
			<dd>{{ money(kpi.plan) }}</dd>
			<template v-if="nature === 'result'">
				<dt>{{ t('Differenz') }}</dt>
				<dd>{{ signedMoney(kpi.actual - kpi.plan) }}</dd>
			</template>
			<template v-else>
				<dt>{{ t('Ausschöpfung') }}</dt>
				<dd>{{ utilisation }}</dd>
			</template>
			<template v-if="timeFactor">
				<dt>{{ t('Zeitanteiliger Plan') }}</dt>
				<dd>{{ money(kpi.plan * timeFactor) }}</dd>
			</template>
			<template v-if="kpi.forecast !== null && kpi.forecast !== undefined">
				<dt>{{ t('Hochrechnung') }}</dt>
				<dd>{{ money(kpi.forecast) }}</dd>
			</template>
		</dl>
		<p v-if="kpi.forecast !== null && kpi.forecast !== undefined" class="vbh-hint vbh-visual-tile__hint">
			{{ t('Lineare Fortschreibung; saisonale Posten verzerren.') }}
		</p>
	</section>
</template>

<script>
import BudgetBullet from './BudgetBullet.vue'
import BudgetStatusPill from './BudgetStatusPill.vue'
import { statusMeta } from '../lib/budgetVisual.js'
import { formatMoney, formatSigned } from '../lib/format.js'

/**
 * Kennzahlen-Kachel für eine Art (Einnahmen, Ausgaben, Ergebnis): Ist, Plan,
 * Ausschöpfung, Ampel, zeitanteiliger Plan und Hochrechnung. Ausschöpfung und
 * Ampel folgen der gewählten Vergleichsbasis. Beträge in Cent.
 */
export default {
	name: 'BudgetKpiTile',
	components: { BudgetBullet, BudgetStatusPill },
	props: {
		title: { type: String, default: '' },
		nature: { type: String, default: 'expense' },
		kpi: { type: Object, required: true },
		basis: { type: String, default: 'year', validator: (v) => ['year', 'time'].includes(v) },
		timeFactor: { type: Number, default: null },
	},

	computed: {
		status() {
			return this.kpi.status?.[this.basis] ?? { level: 'none', ratio: null }
		},

		meta() {
			return statusMeta(this.status.level, this.nature)
		},

		utilisation() {
			const ratio = this.status.ratio
			return ratio === null || ratio === undefined ? '–' : Math.round(ratio * 100) + ' %'
		},
	},

	methods: {
		money(cents) {
			return formatMoney(cents / 100)
		},

		signedMoney(cents) {
			return formatSigned(cents / 100)
		},
	},
}
</script>

<style scoped>
.vbh-visual-tile {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
	padding: 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.vbh-visual-tile__title { margin: 0; font-weight: 600; }

.vbh-visual-tile__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.vbh-visual-tile__actual { font-size: 1.4em; font-weight: 700; }

.vbh-visual-tile__rows {
	display: grid;
	grid-template-columns: 1fr auto;
	gap: 2px 12px;
	margin: 0;
}

/* Nextcloud formt dt/dd global (inline-block, padding 12px, dt 130px breit
   und rechtsbündig) – hier zurücksetzen wie .vbh-dl in styles.css. */
.vbh-visual-tile__rows dt,
.vbh-visual-tile__rows dd {
	display: block;
	width: auto;
	margin: 0;
	padding: 0;
	line-height: 1.5;
	white-space: normal;
}

.vbh-visual-tile__rows dt { color: var(--color-text-maxcontrast); text-align: start; }

.vbh-visual-tile__rows dd { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }

.vbh-visual-tile__hint { margin: 0; }
</style>
