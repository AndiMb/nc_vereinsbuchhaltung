<template>
	<div>
		<h3 class="vbh-section-divider">Verein</h3>
		<div class="vbh-card">
			<div class="vbh-form">
				<label class="vbh-grow">Vereinsname (erscheint im Kopf des Kassenberichts)
					<input v-model="clubNameModel" type="text" placeholder="z. B. Musterverein e. V.">
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">Speichern</NcButton>
			</div>
		</div>

		<h3 class="vbh-section-divider">Kostenstellen</h3>
		<div class="vbh-card">
			<p class="vbh-hint">
				Bestimmt, wie der Bericht „Kostenstellen" die Konten gruppiert. Der Modus hängt vom
				Kontenrahmen des Vereins ab.
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">Modus
					<select v-model="costCenterModeModel">
						<option value="group">2. Zahlengruppe der Kontonummer (z. B. „111 51" → Kostenstelle 51)</option>
						<option value="account">Jedes Einnahmen-/Ausgabenkonto ist eine eigene Kostenstelle</option>
					</select>
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">Speichern</NcButton>
			</div>
		</div>

		<h3 class="vbh-section-divider">Belegablage</h3>
		<div class="vbh-card">
			<p class="vbh-hint">
				Belege können intern (AppData, nicht in der Nextcloud-Oberfläche sichtbar) oder in einem
				Ordner eines Nextcloud-Nutzers gespeichert werden. Wenn kein Nutzer gewählt ist, wird die interne Ablage verwendet.
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">Nextcloud-Nutzer
					<select v-model="storageUserModel">
						<option value="">— intern (AppData) —</option>
						<option v-for="u in users" :key="u.id" :value="u.id">{{ u.displayName }} ({{ u.id }})</option>
					</select>
				</label>
				<label class="vbh-grow">Ordnerpfad im Nutzer-Home
					<input v-model="storagePathModel" type="text" placeholder="Vereinsbuchhaltung/Belege">
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">Speichern</NcButton>
			</div>
			<p v-if="storageUser" class="vbh-hint vbh-hint--info">
				Belege werden unter <code>{{ storageUser }}/{{ storagePath || 'Vereinsbuchhaltung/Belege' }}/&lt;BuchungsID&gt;/</code> abgelegt.
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'SettingsGeneral',
	components: { NcButton },
	props: {
		// alle vier per .sync (App.vue behaelt den Zustand, da clubName/demoActive
		// auch ausserhalb des Einstellungen-Modals gebraucht werden, z. B.
		// SetupChecklist-Prop und Demo-Banner)
		clubName: { type: String, required: true },
		costCenterMode: { type: String, required: true },
		storageUser: { type: String, required: true },
		storagePath: { type: String, required: true },
		users: { type: Array, required: true },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils (alle drei Karten speichern
		// dasselbe Settings-Objekt in einem Rutsch, wie im Original)
		saveStorageSettings: { type: Function, required: true },
	},
	computed: {
		clubNameModel: {
			get() { return this.clubName },
			set(v) { this.$emit('update:clubName', v) },
		},
		costCenterModeModel: {
			get() { return this.costCenterMode },
			set(v) { this.$emit('update:costCenterMode', v) },
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
