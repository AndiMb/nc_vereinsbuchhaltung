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
						ref="searchInput"
						v-model="search"
						type="search"
						class="vbh-search vbh-search--full"
						:placeholder="t('Dateiname, Ordner oder Buchungsnummer suchen')">
					<label class="vbh-checkinline">
						<input v-model="onlyOpen" type="checkbox">
						{{ t('nur offene') }}
					</label>
					<label class="vbh-checkinline" :title="t('Wird in diesem Browser gemerkt.')">
						<input v-model="showFolders" type="checkbox">
						{{ t('Ordner anzeigen') }}
					</label>
				</div>

				<p v-if="!loading && files.length" class="vbh-folder-summary">
					{{ t('{open} offen · {linked} zugeordnet', { open: tree.open, linked: tree.linked }) }}
				</p>

				<p v-if="loading" class="vbh-attachment-empty">
					{{ t('Lädt…') }}
				</p>
				<p v-else-if="!rows.length" class="vbh-attachment-empty">
					{{ search ? t('Keine Datei passt zur Suche.') : (onlyOpen ? t('Im Wächter-Ordner liegt kein Dokument ohne Buchung.') : t('Keine weiteren Dateien im Wächter-Ordner.')) }}
				</p>
				<!-- Der Baum als flache Liste: jede Zeile ist Ordner oder Datei, die
				     Tiefe gibt den Einzug (lib/folderTree.js). Ohne Ordner nur die
				     Dateizeilen, der Ordnerpfad steht dann in der Metazeile. -->
				<ul v-else class="vbh-attachment-list vbh-folder-list">
					<template v-for="row in rows" :key="row.kind === 'folder' ? 'd:' + row.node.path : 'f:' + row.file.fileId">
						<li
							v-if="row.kind === 'folder'"
							class="vbh-folder-dir"
							:class="{ 'vbh-folder-dir--done': row.node.open === 0 }"
							:style="{ paddingInlineStart: (row.depth * 20) + 'px' }">
							<button
								type="button"
								class="vbh-folder-toggle"
								:aria-expanded="row.expanded ? 'true' : 'false'"
								:disabled="!!search.trim()"
								@click="toggleFolder(row.node.path)">
								<NcIconSvgWrapper :path="row.expanded ? mdiChevronDown : mdiChevronRight" :size="18" />
								<NcIconSvgWrapper :path="row.expanded ? mdiFolderOpenOutline : mdiFolderOutline" :size="18" />
								<span class="vbh-folder-dirname">{{ row.node.name }}</span>
							</button>
							<span class="vbh-folder-counts">
								<span v-if="row.node.open" class="vbh-folder-open">{{ t('{n} offen', { n: row.node.open }) }}</span>
								<span v-if="row.node.open && row.node.linked"> · </span>
								<span v-if="row.node.linked">{{ t('{n} zugeordnet', { n: row.node.linked }) }}</span>
							</span>
						</li>
						<li
							v-else
							class="vbh-attachment-item vbh-folder-item"
							:style="showFolders ? { paddingInlineStart: (row.depth * 20 + 26) + 'px' } : null">
							<input
								v-if="pick"
								type="checkbox"
								:checked="selected.has(row.file.fileId)"
								:aria-label="tRaw('Auswählen: {name}', { name: row.file.name })"
								@change="toggle(row.file.fileId)">
							<NcIconSvgWrapper
								v-else
								:path="mdiFileDocumentOutline"
								:size="16"
								class="vbh-attachment-icon" />
							<div class="vbh-folder-main">
								<button class="vbh-attachment-name" :title="tRaw('Anzeigen: {name}', { name: row.file.name })" @click="preview(row.file)">
									{{ row.file.name }}
								</button>
								<span class="vbh-folder-meta">
									<span v-if="!showFolders && row.file.folder">{{ row.file.folder }}/ · </span>{{ formatFileSize(row.file.size) }} · {{ formatMtime(row.file.mtime) }}
								</span>
							</div>
							<span v-if="!row.file.journalIds.length" class="vbh-folder-status vbh-folder-status--open">{{ t('offen') }}</span>
							<span v-else class="vbh-folder-status vbh-folder-status--linked">
								{{ t('zugeordnet') }}
								<template v-for="b in bookingsOf(row.file)" :key="b.journalId">
									<button
										v-if="!pick"
										type="button"
										class="vbh-folder-booking"
										:title="t('Buchung öffnen')"
										@click="$emit('open-booking', b.journalId)">
										{{ bookingLabel(b) }}
									</button>
									<span v-else class="vbh-folder-booking">{{ bookingLabel(b) }}</span>
								</template>
							</span>
							<NcButton
								v-if="!pick && canWrite && !row.file.journalIds.length"
								variant="primary"
								size="small"
								@click="$emit('create-booking', row.file)">
								{{ t('Buchung anlegen') }}
							</NcButton>
						</li>
					</template>
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
import { mdiAlertCircleOutline, mdiChevronDown, mdiChevronRight, mdiFileDocumentOutline, mdiFolderOpenOutline, mdiFolderOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcIconSvgWrapper, NcModal } from '@nextcloud/vue'
import api from '../api.js'
import { buildFolderTree, flatFileRows, flattenTree, initialExpanded } from '../lib/folderTree.js'
import { errMsg, formatDate, formatFileSize } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

const SHOW_FOLDERS_KEY = 'vbh_folder_tree'

/** Ordnerbaum oder einfache Liste; ohne gespeicherte Wahl der Baum. */
function readShowFolders() {
	try { return localStorage.getItem(SHOW_FOLDERS_KEY) !== '0' } catch { return true }
}

/**
 * Die Dateien des Wächter-Ordners – in zwei Rollen:
 *
 * - pick: Auswahl aus dem Buchungsdialog oder aus „Zuzuordnen“ heraus
 *   ("Aus Ordner wählen"), Mehrfachauswahl, Ergebnis geht per pick-Event an
 *   App.vue. Standardmäßig nur offene Belege.
 * - Eingangskorb von der Übersicht aus: alle Belege mit ihrem Status,
 *   "Buchung anlegen" bei offenen, Sprung zur Buchung bei zugeordneten,
 *   darunter Belege, deren Datei fehlt.
 *
 * Beide zeigen den Ordnerbaum mit Zählern je Ordner (lib/folderTree.js) –
 * oder, wenn der Nutzer das abschaltet, die einfache Dateiliste wie früher.
 * Die Wahl merkt sich der Browser (localStorage), für beide Rollen gemeinsam.
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
			mdiChevronDown,
			mdiChevronRight,
			mdiFileDocumentOutline,
			mdiFolderOpenOutline,
			mdiFolderOutline,
			files: [],
			missing: [],
			capped: false,
			limit: 0,
			folderMissing: false,
			loading: false,
			search: '',
			onlyOpen: false,
			showFolders: readShowFolders(),
			// Aufgeklappte Ordnerpfade; bei jedem Laden neu aus initialExpanded().
			expanded: new Set(),
			selected: new Set(),
		}
	},

	computed: {
		title() {
			return this.pick ? this.t('Beleg aus Ordner wählen') : this.t('Belege im Wächter-Ordner')
		},

		tree() { return buildFolderTree(this.files) },

		rows() {
			const opts = {
				onlyOpen: this.onlyOpen,
				search: this.search,
				exclude: new Set(this.excludeFileIds),
			}
			return this.showFolders
				? flattenTree(this.tree, { ...opts, expanded: this.expanded })
				: flatFileRows(this.files, opts)
		},
	},

	watch: {
		showFolders(v) {
			try { localStorage.setItem(SHOW_FOLDERS_KEY, v ? '1' : '0') } catch { /* voll/gesperrt – dann eben ohne */ }
		},

		show(v) {
			if (v) {
				this.search = ''
				// Zur Auswahl braucht es meist einen noch offenen Beleg; der
				// Eingangskorb zeigt dagegen den ganzen Bestand.
				this.onlyOpen = this.pick
				this.selected = new Set()
				this.load()
				// Auf Mobil nicht: die aufspringende Tastatur würde die Liste verdecken.
				if (!this.isMobile) { focusOnOpen(this, () => this.$refs.searchInput) }
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
				this.expanded = initialExpanded(this.tree)
			} catch (e) {
				showError(errMsg(e, this.t('Der Wächter-Ordner konnte nicht gelesen werden')))
			} finally { this.loading = false }
		},

		toggleFolder(path) {
			const next = new Set(this.expanded)
			if (next.has(path)) { next.delete(path) } else { next.add(path) }
			this.expanded = next
		},

		bookingsOf(f) {
			return f.bookings || f.journalIds.map((journalId) => ({ journalId, entryNo: null }))
		},

		bookingLabel(b) {
			return b.entryNo ? '#' + b.entryNo : this.t('Buchung')
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
