<template>
	<div class="vbh-tplfield">
		<div class="vbh-tplfield-head">
			<label :for="id">{{ label }}</label>
			<NcButton
				variant="tertiary"
				size="small"
				:disabled="!defaultText || modelValue === defaultText"
				@click="$emit('update:modelValue', defaultText)">
				{{ t('Standardtext übernehmen') }}
			</NcButton>
		</div>
		<textarea
			v-if="multiline"
			:id="id"
			class="vbh-note-textarea"
			:value="modelValue"
			:placeholder="defaultText"
			:rows="rows"
			:maxlength="maxlength"
			@input="$emit('update:modelValue', $event.target.value)" />
		<input
			v-else
			:id="id"
			:value="modelValue"
			:placeholder="defaultText"
			:maxlength="maxlength"
			@input="$emit('update:modelValue', $event.target.value)">
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

let uid = 0

/**
 * Ein Textfeld mit Standardtext: leer gilt der (als Platzhalter grau gezeigte)
 * Standardtext, „Standardtext übernehmen“ kopiert ihn ins Feld, damit man ihn
 * anpassen kann. Für die Vorlagen der Rechnungsmails und Mahnschreiben.
 */
export default {
	name: 'SettingsTemplateField',
	components: { NcButton },
	props: {
		modelValue: { type: String, default: '' },
		label: { type: String, required: true },
		defaultText: { type: String, default: '' },
		multiline: { type: Boolean, default: false },
		rows: { type: Number, default: 9 },
		maxlength: { type: Number, default: 5000 },
	},

	emits: ['update:modelValue'],
	data() {
		return { id: `vbh-tplfield-${++uid}` }
	},
}
</script>

<style scoped>
.vbh-tplfield { flex: 1 1 100%; min-width: 0; }

.vbh-tplfield-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; font-size: 0.85em; margin-bottom: 3px; }

.vbh-tplfield input, .vbh-tplfield textarea { width: 100%; min-width: 0; box-sizing: border-box; }
</style>
