<template>
	<div class="vbh-card">
		<p class="vbh-hint">
			{{ t('Belege können intern (AppData, nicht in der Nextcloud-Oberfläche sichtbar), in einem von der App verwalteten Ordner eines Nextcloud-Nutzers oder in einem Wächter-Ordner liegen. Im Wächter-Ordner bleiben die Dateien so, wie sie in der Dateien-App abgelegt wurden – alle Dateien darin und in seinen Unterordnern stehen beim Buchen zur Auswahl, und die Übersicht meldet, was noch keiner Buchung zugewiesen ist.') }}
		</p>
		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Art der Ablage') }}
				<select v-model="storageModeModel">
					<option value="appdata">{{ t('intern (AppData)') }}</option>
					<option value="user">{{ t('Nextcloud-Ordner, von der App verwaltet') }}</option>
					<option value="watch">{{ t('Wächter-Ordner (Archiv in der Dateien-App)') }}</option>
				</select>
			</label>
			<template v-if="storageMode !== 'appdata'">
				<label class="vbh-grow">{{ t('Nextcloud-Nutzer') }}
					<select v-model="storageUserModel">
						<option value="">{{ t('— bitte wählen —') }}</option>
						<option v-for="u in users" :key="u.id" :value="u.id">{{ u.displayName }} ({{ u.id }})</option>
					</select>
				</label>
				<div class="vbh-grow vbh-field">
					<label for="vbh-storage-path">{{ t('Ordnerpfad im Nutzer-Home') }}</label>
					<div class="vbh-inputgroup">
						<input
							id="vbh-storage-path"
							v-model="storagePathModel"
							type="text"
							placeholder="Vereinsbuchhaltung/Belege"
							:readonly="storageMode === 'watch'">
						<NcButton
							v-if="storageUser"
							:aria-label="t('Ordner wählen…')"
							:title="t('Ordner wählen…')"
							@click="pickerOpen = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiFolderSearchOutline" :size="20" />
							</template>
						</NcButton>
					</div>
				</div>
			</template>
			<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<FolderPickerDialog
			v-if="storageUser"
			v-model:show="pickerOpen"
			:user="storageUser"
			:modelValue="storagePath"
			@pick="storagePathModel = $event" />
		<p v-if="storageMode === 'user' && storageUser" class="vbh-hint vbh-hint--info">
			{{ t('Belege werden unter') }} <code>{{ storageUser }}/{{ storagePath || 'Vereinsbuchhaltung/Belege' }}/&lt;BuchungsID&gt;/</code> {{ t('abgelegt.') }}
		</p>
		<p v-if="storageMode === 'watch'" class="vbh-hint vbh-hint--info">
			{{ t('Der Ordner muss in der Dateien-App bereits existieren – die App legt ihn nicht an. Aus der App hochgeladene oder fotografierte Belege landen unter') }} <code>{{ storagePath || 'Vereinsbuchhaltung/Belege' }}/&lt;Jahr&gt;/</code>{{ t('; danach dürfen sie beliebig umsortiert werden. Gelöscht wird dort nie etwas: „Beleg löschen" löst nur die Verknüpfung. Wer bisher den von der App verwalteten Ordner genutzt hat, kann ihn direkt als Wächter-Ordner wählen: die dort abgelegten Belege bleiben ihren Buchungen zugeordnet. Alle anderen Dateien im Ordner werden von Hand zugeordnet – in der Übersicht oder beim Buchen mit „Aus Ordner wählen".') }}
		</p>
	</div>
</template>

<script>
import { mdiFolderSearchOutline } from '@mdi/js'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { toRefs } from 'vue'
import FolderPickerDialog from './FolderPickerDialog.vue'
import { usePermissions } from '../composables/usePermissions.js'

/**
 * Belegablage – eine der sieben Seiten des Einstellungsdialogs (App.vue),
 * aus SettingsGeneral.vue aufgeteilt, siehe NAVIGATION-KONZEPT.md Abschnitt 5.
 */
export default {
	name: 'SettingsAttachments',
	components: { FolderPickerDialog, NcButton, NcIconSvgWrapper },
	props: {
		storageMode: { type: String, required: true },
		storageUser: { type: String, required: true },
		storagePath: { type: String, required: true },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsClub.vue
		saveStorageSettings: { type: Function, required: true },
	},

	emits: ['update:storageMode', 'update:storagePath', 'update:storageUser'],

	setup() {
		return { ...toRefs(usePermissions().state) }
	},

	data() {
		return { pickerOpen: false, mdiFolderSearchOutline }
	},

	computed: {
		storageModeModel: {
			get() { return this.storageMode },
			set(v) { this.$emit('update:storageMode', v) },
		},

		storageUserModel: {
			get() { return this.storageUser },
			set(v) { this.$emit('update:storageUser', v) },
		},

		storagePathModel: {
			get() { return this.storagePath },
			set(v) { this.$emit('update:storagePath', v) },
		},
	},
}
</script>
