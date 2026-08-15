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
			<label class="vbh-grow">{{ t('Ordnerpfad im Nutzer-Home') }}
				<input v-model="watchPathModel" type="text" placeholder="Vereinsbuchhaltung/Kontoauszüge">
			</label>
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
import { NcButton } from '@nextcloud/vue'

/**
 * Überwachter Ordner für Kontoauszüge – eine der sieben Seiten des
 * Einstellungsdialogs (App.vue), aus SettingsGeneral.vue aufgeteilt, siehe
 * NAVIGATION-KONZEPT.md Abschnitt 5.
 */
export default {
	name: 'SettingsStatementWatch',
	components: { NcButton },
	props: {
		statementWatchUser: { type: String, default: '' },
		statementWatchPath: { type: String, default: '' },
		users: { type: Array, required: true },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsClub.vue
		saveStorageSettings: { type: Function, required: true },
	},

	emits: ['update:statementWatchPath', 'update:statementWatchUser'],

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
