<template>
	<span
		class="vbh-visual-bullet"
		:class="`vbh-visual-tone--${tone}`"
		role="img"
		:aria-label="ariaLabel">
		<span class="vbh-visual-bullet__fill" :style="{ width: geometry.fill + '%' }" />
		<span v-if="geometry.over" class="vbh-visual-bullet__over" />
		<span
			v-if="geometry.marker !== null"
			class="vbh-visual-bullet__marker"
			:style="{ insetInlineStart: geometry.marker + '%' }" />
	</span>
</template>

<script>
import { bulletGeometry } from '../lib/budgetVisual.js'
import { formatMoney } from '../lib/format.js'

/**
 * Bullet-Balken: die Spur ist der Plan, die Füllung das Ist, der dünne Strich
 * der Zeitanteil. Überschreitung zeigt eine schraffierte Kappe am rechten Ende.
 * Beträge in Cent.
 */
export default {
	name: 'BudgetBullet',
	props: {
		plan: { type: Number, required: true },
		actual: { type: Number, required: true },
		timeFactor: { type: Number, default: null },
		tone: { type: String, required: true },
	},

	computed: {
		geometry() {
			return bulletGeometry(this.plan, this.actual, this.timeFactor)
		},

		ariaLabel() {
			const actual = formatMoney(this.actual / 100)
			const plan = formatMoney(this.plan / 100)
			if (this.plan > 0) {
				return this.t('Ist {actual} von Plan {plan} ({percent} %)', {
					actual,
					plan,
					percent: Math.round((this.actual / this.plan) * 100),
				})
			}
			return this.t('Ist {actual}, Plan {plan}', { actual, plan })
		},
	},
}
</script>

<style scoped>
.vbh-visual-bullet {
	position: relative;
	display: block;
	width: 100%;
	height: 12px;
	overflow: hidden;
	border-radius: 6px;
	background-color: var(--color-background-dark);
}

.vbh-visual-bullet__fill {
	position: absolute;
	top: 0;
	bottom: 0;
	inset-inline-start: 0;
	background-color: var(--vbh-visual-tone, var(--color-text-maxcontrast));
}

.vbh-visual-bullet__over {
	position: absolute;
	top: 0;
	bottom: 0;
	inset-inline-end: 0;
	width: 10px;
	background: repeating-linear-gradient(45deg, var(--color-main-background) 0 2px, var(--vbh-visual-tone, var(--color-text-maxcontrast)) 2px 4px);
	border-inline-start: 2px solid var(--color-main-text);
}

.vbh-visual-bullet__marker {
	position: absolute;
	top: 0;
	bottom: 0;
	width: 2px;
	margin-inline-start: -1px;
	background-color: var(--color-main-text);
}

.vbh-visual-tone--good { --vbh-visual-tone: var(--color-success); }

.vbh-visual-tone--warn { --vbh-visual-tone: var(--color-warning); }

.vbh-visual-tone--crit { --vbh-visual-tone: var(--color-error); }

.vbh-visual-tone--serious { --vbh-visual-tone: var(--color-error); }

.vbh-visual-tone--none { --vbh-visual-tone: var(--color-text-maxcontrast); }
</style>
