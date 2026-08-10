<template>
	<NcModal :show="show"
		:name="t('Kontoumsätze importieren')"
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
						{{ t('Kontoauszug der Bank hierher ziehen') }}<br>
						<span class="vbh-dropzone-or">{{ t('oder') }}</span>
					</p>
					<label class="vbh-filebtn">{{ t('Datei wählen') }}<input ref="fileInput"
						type="file"
						accept=".csv,.xml,.sta,.txt,text/csv,text/xml,application/xml"
						hidden
						@change="onFileSelected"></label>
					<p v-if="selectedFile" class="vbh-filename">
						{{ selectedFile.name }}
					</p>
				</div>
				<p class="vbh-hint">
					{{ t('Erkannt werden CSV-CAMT, CAMT.053 (XML) und MT940 – das Format wird am Inhalt bestimmt, die Dateiendung spielt keine Rolle. Nur neue Buchungen werden übernommen; bereits importierte werden automatisch erkannt (Dublettenprüfung).') }}
				</p>
				<NcCheckboxRadioSwitch v-model="applyRules">
					{{ t('Auto-Zuordnungsregeln anwenden') }}
				</NcCheckboxRadioSwitch>
				<div v-if="previewResult" class="vbh-preview">
					<p class="vbh-previewsummary">
						<span class="vbh-badge pos">{{ t('{n} neu', { n: previewResult.new }) }}</span>
						<span class="vbh-badge muted">{{ t('{n} Dubletten', { n: previewResult.duplicate }) }}</span>
						<span class="vbh-badge muted">{{ t('{n} gesamt', { n: previewResult.total }) }}</span>
					</p>
					<p v-if="previewResult.existingBookings > 0" class="vbh-hint">
						{{ t('Davon {n} bereits als vorhandene Buchung erkannt (z. B. aus einem XBUC-Import) und daher übersprungen.', { n: previewResult.existingBookings }) }}
					</p>
					<NcButton variant="primary" :disabled="busy || previewResult.new === 0" @click="commit">
						{{ t('{n} Buchungen importieren', { n: previewResult.new }) }}
					</NcButton>
					<p v-if="previewResult.new === 0" class="vbh-hint">
						{{ t('Alle Buchungen dieser Datei wurden bereits importiert.') }}
					</p>
				</div>
			</template>
			<template v-else>
				<div class="vbh-import-done">
					<NcIconSvgWrapper :path="mdiCheckCircle" :size="48" class="vbh-import-done-icon" />
					<h3>{{ t('{n} Buchungen importiert', { n: importDone.new }) }}</h3>
					<p v-if="importDone.autoAssigned > 0" class="vbh-hint">
						{{ t('{n} davon wurden automatisch zugeordnet.', { n: importDone.autoAssigned }) }}
					</p>
					<p v-if="importDone.new - importDone.autoAssigned > 0" class="vbh-hint">
						{{ t('{n} Buchungen warten auf die Zuordnung zu einem Konto.', { n: importDone.new - importDone.autoAssigned }) }}
					</p>
					<p v-if="importDone.sepaReturnsDetected > 0" class="vbh-hint vbh-hint--info">
						{{ t('{n} SEPA-Rücklastschrift(en) erkannt: der zugehörige offene Posten wurde wieder geöffnet.', { n: importDone.sepaReturnsDetected }) }}
					</p>
					<div class="vbh-modal-actions">
						<NcButton variant="tertiary" @click="$emit('update:show', false)">
							{{ t('Schließen') }}
						</NcButton>
						<NcButton v-if="importDone.new - importDone.autoAssigned > 0" variant="primary" @click="$emit('go-assign')">
							{{ t('Jetzt zuordnen') }}
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
			try { const fd = new FormData(); fd.append('file', this.selectedFile); const { data } = await api.previewImport(fd); this.previewResult = data } catch (e) { showError(this.errMsg(e, this.t('Vorschau fehlgeschlagen'))) } finally { this.$emit('update:busy', false) }
		},
		async commit() {
			if (!this.selectedFile) return
			this.$emit('update:busy', true)
			try {
				const fd = new FormData(); fd.append('file', this.selectedFile); fd.append('applyRules', this.applyRules ? '1' : '0')
				const { data } = await api.commitImport(fd)
				showSuccess(this.t('{n} Buchungen importiert ({auto} automatisch zugeordnet).', { n: data.new, auto: data.autoAssigned }))
				this.importDone = data
				this.previewResult = null; this.selectedFile = null
				if (this.$refs.fileInput) this.$refs.fileInput.value = ''
				this.$emit('imported')
			} catch (e) { showError(this.errMsg(e, this.t('Import fehlgeschlagen'))) } finally { this.$emit('update:busy', false) }
		},
	},
}
</script>
