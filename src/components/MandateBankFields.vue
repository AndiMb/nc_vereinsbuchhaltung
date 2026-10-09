<template>
	<div class="vbh-bankfields">
		<NcTextField
			ref="iban"
			class="vbh-bankfields__iban"
			:modelValue="iban"
			:label="ibanLabel || t('Neue IBAN')"
			placeholder="DE12 5001 0517 0648 4898 90"
			@update:modelValue="$emit('update:iban', $event)" />
		<NcTextField
			class="vbh-bankfields__bic"
			:modelValue="bic"
			:label="t('BIC (optional)')"
			@update:modelValue="$emit('update:bic', $event)" />
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'

/**
 * IBAN und BIC eines Mandats nebeneinander (untereinander auf schmalem Schirm) –
 * gemeinsam für die Dialoge „Bankverbindung ändern“ der Verwaltung und des
 * Self-Service. `focus()` setzt den Cursor in die IBAN (siehe focusOnOpen()).
 */
export default {
	name: 'MandateBankFields',
	components: { NcTextField },

	props: {
		iban: { type: String, default: '' },
		bic: { type: String, default: '' },
		/** Beschriftung der IBAN; Standard „Neue IBAN“ (beim Ändern), beim Erteilen „IBAN“. */
		ibanLabel: { type: String, default: '' },
	},

	emits: ['update:iban', 'update:bic'],

	methods: {
		focus() { this.$refs.iban?.focus() },
	},
}
</script>

<style scoped>
.vbh-bankfields {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 0 12px;
}

.vbh-bankfields__iban { flex: 1 1 260px; }

.vbh-bankfields__bic { flex: 0 1 180px; min-width: 140px; }
</style>
