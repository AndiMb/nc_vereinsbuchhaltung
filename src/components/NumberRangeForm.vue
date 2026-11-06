<template>
	<div class="vbh-inv-range">
		<h5 v-if="title">
			{{ title }}
		</h5>
		<div class="vbh-form">
			<label>{{ t('Muster') }}
				<input
					:value="modelValue.prefixPattern"
					maxlength="40"
					@input="update('prefixPattern', $event.target.value)">
			</label>
			<label>{{ t('Zähler') }}
				<select :value="modelValue.resetMode" @change="update('resetMode', $event.target.value)">
					<option value="yearly">{{ t('jedes Jahr bei 1 beginnen') }}</option>
					<option value="monthly">{{ t('jeden Monat bei 1 beginnen') }}</option>
					<option value="never">{{ t('fortlaufend, nie zurücksetzen') }}</option>
				</select>
			</label>
			<label>{{ t('Nächste Nummer') }}
				<input
					:value="modelValue.nextNo"
					class="vbh-short"
					type="number"
					min="1"
					@input="update('nextNo', $event.target.valueAsNumber || null)">
			</label>
			<NcButton variant="primary" :disabled="saving" @click="$emit('save')">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<p class="vbh-hint">
			{{ t('Vorschau: {number}', { number: preview }) }}
		</p>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import { formatNumberPattern } from '../lib/invoices.js'

/**
 * Muster, Zählerverhalten und nächste Nummer eines Nummernkreises (Rechnungen,
 * Stornorechnungen, Auslagen) mit Vorschau. Der Aufrufer hält den Kreis (`v-model`)
 * und speichert bei `save`; Fehler zeigt `error` unter dem Formular.
 */
export default {
	name: 'NumberRangeForm',
	components: { NcButton },

	props: {
		/** Der Kreis: `{ prefixPattern, resetMode, nextNo }`. */
		modelValue: { type: Object, required: true },
		title: { type: String, default: '' },
		saving: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},

	emits: ['save', 'update:modelValue'],

	computed: {
		preview() {
			return formatNumberPattern(this.modelValue.prefixPattern, new Date(), this.modelValue.nextNo || 1)
		},
	},

	methods: {
		update(field, value) {
			this.$emit('update:modelValue', { ...this.modelValue, [field]: value })
		},
	},
}
</script>

<style scoped>
/* Die Felder füllen ihre Zeile: je Feld mindestens 220 px, die Zahl nur 150 px. */
.vbh-inv-range .vbh-form label { flex: 1 1 220px; min-width: 0; }

.vbh-inv-range .vbh-form label:has(> .vbh-short) { flex: 0 1 150px; }

.vbh-inv-range .vbh-form label > input:not([type="checkbox"]), .vbh-inv-range .vbh-form label > select { width: 100%; min-width: 0; box-sizing: border-box; }
</style>
