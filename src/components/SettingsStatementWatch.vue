<template>
	<div class="vbh-card">
		<SettingsToggle :modelValue="enabled" :label="t('Kontoauszüge automatisch einlesen')" @update:modelValue="changeEnabled">
			{{ t('Legt man den im Onlinebanking heruntergeladenen Kontoauszug in einen Ordner, liest die App ihn von allein ein – kein Hochladen mehr von Hand. Erkannt werden CSV-CAMT, CAMT.053 (XML) und MT940. Beim Ausschalten wird der Ordner nicht mehr geprüft.') }}
		</SettingsToggle>
		<div v-if="enabled" class="vbh-form">
			<label class="vbh-grow">{{ t('Nextcloud-Nutzer') }}
				<select v-model="watchUserModel">
					<option value="">{{ t('— bitte wählen —') }}</option>
					<option v-for="u in users" :key="u.id" :value="u.id">{{ u.displayName }} ({{ u.id }})</option>
				</select>
			</label>
			<FolderPathField
				v-model="watchPathModel"
				inputId="vbh-statement-path"
				:user="statementWatchUser"
				placeholder="Vereinsbuchhaltung/Kontoauszüge" />
			<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<p v-if="watchActive" class="vbh-hint vbh-hint--info">
			{{ t('Eingelesene Dateien wandern nach') }} <code>{{ statementWatchPath }}/verarbeitet/</code>,
			{{ t('nicht lesbare nach') }} <code>{{ statementWatchPath }}/fehler/</code> {{ t('– gelöscht wird nichts.') }}
			{{ t('Geprüft wird stündlich.') }} <strong>{{ t('Voraussetzung:') }}</strong> {{ t('die Nextcloud-Instanz muss Hintergrundaufgaben per System-Cron ausführen (Verwaltung → Grundeinstellungen); mit „AJAX" laufen sie nur, solange jemand Nextcloud geöffnet hat.') }}
		</p>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import FolderPathField from './FolderPathField.vue'
import SettingsToggle from './SettingsToggle.vue'
import { usePermissions } from '../composables/usePermissions.js'

/**
 * Überwachter Ordner für Kontoauszüge – eine der sieben Seiten des
 * Einstellungsdialogs (App.vue), aus SettingsGeneral.vue aufgeteilt, siehe
 * NAVIGATION-KONZEPT.md Abschnitt 5.
 */
export default {
	name: 'SettingsStatementWatch',
	components: { FolderPathField, NcButton, SettingsToggle },
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
		return { enabled: !!this.statementWatchUser }
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

	watch: {
		// Die Einstellungen kommen erst nach dem Mounten vom Server.
		statementWatchUser(v) { if (v) { this.enabled = true } },
	},

	methods: {
		changeEnabled(on) {
			this.enabled = on
			if (!on) {
				this.$emit('update:statementWatchUser', '')
				this.$nextTick(() => this.saveStorageSettings())
			}
		},
	},
}
</script>
