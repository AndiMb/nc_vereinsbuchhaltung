<template>
	<NcModal :show="show"
		name="Kontoumsätze importieren (CSV-CAMT)"
		size="normal"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<template v-if="!importDone">
				<div class="vbh-dropzone"
					:class="{ dragging: importDragging, 'has-file': !!selectedFile }"
					@dragover.prevent="importDragging = true"
					@dragleave.self="importDragging = false"
					@drop.prevent="onImportDrop">
					<NcIconSvgWrapper :path="mdiUpload" :size="36" class="vbh-dropzone-icon" />
					<p class="vbh-dropzone-text">
						CSV-Datei der Bank hierher ziehen<br>
						<span class="vbh-dropzone-or">oder</span>
					</p>
					<label class="vbh-filebtn">Datei wählen<input ref="fileInput"
						type="file"
						accept=".csv,text/csv"
						hidden
						@change="onFileSelected"></label>
					<p v-if="selectedFile" class="vbh-filename">
						{{ selectedFile.name }}
					</p>
				</div>
				<p class="vbh-hint">
					Nur neue Buchungen werden übernommen – bereits importierte werden automatisch erkannt (Dublettenprüfung).
				</p>
				<NcCheckboxRadioSwitch v-model="applyRules">
					Auto-Zuordnungsregeln anwenden
				</NcCheckboxRadioSwitch>
				<div v-if="previewResult" class="vbh-preview">
					<p class="vbh-previewsummary">
						<span class="vbh-badge pos">{{ previewResult.new }} neu</span>
						<span class="vbh-badge muted">{{ previewResult.duplicate }} Dubletten</span>
						<span class="vbh-badge muted">{{ previewResult.total }} gesamt</span>
					</p>
					<p v-if="previewResult.existingBookings > 0" class="vbh-hint">
						Davon {{ previewResult.existingBookings }} bereits als vorhandene Buchung erkannt (z. B. aus einem XBUC-Import) und daher übersprungen.
					</p>
					<NcButton variant="primary" :disabled="busy || previewResult.new === 0" @click="commit">
						{{ previewResult.new }} Buchungen importieren
					</NcButton>
					<p v-if="previewResult.new === 0" class="vbh-hint">
						Alle Buchungen dieser Datei wurden bereits importiert.
					</p>
				</div>
			</template>
			<template v-else>
				<div class="vbh-import-done">
					<NcIconSvgWrapper :path="mdiCheckCircle" :size="48" class="vbh-import-done-icon" />
					<h3>{{ importDone.new }} Buchungen importiert</h3>
					<p v-if="importDone.autoAssigned > 0" class="vbh-hint">
						{{ importDone.autoAssigned }} davon wurden automatisch zugeordnet.
					</p>
					<p v-if="importDone.new - importDone.autoAssigned > 0" class="vbh-hint">
						{{ importDone.new - importDone.autoAssigned }} Buchungen warten auf die Zuordnung zu einem Konto.
					</p>
					<div class="vbh-modal-actions">
						<NcButton variant="tertiary" @click="$emit('update:show', false)">
							Schließen
						</NcButton>
						<NcButton v-if="importDone.new - importDone.autoAssigned > 0" variant="primary" @click="$emit('go-assign')">
							Jetzt zuordnen
						</NcButton>
					</div>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mdiUpload, mdiCheckCircle } from '@mdi/js'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

export default {
	name: 'ImportDialog',
	components: { NcModal, NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper },
	props: {
		show: { type: Boolean, default: false },
		// gemeinsames App-weites Ladeflag (.sync)
		busy: { type: Boolean, required: true },
	},
	data() {
		return {
			mdiUpload,
			mdiCheckCircle,
			selectedFile: null,
			applyRules: true,
			previewResult: null,
			importDragging: false,
			importDone: null,
		}
	},
	watch: {
		// NcModal-Inhalt bleibt beim Schließen im DOM (Vue-Instanz bleibt bestehen) -
		// Zuruecksetzen daher hier statt in einer openImport()-Methode des Elternteils.
		show(open) {
			if (open) {
				this.importDone = null
				this.previewResult = null
				this.selectedFile = null
			} else {
				this.importDragging = false
			}
		},
	},
	methods: {
		errMsg,
		onImportDrop(e) {
			this.importDragging = false
			const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]
			if (f) { this.selectedFile = f; this.previewResult = null; this.preview() }
		},
		onFileSelected(e) { this.selectedFile = e.target.files[0] || null; this.previewResult = null; if (this.selectedFile) this.preview() },
		async preview() {
			if (!this.selectedFile) return
			this.$emit('update:busy', true)
			try { const fd = new FormData(); fd.append('file', this.selectedFile); const { data } = await api.previewImport(fd); this.previewResult = data } catch (e) { showError(this.errMsg(e, 'Vorschau fehlgeschlagen')) } finally { this.$emit('update:busy', false) }
		},
		async commit() {
			if (!this.selectedFile) return
			this.$emit('update:busy', true)
			try {
				const fd = new FormData(); fd.append('file', this.selectedFile); fd.append('applyRules', this.applyRules ? '1' : '0')
				const { data } = await api.commitImport(fd)
				showSuccess(`${data.new} Buchungen importiert (${data.autoAssigned} automatisch zugeordnet).`)
				this.importDone = data
				this.previewResult = null; this.selectedFile = null
				if (this.$refs.fileInput) this.$refs.fileInput.value = ''
				this.$emit('imported')
			} catch (e) { showError(this.errMsg(e, 'Import fehlgeschlagen')) } finally { this.$emit('update:busy', false) }
		},
	},
}
</script>
