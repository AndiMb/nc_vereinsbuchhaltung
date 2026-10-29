<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-receipt-move"
		size="small"
		:closeOnClickOutside="!busy"
		@close="close">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-receipt-move" class="vbh-modal-title">
				{{ n('%n Datei verschieben', '%n Dateien verschieben', fileIds.length) }}
			</h2>
			<p class="vbh-hint">
				{{ t('Die Dateien bleiben im Wächter-Ordner und lassen sich weiter zuordnen. Gleichnamige Dateien im Ziel werden nicht überschrieben, sondern durchnummeriert.') }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Zielordner') }}
					<select ref="folderSelect" v-model="folder" :disabled="busy">
						<option v-for="p in folders" :key="p" :value="p">{{ p === '' ? t('(oberste Ebene)') : p }}</option>
					</select>
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Neuer Unterordner (optional)') }}
					<input
						v-model="subfolder"
						:disabled="busy"
						maxlength="255"
						:placeholder="t('z. B. _Klären oder _Dubletten')">
				</label>
			</div>
			<p class="vbh-folder-meta">
				{{ t('Ziel: {path}', { path: '/' + target }) }}
			</p>
			<p v-if="busy && total > chunkSize" class="vbh-hint">
				{{ t('{done} von {total} verarbeitet…', { done, total }) }}
			</p>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="busy" @click="close">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !fileIds.length" @click="submit">
					{{ t('Verschieben') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/** Wie AttachmentWatchFolderService::MAX_MOVE – größere Mengen gehen in Paketen. */
const CHUNK = 500

/**
 * Offene Belege in einen Unterordner des Wächter-Ordners verschieben
 * (Eingangskorb, AttachmentFolderPanel.vue). Ziel ist ein vorhandener Ordner
 * aus dem Scan, optional ergänzt um einen neuen Unterordner – leere Ordner
 * kennt der Scan nicht. Meldet `moved` mit allen verschobenen und
 * übersprungenen Dateien über alle Pakete.
 */
export default {
	name: 'ReceiptMoveDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		fileIds: { type: Array, required: true },
		// Ordnerpfade relativ zum Wächter-Ordner, '' = oberste Ebene (folderPaths()).
		folders: { type: Array, required: true },
	},

	emits: ['close', 'moved'],

	data() {
		return { folder: '', subfolder: '', busy: false, error: '', done: 0, chunkSize: CHUNK }
	},

	computed: {
		target() {
			return [this.folder, this.subfolder.trim().replace(/^\/+|\/+$/g, '')].filter(Boolean).join('/')
		},

		total() { return this.fileIds.length },
	},

	watch: {
		show: {
			immediate: true,
			handler(open) {
				if (!open) { return }
				this.folder = ''
				this.subfolder = ''
				this.error = ''
				this.done = 0
				focusOnOpen(this, () => this.$refs.folderSelect)
			},
		},
	},

	methods: {
		close() {
			if (!this.busy) { this.$emit('close') }
		},

		async submit() {
			this.busy = true
			this.error = ''
			this.done = 0
			const result = { moved: [], skipped: [] }
			try {
				for (let i = 0; i < this.fileIds.length; i += CHUNK) {
					const { data } = await api.attachmentInboxMove(this.fileIds.slice(i, i + CHUNK), this.target)
					result.moved.push(...data.moved)
					result.skipped.push(...data.skipped)
					this.done = Math.min(i + CHUNK, this.fileIds.length)
				}
			} catch (e) {
				this.error = errMsg(e, this.t('Die Dateien konnten nicht verschoben werden.'))
			} finally {
				this.busy = false
			}
			// Auch nach einem Fehler im zweiten Paket ist das erste verschoben –
			// die Liste muss das wissen, und der Fehler darf dabei nicht
			// verloren gehen (moveOutcome()).
			if (result.moved.length || result.skipped.length) {
				this.$emit('moved', { ...result, error: this.error })
			}
		},
	},
}
</script>
