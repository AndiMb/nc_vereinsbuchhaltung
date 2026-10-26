<template>
	<div class="vbh-toggle">
		<NcCheckboxRadioSwitch
			:modelValue="modelValue"
			:disabled="disabled"
			type="switch"
			@update:modelValue="$emit('update:modelValue', $event)">
			{{ label }}
		</NcCheckboxRadioSwitch>
		<p v-if="$slots.default" class="vbh-hint vbh-toggle-hint">
			<slot />
		</p>
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

/**
 * Ein Funktionsschalter der Einstellungen: der Schalter mit kurzem Namen der
 * Funktion, darunter (im Slot) was „an“ und was „aus“ bedeutet. Alle Ein/Aus-
 * Einstellungen der Admin-Seite nutzen ihn, damit sie gleich aussehen und
 * gleich beschriftet sind – Häkchen bleiben Einmal-Optionen einer Aktion
 * vorbehalten (z. B. im xbuc-Import).
 */
export default {
	name: 'SettingsToggle',
	components: { NcCheckboxRadioSwitch },
	props: {
		modelValue: { type: Boolean, default: false },
		label: { type: String, required: true },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],
}
</script>

<style scoped>
.vbh-toggle { margin-top: 14px; }

.vbh-toggle:first-child { margin-top: 0; }

.vbh-toggle-hint { margin: 0 0 0 49px; font-size: 0.9em; }
</style>
