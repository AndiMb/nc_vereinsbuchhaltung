<template>
	<div class="vbh-card">
		<p class="vbh-hint">
			{{ t('Belege können intern (AppData, nicht in der Nextcloud-Oberfläche sichtbar) oder in einem Ordner eines Nextcloud-Nutzers gespeichert werden. Wenn kein Nutzer gewählt ist, wird die interne Ablage verwendet.') }}
		</p>
		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Nextcloud-Nutzer') }}
				<select v-model="storageUserModel">
					<option value="">{{ t('— intern (AppData) —') }}</option>
					<option v-for="u in users" :key="u.id" :value="u.id">{{ u.displayName }} ({{ u.id }})</option>
				</select>
			</label>
			<label class="vbh-grow">{{ t('Ordnerpfad im Nutzer-Home') }}
				<input v-model="storagePathModel" type="text" placeholder="Vereinsbuchhaltung/Belege">
			</label>
			<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<p v-if="storageUser" class="vbh-hint vbh-hint--info">
			{{ t('Belege werden unter') }} <code>{{ storageUser }}/{{ storagePath || 'Vereinsbuchhaltung/Belege' }}/&lt;BuchungsID&gt;/</code> {{ t('abgelegt.') }}
		</p>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

/**
 * Belegablage – eine der sieben Seiten des Einstellungsdialogs (App.vue),
 * aus SettingsGeneral.vue aufgeteilt, siehe NAVIGATION-KONZEPT.md Abschnitt 5.
 */
export default {
	name: 'SettingsAttachments',
	components: { NcButton },
	props: {
		storageUser: { type: String, required: true },
		storagePath: { type: String, required: true },
		users: { type: Array, required: true },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsClub.vue
		saveStorageSettings: { type: Function, required: true },
	},
	computed: {
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
