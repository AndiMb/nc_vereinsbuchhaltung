<template>
	<span class="vbh-statustag" :class="`vbh-statustag--${tone}`">{{ label }}</span>
</template>

<script>
import { batchStatusLabel, batchStatusTone, claimStateLabel, claimStateTone, severityLabel, severityTone } from '../lib/debitRun.js'

/**
 * Die kleine Beschriftung eines Zustands im Einzug-Unterreiter – immer mit
 * Klartext, die Farbe ist nur die zweite Spur (Issue #102 „Zustandslabels in
 * Klartext“). Drei Arten teilen sich eine Form, damit Läufe, Forderungen und
 * Störfälle überall gleich aussehen:
 * - `batch`: Lauf-Status (freigegeben, eingereicht, verworfen)
 * - `claim`: abgeleiteter Forderungszustand; `settlementType` unterscheidet
 *   beim Erledigungsvermerk bezahlt von erlassen
 * - `severity`: Schweregrad eines Störfalls (Handlungsbedarf, Hinweis)
 *
 * Die Farben sind Paare aus Fläche und darauf lesbarer Schrift (--color-*
 * und --color-*-text), nie ein einzelner Wert: so bleibt der Kontrast im
 * hellen wie im dunklen Design gewahrt.
 */
export default {
	name: 'DebitStatusTag',
	props: {
		kind: { type: String, required: true, validator: (v) => ['batch', 'claim', 'severity'].includes(v) },
		value: { type: String, required: true },
		settlementType: { type: String, default: null },
	},

	computed: {
		label() {
			if (this.kind === 'batch') { return batchStatusLabel(this.value) }
			if (this.kind === 'claim') { return claimStateLabel(this.value, this.settlementType) }
			return severityLabel(this.value)
		},

		tone() {
			if (this.kind === 'batch') { return batchStatusTone(this.value) }
			if (this.kind === 'claim') { return claimStateTone(this.value, this.settlementType) }
			return severityTone(this.value)
		},
	},
}
</script>

<style scoped>
.vbh-statustag {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.82em;
	font-weight: 600;
	white-space: nowrap;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	box-shadow: inset 0 0 0 1px var(--color-border-dark);
}

.vbh-statustag--info {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.vbh-statustag--success {
	background-color: var(--color-success);
	color: var(--color-success-text);
	box-shadow: inset 0 0 0 1px var(--color-element-success);
}

.vbh-statustag--error {
	background-color: var(--color-error);
	color: var(--color-error-text);
	box-shadow: inset 0 0 0 1px var(--color-element-error);
}

</style>
