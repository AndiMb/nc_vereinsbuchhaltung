<template>
	<div class="vbh-card">
		<p class="vbh-hint">
			{{ t('Legt man den im Onlinebanking heruntergeladenen Kontoauszug in diesen Ordner, liest die App ihn von allein ein – kein Hochladen mehr von Hand. Erkannt werden CSV-CAMT, CAMT.053 (XML) und MT940. Leer lassen schaltet die Funktion ab.') }}
		</p>
		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Nextcloud-Nutzer') }}
				<select v-model="watchUserModel">
					<option value="">{{ t('— aus —') }}</option>
					<option v-for="u in users" :key="u.id" :value="u.id">{{ u.displayName }} ({{ u.id }})</option>
				</select>
			</label>
			<div class="vbh-grow vbh-field">
				<label for="vbh-statement-path">{{ t('Ordnerpfad im Nutzer-Home') }}</label>
				<div class="vbh-inputgroup">
					<input
						id="vbh-statement-path"
						v-model="watchPathModel"
						type="text"
						placeholder="Vereinsbuchhaltung/Kontoauszüge">
					<NcButton
						v-if="statementWatchUser"
						:aria-label="t('Ordner wählen…')"
						:title="t('Ordner wählen…')"
						@click="pickerOpen = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFolderSearchOutline" :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
			<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<FolderPickerDialog
			v-if="statementWatchUser"
			v-model:show="pickerOpen"
			:user="statementWatchUser"
			:modelValue="statementWatchPath"
			@pick="watchPathModel = $event" />
		<p v-if="watchActive" class="vbh-hint vbh-hint--info">
			{{ t('Eingelesene Dateien wandern nach') }} <code>{{ statementWatchPath }}/verarbeitet/</code>,
			{{ t('nicht lesbare nach') }} <code>{{ statementWatchPath }}/fehler/</code> {{ t('– gelöscht wird nichts.') }}
			{{ t('Geprüft wird stündlich.') }} <strong>{{ t('Voraussetzung:') }}</strong> {{ t('die Nextcloud-Instanz muss Hintergrundaufgaben per System-Cron ausführen (Verwaltung → Grundeinstellungen); mit „AJAX" laufen sie nur, solange jemand Nextcloud geöffnet hat.') }}
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
 * Überwachter Ordner für Kontoauszüge – eine der sieben Seiten des
 * Einstellungsdialogs (App.vue), aus SettingsGeneral.vue aufgeteilt, siehe
 * NAVIGATION-KONZEPT.md Abschnitt 5.
 */
export default {
	name: 'SettingsStatementWatch',
	components: { FolderPickerDialog, NcButton, NcIconSvgWrapper },
	props: {
		statementWatchUser: { type: String, default: '' },
		statementWatchPath: { type: String, default: '' },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsClub.vue
		saveStorageSettings: { type: Function, required: true },
	},

	emits: ['update:statementWatchPath', 'update:statementWatchUser'],

	setup() {
		return { ...toRefs(usePermissions().state) }
	},

	data() {
		return { pickerOpen: false, mdiFolderSearchOutline }
	},

	computed: {
		watchUserModel: {
			get() { return this.statementWatchUser },
			set(v) { this.$emit('update:statementWatchUser', v) },
		},

		watchPathModel: {
			get() { return this.statementWatchPath },
			set(v) { this.$emit('update:statementWatchPath', v) },
		},

		// Nur beides zusammen ergibt einen Wachordner (das Backend setzt eine
		// halb ausgefuellte Angabe ebenfalls zurueck).
		watchActive() {
			return !!this.statementWatchUser && !!this.statementWatchPath
		},
	},
}
</script>
