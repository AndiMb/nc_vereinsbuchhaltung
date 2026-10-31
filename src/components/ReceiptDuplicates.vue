<template>
	<div class="vbh-dups">
		<p v-if="loading" class="vbh-attachment-empty">
			{{ t('Dubletten werden gesucht…') }}
		</p>
		<template v-else>
			<div v-if="groups.length" class="vbh-folder-toolbar">
				<p class="vbh-folder-summary">
					{{ t('{groups} Gruppen gleicher Dateien · {removable} können weg', { groups: groups.length, removable: removableIds.length }) }}
				</p>
				<NcButton
					v-if="canWrite && removableIds.length"
					variant="secondary"
					size="small"
					@click="$emit('select', removableIds)">
					{{ t('Alle „kann weg“ auswählen') }}
				</NcButton>
			</div>
			<p v-else class="vbh-attachment-empty">
				{{ t('Keine inhaltsgleichen Dateien gefunden.') }}
			</p>
			<p class="vbh-hint">
				{{ t('Gelöscht wird hier nichts. Am einfachsten die Kopien, die weg können, nach „_Dubletten“ verschieben und den Ordner danach in der Dateien-App löschen.') }}
			</p>

			<ul
				v-if="groups.length"
				class="vbh-attachment-list vbh-folder-list vbh-dups-list"
				@keydown.up="onArrowKey($event, -1)"
				@keydown.down="onArrowKey($event, 1)">
				<li v-for="g in groups" :key="g.files[0].fileId" class="vbh-dups-group">
					<div class="vbh-dups-head">
						<strong>{{ n('%n gleiche Datei', '%n gleiche Dateien', g.files.length) }} · {{ formatFileSize(g.size) }}</strong>
						<span v-if="g.doubleBooked" class="vbh-folder-status vbh-folder-status--open">
							{{ t('evtl. doppelt gebucht: {bookings}', { bookings: bookingsOfGroup(g) }) }}
						</span>
					</div>
					<ul class="vbh-dups-files">
						<li
							v-for="f in g.files"
							:key="f.fileId"
							class="vbh-attachment-item vbh-folder-item"
							:class="{ 'vbh-folder-item--previewed': f.fileId === previewFileId }"
							:data-file-id="f.fileId">
							<input
								v-if="canWrite && !f.journalIds.length"
								type="checkbox"
								:checked="selected.has(f.fileId)"
								:aria-label="tRaw('Auswählen: {name}', { name: f.name })"
								@change="$emit('toggle', f.fileId)">
							<NcIconSvgWrapper
								v-else
								:path="mdiFileDocumentOutline"
								:size="16"
								class="vbh-attachment-icon" />
							<div class="vbh-folder-main">
								<button class="vbh-attachment-name" :title="tRaw('Anzeigen: {name}', { name: f.name })" @click="preview(f)">
									{{ f.name }}
								</button>
								<span class="vbh-folder-meta">{{ f.folder ? f.folder + '/' : t('(oberste Ebene)') }} · {{ formatMtime(f.mtime) }}</span>
							</div>
							<span v-if="f.verdict === 'assigned'" class="vbh-folder-status vbh-folder-status--linked">
								{{ t('zugeordnet') }}
								<button
									v-for="b in f.bookings"
									:key="b.journalId"
									type="button"
									class="vbh-folder-booking"
									:title="t('Buchung öffnen')"
									@click="$emit('open-booking', b.journalId)">
									{{ b.entryNo ? '#' + b.entryNo : t('Buchung') }}
								</button>
							</span>
							<span v-else-if="f.verdict === 'keep'" class="vbh-folder-status vbh-dups-keep">{{ t('behalten') }}</span>
							<span v-else class="vbh-folder-status vbh-dups-removable">{{ t('kann weg') }}</span>
						</li>
					</ul>
				</li>
			</ul>

			<p v-if="unreadable" class="vbh-hint">
				{{ n('%n Datei konnte nicht geprüft werden.', '%n Dateien konnten nicht geprüft werden.', unreadable) }}
			</p>
			<p v-if="capped" class="vbh-hint">
				{{ t('Nur unter den ersten {n} Dateien gesucht.', { n: limit }) }}
			</p>
		</template>
	</div>
</template>

<script>
import { mdiFileDocumentOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import api from '../api.js'
import { errMsg, formatDate, formatFileSize } from '../lib/format.js'

/**
 * Inhaltsgleiche Dateien im Wächter-Ordner (Ansicht „Dubletten“ im
 * Eingangskorb, AttachmentFolderPanel.vue). Die Bewertung je Kopie –
 * zugeordnet, behalten, kann weg – kommt vom Server. Auswahl und Verschieben
 * gehören dem Panel: hier wird nur an- und abgehakt.
 *
 * Gesucht wird beim Einhängen; reload() nach einem Verschieben.
 */
export default {
	name: 'ReceiptDuplicates',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		canWrite: { type: Boolean, required: true },
		selected: { type: Set, required: true },
		// Belegvorschau wie im Panel (AttachmentFolderPanel.vue).
		previewEnabled: { type: Boolean, default: false },
		previewFileId: { type: Number, default: null },
	},

	emits: ['open-booking', 'preview', 'select', 'step', 'toggle'],

	data() {
		return { mdiFileDocumentOutline, loading: false, groups: [], unreadable: 0, capped: false, limit: 0 }
	},

	computed: {
		removableIds() {
			return this.groups.flatMap((g) => g.files.filter((f) => f.verdict === 'removable').map((f) => f.fileId))
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		formatFileSize,

		async reload() {
			this.loading = true
			try {
				const { data } = await api.attachmentInboxDuplicates()
				this.groups = data.groups
				this.unreadable = data.unreadable
				this.capped = data.capped
				this.limit = data.limit
			} catch (e) {
				showError(errMsg(e, this.t('Die Dubletten konnten nicht gesucht werden')))
			} finally { this.loading = false }
		},

		bookingsOfGroup(g) {
			return g.files.flatMap((f) => f.bookings || []).map((b) => (b.entryNo ? '#' + b.entryNo : '?')).join(', ')
		},

		preview(f) {
			if (this.previewEnabled) {
				this.$emit('preview', f)
			} else {
				window.open(api.attachmentInboxViewUrl(f.fileId), '_blank')
			}
		},

		/**
		 * Alle Kopien in Anzeigereihenfolge als Zeilen für adjacentFile();
		 * das Panel blättert damit auch in dieser Ansicht.
		 *
		 * @return {Array<{kind: 'file', file: object}>}
		 */
		previewRows() {
			return this.groups.flatMap((g) => g.files.map((file) => ({ kind: 'file', file })))
		},

		/** ↑/↓ meldet step; das Panel blättert, dort liegt die Reihenfolge. */
		onArrowKey(event, dir) {
			if (!this.previewEnabled) { return }
			event.preventDefault()
			this.$emit('step', dir)
		},

		formatMtime(mtime) {
			return formatDate(new Date(mtime * 1000).toISOString())
		},
	},
}
</script>
