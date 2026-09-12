<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-folderpicker"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('update:show', false)"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-folderpicker" class="vbh-modal-title">
				{{ t('Ordner wählen') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Ordner im Home von {user}', { user }) }}
			</p>
			<FolderTree v-if="show" v-model="draft" :user="user" />
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('update:show', false)">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!draft" @click="confirm">
					{{ t('Übernehmen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import FolderTree from './FolderTree.vue'

/**
 * Popup um den Ordnerbaum (FolderTree.vue) für die Einstellungen. Der Baum
 * bekommt ein eigenes Fenster, weil er inline die halbe Einstellungsseite
 * einnahm. Die Auswahl gilt erst mit „Übernehmen": wer nur schauen will,
 * bricht ab, ohne dass sich das Pfadfeld ändert.
 */
export default {
	name: 'FolderPickerDialog',
	components: { FolderTree, NcButton, NcModal },
	props: {
		show: { type: Boolean, required: true },
		user: { type: String, required: true },
		modelValue: { type: String, default: '' },
	},

	emits: ['pick', 'update:show'],

	data() {
		return { draft: this.modelValue }
	},

	watch: {
		show(open) {
			if (open) { this.draft = this.modelValue }
		},
	},

	methods: {
		confirm() {
			this.$emit('pick', this.draft)
			this.$emit('update:show', false)
		},
	},
}
</script>
