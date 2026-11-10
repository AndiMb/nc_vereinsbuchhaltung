<template>
	<span
		class="vbh-visual-pill"
		:class="`vbh-visual-tone--${meta.tone}`"
		:role="compact ? 'img' : null"
		:aria-label="compact ? meta.label : null">
		<span class="vbh-visual-pill__icon" aria-hidden="true">{{ meta.icon }}</span>
		<span v-if="!compact" class="vbh-visual-pill__text">{{ meta.label }}</span>
	</span>
</template>

<script>
import { statusMeta } from '../lib/budgetVisual.js'

/**
 * Ampelstufe als Symbol mit Text. Kompakt bleibt nur das Symbol sichtbar, der
 * Text steht dann als aria-label am Element – die Stufe ist nie nur Farbe.
 */
export default {
	name: 'BudgetStatusPill',
	props: {
		level: { type: String, required: true },
		nature: { type: String, required: true },
		compact: { type: Boolean, default: false },
	},

	computed: {
		meta() {
			return statusMeta(this.level, this.nature)
		},
	},
}
</script>

<style scoped>
.vbh-visual-pill {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.85em;
	font-weight: 600;
	white-space: nowrap;
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	border: 2px solid var(--vbh-visual-tone, var(--color-text-maxcontrast));
}

.vbh-visual-pill__icon {
	color: var(--vbh-visual-tone, var(--color-text-maxcontrast));
	font-weight: 700;
}

.vbh-visual-tone--good { --vbh-visual-tone: var(--color-success); }

.vbh-visual-tone--warn { --vbh-visual-tone: var(--color-warning); }

.vbh-visual-tone--crit { --vbh-visual-tone: var(--color-error); }

.vbh-visual-tone--serious { --vbh-visual-tone: var(--color-error); border-style: dashed; }

.vbh-visual-tone--none { --vbh-visual-tone: var(--color-text-maxcontrast); }
</style>
