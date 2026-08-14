<template>
	<div>
		<div class="vbh-card">
			<h4>{{ t('Vereinsname') }}</h4>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Vereinsname (erscheint im Kopf des Kassenberichts)') }}
					<input v-model="clubNameModel" type="text" :placeholder="t('z. B. Musterverein e. V.')">
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-card">
			<h4>{{ t('Corporate Design') }}</h4>
			<p class="vbh-hint">
				{{ t('Logo und Akzentfarbe erscheinen im Kurzbericht für Vorstandssitzungen (Berichte → Auswertung).') }}
			</p>
			<div class="vbh-uploadrow">
				<label class="vbh-filebtn">{{ t('Logo wählen') }}<input ref="logoInput"
					type="file"
					accept="image/png,image/jpeg,image/webp"
					hidden
					@change="onLogoSelected"></label>
				<img v-if="hasLogo"
					:src="logoPreviewUrl"
					:alt="t('Vereinslogo')"
					class="vbh-logopreview">
				<span v-else class="vbh-filename">{{ t('kein Logo hinterlegt') }}</span>
				<NcButton v-if="hasLogo"
					variant="tertiary"
					:disabled="logoBusy"
					@click="removeLogo">
					{{ t('Entfernen') }}
				</NcButton>
			</div>
			<div class="vbh-form">
				<label>{{ t('Akzentfarbe') }}
					<input v-model="brandColorModel" type="color">
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Vereinsname und Corporate Design – eine der sieben Seiten des
 * Einstellungsdialogs (App.vue), aus SettingsGeneral.vue aufgeteilt, siehe
 * NAVIGATION-KONZEPT.md Abschnitt 5.
 */
export default {
	name: 'SettingsClub',
	components: { NcButton },
	props: {
		// per .sync (App.vue behaelt den Zustand, da clubName auch ausserhalb
		// des Einstellungsdialogs gebraucht wird, z. B. SetupChecklist-Prop)
		clubName: { type: String, required: true },
		brandColor: { type: String, required: true },
		// nur lesend - Upload/Loeschen laden hier direkt per api.js nach (siehe
		// onLogoSelected/removeLogo), @changed signalisiert dem Elternteil,
		// hasLogo per loadStorageSettings() neu zu laden.
		hasLogo: { type: Boolean, required: true },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils (schreibt den
		// vollstaendigen Einstellungssatz, siehe App.vue::saveStorageSettings())
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
				showSuccess(this.t('Logo gespeichert.'))
			} catch (err) { showError(this.errMsg(err, this.t('Logo konnte nicht gespeichert werden'))) } finally { this.logoBusy = false }
		},
		async removeLogo() {
			this.logoBusy = true
			try {
				await api.deleteLogo()
				this.$emit('changed')
				showSuccess(this.t('Logo entfernt.'))
			} catch (err) { showError(this.errMsg(err, this.t('Logo konnte nicht entfernt werden'))) } finally { this.logoBusy = false }
		},
	},
}
</script>
