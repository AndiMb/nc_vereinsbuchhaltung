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

		<h3 class="vbh-section-divider">Corporate Design</h3>
		<div class="vbh-card">
			<p class="vbh-hint">Logo und Akzentfarbe erscheinen im Kurzbericht für Vorstandssitzungen (Berichte → Auswertung).</p>
			<div class="vbh-uploadrow">
				<label class="vbh-filebtn">Logo wählen<input ref="logoInput" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" hidden @change="onLogoSelected"></label>
				<img v-if="hasLogo" :src="logoPreviewUrl" alt="Vereinslogo" class="vbh-logopreview">
				<span v-else class="vbh-filename">kein Logo hinterlegt</span>
				<NcButton v-if="hasLogo" variant="tertiary" :disabled="logoBusy" @click="removeLogo">Entfernen</NcButton>
			</div>
			<div class="vbh-form">
				<label>Akzentfarbe
					<input v-model="brandColorModel" type="color">
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

export default {
	name: 'SettingsGeneral',
	components: { NcButton },
	props: {
		// alle fünf per .sync (App.vue behaelt den Zustand, da clubName/demoActive
		// auch ausserhalb des Einstellungen-Modals gebraucht werden, z. B.
		// SetupChecklist-Prop und Demo-Banner)
		clubName: { type: String, required: true },
		costCenterMode: { type: String, required: true },
		storageUser: { type: String, required: true },
		storagePath: { type: String, required: true },
		brandColor: { type: String, required: true },
		// nur lesend - Upload/Loeschen laden hier direkt per api.js nach (siehe
		// onLogoSelected/removeLogo), @changed signalisiert dem Elternteil,
		// hasLogo per loadStorageSettings() neu zu laden.
		hasLogo: { type: Boolean, required: true },
		users: { type: Array, required: true },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils (alle drei Karten speichern
		// dasselbe Settings-Objekt in einem Rutsch, wie im Original)
		saveStorageSettings: { type: Function, required: true },
	},
	data() {
		return {
			logoBusy: false,
			// Cache-Buster fuer das <img>, da die Logo-URL nach einem Upload
			// unveraendert bleibt und der Browser sonst die alte Datei zeigt.
			logoVersion: 0,
		}
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
		brandColorModel: {
			get() { return this.brandColor || '#2d7d46' },
			set(v) { this.$emit('update:brandColor', v) },
		},
		logoPreviewUrl() {
			return api.logoUrl() + '?v=' + this.logoVersion
		},
	},
	methods: {
		errMsg,
		async onLogoSelected(e) {
			const file = e.target.files[0]
			e.target.value = ''
			if (!file) return
			this.logoBusy = true
			try {
				const fd = new FormData(); fd.append('file', file)
				await api.uploadLogo(fd)
				this.logoVersion++
				this.$emit('changed')
				showSuccess('Logo gespeichert.')
			} catch (err) { showError(this.errMsg(err, 'Logo konnte nicht gespeichert werden')) } finally { this.logoBusy = false }
		},
		async removeLogo() {
			this.logoBusy = true
			try {
				await api.deleteLogo()
				this.$emit('changed')
				showSuccess('Logo entfernt.')
			} catch (err) { showError(this.errMsg(err, 'Logo konnte nicht entfernt werden')) } finally { this.logoBusy = false }
		},
	},
}
</script>
