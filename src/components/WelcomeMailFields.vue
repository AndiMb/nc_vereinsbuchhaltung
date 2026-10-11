<template>
	<div class="vbh-form-stack">
		<NcTextField
			ref="subject"
			:modelValue="subject"
			:label="t('Betreff')"
			@update:modelValue="$emit('update:subject', $event)" />
		<NcTextArea
			:modelValue="body"
			:label="t('Text')"
			rows="8"
			@update:modelValue="$emit('update:body', $event)" />
		<p class="vbh-hint">
			{{ t('Platzhalter, die beim Versand ersetzt werden:') }}
			<code v-for="key in placeholders" :key="key" class="vbh-placeholder">{{ '{' + key + '}' }}</code>
		</p>
	</div>
</template>

<script>
import { NcTextArea, NcTextField } from '@nextcloud/vue'

/**
 * Betreff und Text der Willkommens-Mail (Vorlage in den Einstellungen, im Aufnahme-Dialog und in
 * der Akte für den Einzelfall anpassbar). Die Platzhalter ersetzt der Server beim Versand
 * (WelcomeMailService::PLACEHOLDERS).
 */
export default {
	name: 'WelcomeMailFields',
	components: { NcTextArea, NcTextField },
	props: {
		subject: { type: String, default: '' },
		body: { type: String, default: '' },
		placeholders: { type: Array, default: () => [] },
	},

	emits: ['update:subject', 'update:body'],

	methods: {
		/** Setzt den Cursor in den Betreff (siehe focusOnOpen()). */
		focus() { this.$refs.subject?.focus() },
	},
}
</script>

<style scoped>
.vbh-placeholder {
	margin-inline-end: calc(var(--default-grid-baseline, 4px) * 2);
	white-space: nowrap;
}
</style>
