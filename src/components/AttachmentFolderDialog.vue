<template>
	<NcModal
		:show="show"
		:name="isMobile ? title : ''"
		:labelId="isMobile ? undefined : 'vbh-modal-title-folder'"
		:size="isMobile ? 'full' : 'normal'"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 v-if="!isMobile" id="vbh-modal-title-folder" class="vbh-modal-title">
				{{ title }}
			</h2>

			<p v-if="folderMissing" class="vbh-hint vbh-hint--warning">
				{{ t('Der Wächter-Ordner wurde nicht gefunden. Bitte die Belegablage in den Einstellungen prüfen.') }}
			</p>

			<template v-else>
				<div class="vbh-folder-toolbar">
					<input
						v-model="search"
						type="search"
						class="vbh-search vbh-search--full"
						:placeholder="t('Dateiname oder Ordner suchen')">
					<label v-if="pick" class="vbh-checkinline">
						<input v-model="showLinked" type="checkbox">
						{{ t('auch bereits zugeordnete anzeigen') }}
					</label>
				</div>

				<p v-if="loading" class="vbh-attachment-empty">
					{{ t('Lädt…') }}
				</p>
				<p v-else-if="!visibleFiles.length" class="vbh-attachment-empty">
					{{ search ? t('Keine Datei passt zur Suche.') : (showLinked ? t('Keine weiteren Dateien im Wächter-Ordner.') : t('Im Wächter-Ordner liegt kein Dokument ohne Buchung.')) }}
				</p>
				<ul v-else class="vbh-attachment-list vbh-folder-list">
					<li v-for="f in visibleFiles" :key="f.fileId" class="vbh-attachment-item vbh-folder-item">
						<input
							v-if="pick"
							type="checkbox"
							:checked="selected.has(f.fileId)"
							:aria-label="t('Auswählen: {name}', { name: f.name })"
							@change="toggle(f.fileId)">
						<NcIconSvgWrapper
							v-else
							:path="mdiFileDocumentOutline"
							:size="16"
							class="vbh-attachment-icon" />
						<div class="vbh-folder-main">
							<button class="vbh-attachment-name" :title="t('Anzeigen: {name}', { name: f.name })" @click="preview(f)">
								{{ f.name }}
							</button>
							<span class="vbh-folder-meta">
								<span v-if="f.folder">{{ f.folder }}/ · </span>{{ formatFileSize(f.size) }} · {{ formatMtime(f.mtime) }}
								<span v-if="f.journalIds.length"> · {{ t('schon zugeordnet') }}</span>
							</span>
						</div>
						<NcButton
							v-if="!pick && canWrite"
							variant="primary"
							size="small"
							@click="$emit('create-booking', f)">
							{{ t('Buchung anlegen') }}
						</NcButton>
					</li>
				</ul>
				<p v-if="capped" class="vbh-hint">
					{{ t('Es werden höchstens {n} Dateien gelesen – der Ordner enthält mehr.', { n: limit }) }}
				</p>

				<template v-if="!pick && missing.length">
					<h3 class="vbh-folder-subtitle">
						{{ t('Belege, deren Datei fehlt') }}
					</h3>
					<p class="vbh-hint">
						{{ t('Die Datei wurde in der Dateien-App gelöscht oder aus dem Wächter-Ordner hinausgeschoben. Zurück im Ordner oder aus dem Papierkorb wiederhergestellt ist sie wieder da; sonst die Verknüpfung in der Buchung lösen und den Beleg neu anhängen.') }}
					</p>
					<ul class="vbh-attachment-list vbh-folder-list">
						<li v-for="m in missing" :key="m.id" class="vbh-attachment-item vbh-folder-item">
							<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="16" class="vbh-attachment-icon vbh-folder-warn" />
							<div class="vbh-folder-main">
								<span class="vbh-attachment-name">{{ m.fileName }}</span>
								<span v-if="m.entryNo" class="vbh-folder-meta">
									{{ t('Buchung #{n}', { n: m.entryNo }) }} · {{ formatDate(m.date) }} · {{ m.description }}
								</span>
							</div>
							<NcButton
								v-if="m.entryNo"
								variant="secondary"
								size="small"
								@click="$emit('open-booking', m.journalId)">
								{{ t('Buchung öffnen') }}
							</NcButton>
						</li>
					</ul>
				</template>
			</template>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ pick ? t('Abbrechen') : t('Schließen') }}
				</NcButton>
				<NcButton
					v-if="pick"
					variant="primary"
					:disabled="!selected.size"
					@click="confirmPick">
					{{ selected.size ? t('{n} übernehmen', { n: selected.size }) : t('Übernehmen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiAlertCircleOutline, mdiFileDocumentOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcIconSvgWrapper, NcModal } from '@nextcloud/vue'
import api from '../api.js'
import { errMsg, formatDate, formatFileSize } from '../lib/format.js'

/**
 * Die Dateien des Wächter-Ordners – in zwei Rollen:
 *
 * - pick: Auswahl aus dem Buchungsdialog heraus ("Aus Ordner wählen"),
 *   Mehrfachauswahl, Ergebnis geht per pick-Event an App.vue.
 * - Eingangskorb von der Übersicht aus: Dokumente ohne Buchung mit
 *   "Buchung anlegen" je Zeile, darunter Belege, deren Datei fehlt.
 *
 * Bewusst eine Liste vom Backend statt des Nextcloud-Dateiwählers: der
 * zeigt die Dateien des angemeldeten Nutzers, der Ordner liegt aber im Home
 * des einen konfigurierten Nutzers. Der Server liefert alle Dateien samt
 * ihren Buchungen; gefiltert wird hier, ohne weiteren Ordnerscan.
 */
export default {
	name: 'AttachmentFolderDialog',
	components: { NcModal, NcButton, NcIconSvgWrapper },
	props: {
		show: { type: Boolean, default: false },
		pick: { type: Boolean, default: false },
		isMobile: { type: Boolean, required: true },
		canWrite: { type: Boolean, required: true },
		// Dateien, die schon an der offenen Buchung hängen oder in der
		// Warteliste stehen – die sollen nicht ein zweites Mal wählbar sein.
		excludeFileIds: { type: Array, default: () => [] },
	},

	emits: ['close', 'create-booking', 'open-booking', 'pick', 'update:show'],

	data() {
		return {
			mdiAlertCircleOutline,
			mdiFileDocumentOutline,
			files: [],
			missing: [],
			capped: false,
			limit: 0,
			folderMissing: false,
			loading: false,
			search: '',
			showLinked: false,
			selected: new Set(),
		}
	},

	computed: {
		title() {
			return this.pick ? this.t('Beleg aus Ordner wählen') : this.t('Dokumente ohne Buchung')
		},

		visibleFiles() {
			const s = this.search.trim().toLowerCase()
			const excluded = new Set(this.excludeFileIds)
			return this.files.filter((f) => !excluded.has(f.fileId)
				&& (this.showLinked || !f.journalIds.length)
				&& (!s || f.name.toLowerCase().includes(s) || f.folder.toLowerCase().includes(s)))
		},
	},

	watch: {
		show(v) {
			if (v) {
				this.search = ''
				this.showLinked = false
				this.selected = new Set()
				this.load()
			}
		},
	},

	methods: {
		formatDate,
		formatFileSize,

		async load() {
			this.loading = true
			try {
				const { data } = await api.attachmentInbox()
				this.files = data.files
				this.missing = data.missing
				this.capped = data.capped
				this.limit = data.limit
				this.folderMissing = data.folderMissing
			} catch (e) {
				showError(errMsg(e, this.t('Der Wächter-Ordner konnte nicht gelesen werden')))
			} finally { this.loading = false }
		},

		toggle(fileId) {
			if (this.selected.has(fileId)) { this.selected.delete(fileId) } else { this.selected.add(fileId) }
		},

		confirmPick() {
			this.$emit('pick', this.files.filter((f) => this.selected.has(f.fileId)))
		},

		preview(f) {
			window.open(api.attachmentInboxViewUrl(f.fileId), '_blank')
		},

		formatMtime(mtime) {
			return formatDate(new Date(mtime * 1000).toISOString())
		},
	},
}
</script>
