<template>
	<NcSelect
		v-model="selected"
		class="vbh-country-select"
		:options="options"
		label="label"
		:clearable="!noClear"
		:ariaLabelCombobox="t('Land')"
		:placeholder="placeholder || t('Land wählen …')" />
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { countryOptions } from '../lib/countries.js'

/**
 * Auswahl des Landes (zweistelliger ISO-Code) mit allen Ländern der Welt, benannt
 * in der Sprache des Nutzers. Der Wert ist der Code („DE“), leer heißt „kein Land“.
 * Als Vorgabe für neue Einträge taugt `defaultCountry()` aus lib/countryDefault.js.
 */
export default {
	name: 'CountrySelect',
	components: { NcSelect },

	props: {
		modelValue: { type: String, default: '' },
		noClear: { type: Boolean, default: false },
		placeholder: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	computed: {
		options() { return countryOptions(getLanguage()) },
		selected: {
			get() { return this.options.find((o) => o.id === this.modelValue) || null },
			set(option) { this.$emit('update:modelValue', option ? option.id : '') },
		},
	},
}

</script>

<style scoped>
.vbh-country-select { min-width: 240px; }
</style>
