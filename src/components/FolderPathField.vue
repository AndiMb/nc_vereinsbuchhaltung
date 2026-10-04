<template>
	<div class="vbh-grow vbh-field">
		<label :for="inputId">{{ label || t('Ordnerpfad im Nutzer-Home') }}</label>
		<div class="vbh-inputgroup">
			<input
				:id="inputId"
				:value="modelValue"
				type="text"
				:placeholder="placeholder"
				:readonly="readonly"
				@input="$emit('update:modelValue', $event.target.value)">
			<NcButton
				v-if="user"
				:aria-label="t('Ordner wählen…')"
				:title="t('Ordner wählen…')"
				@click="pickerOpen = true">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderSearchOutline" :size="20" />
				</template>
			</NcButton>
		</div>
		<FolderPickerDialog
			v-if="user"
			v-model:show="pickerOpen"
			:user="user"
			:modelValue="modelValue"
			@update:modelValue="$emit('update:modelValue', $event)" />
	</div>
</template>

<script>
import { mdiFolderSearchOutline } from '@mdi/js'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import FolderPickerDialog from './FolderPickerDialog.vue'

/**
 * Pfadfeld mit Ordnerwahl für die Einstellungen (Belegablage, Wachordner
 * für Kontoauszüge). Ein eigenes Feld statt eines label-Wrappers, weil ein
 * Knopf nicht in ein label gehört.
 */
export default {
	name: 'FolderPathField',
	components: { FolderPickerDialog, NcButton, NcIconSvgWrapper },
	props: {
		modelValue: { type: String, required: true },
		user: { type: String, required: true },
		inputId: { type: String, required: true },
		// Beschriftung des Feldes; ohne Angabe die allgemeine „Ordnerpfad im Nutzer-Home"
		label: { type: String, default: '' },
		placeholder: { type: String, default: '' },
		readonly: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return { pickerOpen: false, mdiFolderSearchOutline }
	},
}
</script>
