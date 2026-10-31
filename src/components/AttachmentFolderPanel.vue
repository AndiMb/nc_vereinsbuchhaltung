<template>
	<div class="vbh-folder-panel">
		<p v-if="folderMissing" class="vbh-hint vbh-hint--warning">
			{{ t('Der Wächter-Ordner wurde nicht gefunden. Bitte die Belegablage in den Einstellungen prüfen.') }}
		</p>

		<template v-else>
			<div
				v-if="!pick"
				class="vbh-segmented vbh-folder-views"
				role="group"
				:aria-label="t('Ansicht des Eingangskorbs')">
				<button
					type="button"
					class="vbh-segmented-item"
					:class="{ active: view === 'files' }"
					:aria-pressed="view === 'files' ? 'true' : 'false'"
					@click="view = 'files'">
					{{ t('Dateien') }}
				</button>
				<button
					type="button"
					class="vbh-segmented-item"
					:class="{ active: view === 'duplicates' }"
					:aria-pressed="view === 'duplicates' ? 'true' : 'false'"
					@click="view = 'duplicates'; duplicatesOpened = true">
					{{ t('Dubletten') }}
				</button>
			</div>

			<!-- Erst beim ersten Öffnen einhängen, danach nur verstecken: jedes
			     Einhängen sucht neu, und zwei Suchen zugleich kosten doppelt. -->
			<ReceiptDuplicates
				v-if="duplicatesOpened"
				v-show="view === 'duplicates'"
				ref="duplicates"
				:canWrite="canWrite"
				:selected="selected"
				:previewEnabled="previewEnabled"
				:previewFileId="previewFileId"
				@preview="$emit('preview', $event)"
				@step="step"
				@toggle="toggle"
				@select="selectMany"
				@openBooking="$emit('open-booking', $event)" />

			<template v-if="view === 'files'">
				<div class="vbh-folder-toolbar">
					<input
						ref="searchInput"
						v-model="search"
						type="search"
						class="vbh-search vbh-search--full"
						:placeholder="t('Dateiname, Ordner oder Buchungsnummer suchen')"
						@keydown="onArrowKey">
					<label class="vbh-checkinline">
						<input v-model="onlyOpen" type="checkbox">
						{{ t('nur offene') }}
					</label>
					<label class="vbh-checkinline" :title="t('Wird in diesem Browser gemerkt.')">
						<input v-model="showFolders" type="checkbox">
						{{ t('Ordner anzeigen') }}
					</label>
				</div>

				<div v-if="!loading && files.length" class="vbh-folder-summary">
					<label v-if="selecting && selectableIds.length" class="vbh-checkinline">
						<input
							type="checkbox"
							:checked="allSelected(selectableIds)"
							:indeterminate.prop="someSelected(selectableIds)"
							@change="setMany(selectableIds, $event.target.checked)">
						{{ search.trim() ? t('alle passenden offenen auswählen') : t('alle offenen auswählen') }}
					</label>
					{{ t('{open} offen · {linked} zugeordnet', { open: tree.open, linked: tree.linked }) }}
				</div>

				<p v-if="loading" class="vbh-attachment-empty">
					{{ t('Lädt…') }}
				</p>
				<p v-else-if="!rows.length" class="vbh-attachment-empty">
					{{ search ? t('Keine Datei passt zur Suche.') : (onlyOpen ? t('Im Wächter-Ordner liegt kein Dokument ohne Buchung.') : t('Keine weiteren Dateien im Wächter-Ordner.')) }}
				</p>
				<!-- Der Baum als flache Liste: jede Zeile ist Ordner oder Datei, die
			     Tiefe gibt den Einzug (lib/folderTree.js). Ohne Ordner nur die
			     Dateizeilen, der Ordnerpfad steht dann in der Metazeile. -->
				<ul
					v-else
					class="vbh-attachment-list vbh-folder-list"
					@keydown="onArrowKey">
					<template v-for="row in rows" :key="row.kind === 'folder' ? 'd:' + row.node.path : 'f:' + row.file.fileId">
						<li
							v-if="row.kind === 'folder'"
							class="vbh-folder-dir"
							:class="{ 'vbh-folder-dir--done': row.node.open === 0 }"
							:style="{ paddingInlineStart: (row.depth * 20) + 'px' }">
							<input
								v-if="selecting && row.node.open"
								type="checkbox"
								:checked="allSelected(folderIds(row.node.path))"
								:indeterminate.prop="someSelected(folderIds(row.node.path))"
								:aria-label="tRaw('Offene Dateien in {folder} auswählen', { folder: row.node.path })"
								@change="setMany(folderIds(row.node.path), $event.target.checked)">
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
							:class="{ 'vbh-folder-item--previewed': row.file.fileId === previewFileId }"
							:data-file-id="row.file.fileId"
							:style="showFolders ? { paddingInlineStart: (row.depth * 20 + 26) + 'px' } : null">
							<!-- Direktübernahme: ein Klick übernimmt die Datei sofort, sie
						     verschwindet dann aus der Liste (excludeFileIds) und steht
						     links unter den Belegen. -->
							<NcButton
								v-if="pick && instantPick"
								variant="tertiary"
								size="small"
								:disabled="busy"
								:aria-label="tRaw('Auswählen: {name}', { name: row.file.name })"
								:title="tRaw('Auswählen: {name}', { name: row.file.name })"
								@click="pickOne(row.file)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPlus" :size="18" />
								</template>
							</NcButton>
							<input
								v-else-if="pick || (selecting && !row.file.journalIds.length)"
								type="checkbox"
								:checked="selected.has(row.file.fileId)"
								:aria-label="tRaw('Auswählen: {name}', { name: row.file.name })"
								@click="onFileCheck($event, row.file.fileId)">
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

			<div v-if="selecting && selected.size" class="vbh-folder-selectionbar">
				<span>{{ n('%n Datei ausgewählt', '%n Dateien ausgewählt', selected.size) }}</span>
				<NcButton variant="tertiary" size="small" @click="selected = new Set()">
					{{ t('Auswahl aufheben') }}
				</NcButton>
				<NcButton variant="primary" size="small" @click="moveOpen = true">
					{{ t('Verschieben nach…') }}
				</NcButton>
			</div>
		</template>

		<ReceiptMoveDialog
			v-if="moveOpen"
			:show="moveOpen"
			:fileIds="[...selected]"
			:folders="folderPaths"
			@close="moveOpen = false"
			@moved="onMoved" />

		<div v-if="!instantPick && !externalActions" class="vbh-modal-actions vbh-folder-panel-actions">
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
</template>

<script>
import { mdiAlertCircleOutline, mdiChevronDown, mdiChevronRight, mdiFileDocumentOutline, mdiFolderOpenOutline, mdiFolderOutline, mdiPlus } from '@mdi/js'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ReceiptDuplicates from './ReceiptDuplicates.vue'
import ReceiptMoveDialog from './ReceiptMoveDialog.vue'
import api from '../api.js'
import { buildFolderTree, flatFileRows, flattenTree, folderPaths, initialExpanded, openFilesIn } from '../lib/folderTree.js'
import { errMsg, formatDate, formatFileSize } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'
import { moveOutcome } from '../lib/receiptMove.js'
import { adjacentFile, arrowStep, arrowTargetPart } from '../lib/receiptPreview.js'

const SHOW_FOLDERS_KEY = 'vbh_folder_tree'

/** Ordnerbaum oder einfache Liste; ohne gespeicherte Wahl der Baum. */
function readShowFolders() {
	try { return localStorage.getItem(SHOW_FOLDERS_KEY) !== '0' } catch { return true }
}

/**
 * Inhalt des Wächter-Ordners: Suche, Ordnerbaum, Auswahl und Knopfleiste.
 * Steht in zwei Rahmen:
 *
 * - AttachmentFolderDialog.vue: eigener Dialog (Eingangskorb von der
 *   Übersicht, Auswahl für einen Bankumsatz unter „Zuzuordnen“).
 * - BookingDialog.vue: Seitenleiste neben dem Buchungsformular, damit beim
 *   Aussuchen des Belegs Datum, Betrag und Text sichtbar bleiben – statt
 *   eines zweiten Dialogs über dem ersten.
 *
 * Geladen wird beim Einhängen; beide Rahmen hängen das Panel mit v-if bei
 * jedem Öffnen frisch ein, Suche und Auswahl beginnen damit von vorn.
 *
 * Bewusst eine Liste vom Backend statt des Nextcloud-Dateiwählers: der
 * zeigt die Dateien des angemeldeten Nutzers, der Ordner liegt aber im Home
 * des einen konfigurierten Nutzers. Der Server liefert alle Dateien samt
 * ihren Buchungen; gefiltert wird hier, ohne weiteren Ordnerscan.
 */
export default {
	name: 'AttachmentFolderPanel',
	components: { NcButton, NcIconSvgWrapper, ReceiptDuplicates, ReceiptMoveDialog },
	props: {
		pick: { type: Boolean, default: false },
		canWrite: { type: Boolean, required: true },
		// Dateien, die schon an der offenen Buchung hängen oder in der
		// Warteliste stehen – die sollen nicht ein zweites Mal wählbar sein.
		excludeFileIds: { type: Array, default: () => [] },
		// Suchfeld beim Einhängen nicht fokussieren - mobil, da die aufspringende
		// Tastatur würde die Liste verdecken).
		noAutofocus: { type: Boolean, default: false },
		// Jede Datei einzeln per Klick übernehmen, ohne Mehrfachauswahl und
		// ohne Knopfleiste (Seitenleiste im Buchungsdialog am Desktop;
		// geschlossen wird dort über „Aus Ordner wählen“).
		instantPick: { type: Boolean, default: false },
		// Übernehmen gesperrt, solange eine Verknüpfung noch läuft - sonst
		// ließe sich dieselbe Datei doppelt anklicken, bevor sie verschwindet.
		busy: { type: Boolean, default: false },
		// Belegvorschau (#231): Klick auf einen Dateinamen meldet preview,
		// statt die Datei in einem neuen Tab zu öffnen; ↑/↓ blättern. Ohne
		// (mobil, oder ein Rahmen ohne Vorschau) bleibt es beim neuen Tab.
		previewEnabled: { type: Boolean, default: false },
		// Die gerade angezeigte Datei, in der Liste hervorgehoben.
		previewFileId: { type: Number, default: null },
		// Abbrechen/Schließen und Übernehmen stellt der Rahmen in seiner
		// Fußzeile dar (Ordner-Dialog mit Vorschau: sonst stünden sie mitten
		// im Dialog unter der Liste). Die Anzahl kommt per selection-count,
		// übernommen wird über confirmPick().
		externalActions: { type: Boolean, default: false },
	},

	emits: ['close', 'create-booking', 'open-booking', 'pick', 'preview', 'selection-count'],

	data() {
		return {
			mdiAlertCircleOutline,
			mdiChevronDown,
			mdiChevronRight,
			mdiFileDocumentOutline,
			mdiFolderOpenOutline,
			mdiFolderOutline,
			mdiPlus,
			files: [],
			missing: [],
			capped: false,
			limit: 0,
			folderMissing: false,
			loading: false,
			search: '',
			// Zur Auswahl braucht es meist einen noch offenen Beleg; der
			// Eingangskorb zeigt dagegen den ganzen Bestand.
			onlyOpen: this.pick,
			showFolders: readShowFolders(),
			// Aufgeklappte Ordnerpfade; bei jedem Laden neu aus initialExpanded().
			expanded: new Set(),
			// Im Auswahlmodus die Dateien zum Übernehmen, im Eingangskorb die
			// offenen Dateien zum Verschieben.
			selected: new Set(),
			// Eingangskorb: 'files' (Ordnerbaum) oder 'duplicates'.
			view: 'files',
			duplicatesOpened: false,
			moveOpen: false,
			// Zuletzt angeklickte Datei, Anker für Umschalt-Klick.
			lastClicked: null,
		}
	},

	computed: {
		tree() { return buildFolderTree(this.files) },

		/** Eingangskorb mit Schreibrecht: offene Dateien lassen sich zum Verschieben auswählen. */
		selecting() { return !this.pick && this.canWrite },

		selectableIds() { return openFilesIn(this.files, { search: this.search }) },

		folderPaths() { return folderPaths(this.files) },

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
		'selected.size': {
			handler(count) { this.$emit('selection-count', count) },
			immediate: true,
		},

		showFolders(v) {
			try { localStorage.setItem(SHOW_FOLDERS_KEY, v ? '1' : '0') } catch { /* voll/gesperrt – dann eben ohne */ }
		},
	},

	mounted() {
		this.load()
		if (!this.noAutofocus) { focusOnOpen(this, () => this.$refs.searchInput) }
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

		folderIds(path) {
			return openFilesIn(this.files, { search: this.search, folder: path })
		},

		allSelected(ids) {
			return ids.length > 0 && ids.every((id) => this.selected.has(id))
		},

		someSelected(ids) {
			return !this.allSelected(ids) && ids.some((id) => this.selected.has(id))
		},

		setMany(ids, on) {
			const next = new Set(this.selected)
			for (const id of ids) { if (on) { next.add(id) } else { next.delete(id) } }
			this.selected = next
		},

		selectMany(ids) {
			this.setMany(ids, true)
		},

		/**
		 * Kästchen einer Datei. Mit Umschalt-Klick bekommen alle sichtbaren,
		 * auswählbaren Dateien zwischen der zuletzt angeklickten und dieser
		 * denselben Zustand – wie in „Alle Buchungen“.
		 *
		 * @param {MouseEvent} event der Klick
		 * @param {number} fileId die angeklickte Datei
		 */
		onFileCheck(event, fileId) {
			const on = !this.selected.has(fileId)
			const ids = this.rows.filter((r) => r.kind === 'file' && (this.pick || !r.file.journalIds.length)).map((r) => r.file.fileId)
			const from = ids.indexOf(this.lastClicked)
			const to = ids.indexOf(fileId)
			if (event.shiftKey && from >= 0 && to >= 0) {
				this.setMany(ids.slice(Math.min(from, to), Math.max(from, to) + 1), on)
			} else {
				this.toggle(fileId)
			}
			this.lastClicked = fileId
		},

		/** @param {{moved: number[], skipped: Array<{fileId: number, reason: string}>}} result */
		onMoved(result) {
			this.moveOpen = false
			this.selected = new Set()
			const outcome = moveOutcome(result)
			if (outcome.kind === 'error') {
				showError(this.t('{moved} verschoben, dann abgebrochen: {error}', { moved: outcome.moved, error: outcome.error }))
			} else if (outcome.kind === 'warning') {
				showWarning(this.t('{moved} verschoben, {skipped} übersprungen – inzwischen zugeordnet, nicht mehr im Ordner, schon im Ziel oder gesperrt.', { moved: outcome.moved, skipped: outcome.skipped }))
			} else {
				showSuccess(this.n('%n Datei verschoben', '%n Dateien verschoben', outcome.moved))
			}
			this.load()
			this.$refs.duplicates?.reload()
		},

		/**
		 * Direktübernahme einer Datei. Der Fokus wandert vorher ins Suchfeld:
		 * der angeklickte Knopf verschwindet gleich aus der Liste (die Datei
		 * ist dann ausgeschlossen), und focus-trap setzt den Fokus nach dem
		 * Entfernen des fokussierten Elements auf das erste Feld im Dialog -
		 * im Buchungsdialog oben im Formular, dessen Spalte damit jedes Mal
		 * nach oben sprang. preventScroll, damit das Suchfeld selbst nichts
		 * verschiebt.
		 *
		 * @param {object} file die gewählte Datei
		 */
		pickOne(file) {
			this.$refs.searchInput?.focus({ preventScroll: true })
			this.$emit('pick', [file])
		},

		confirmPick() {
			this.$emit('pick', this.files.filter((f) => this.selected.has(f.fileId)))
		},

		preview(f) {
			if (this.previewEnabled) {
				this.$emit('preview', f)
			} else {
				window.open(api.attachmentInboxViewUrl(f.fileId), '_blank')
			}
		},

		/**
		 * ↑/↓ in Liste oder Suchfeld: nächste bzw. vorige Datei zeigen.
		 *
		 * Mit Zusatztaste nicht (arrowStep).
		 *
		 * @param {KeyboardEvent} event die Taste
		 */
		onArrowKey(event) {
			const dir = arrowStep(event)
			if (!this.previewEnabled || !dir) { return }
			event.preventDefault()
			this.step(dir, arrowTargetPart(event.target))
		},

		/**
		 * Nächste (1) bzw. vorige (-1) sichtbare Datei per preview melden -
		 * in der Dateiansicht nach dem Ordnerbaum, in den Dubletten nach deren
		 * Reihenfolge. Auch vom Rahmen aufgerufen, wenn die Vorschau anstelle
		 * der Liste steht. Ist die Liste sichtbar, wandert der Fokus auf die
		 * neue Zeile.
		 *
		 * @param {1|-1} dir Richtung
		 * @param {'name'|'checkbox'} [part] was in der neuen Zeile den Fokus bekommt
		 */
		step(dir, part = 'name') {
			const rows = this.view === 'duplicates' ? (this.$refs.duplicates?.previewRows() ?? []) : this.rows
			const id = adjacentFile(rows, this.previewFileId, dir)
			if (id === null) { return }
			const row = rows.find((r) => r.kind === 'file' && r.file.fileId === id)
			this.$emit('preview', row.file)
			this.$nextTick(() => this.revealFile(id, part))
		},

		/**
		 * Zeile einer Datei in den sichtbaren Bereich holen und fokussieren,
		 * sofern die Liste gerade zu sehen ist. Dieselbe Datei kann zweimal im
		 * DOM stehen – in der Dateiliste und in der (per v-show versteckten)
		 * Dublettenansicht –, gemeint ist die sichtbare Zeile. Hat die Zeile
		 * kein Kästchen (schon zugeordnet), bekommt der Dateiname den Fokus.
		 *
		 * @param {number} fileId die Datei
		 * @param {'name'|'checkbox'} [part] Kästchen oder Dateiname
		 * @return {boolean} ob die Zeile sichtbar war
		 */
		revealFile(fileId, part = 'name') {
			const visible = (selector) => [...this.$el.querySelectorAll(`[data-file-id="${fileId}"] ${selector}`)]
				.find((el) => el.offsetParent !== null)
			const name = visible('.vbh-attachment-name')
			if (!name) { return false }
			const target = (part === 'checkbox' && visible('input[type="checkbox"]')) || name
			target.focus({ preventScroll: true })
			name.scrollIntoView({ block: 'nearest' })
			return true
		},

		formatMtime(mtime) {
			return formatDate(new Date(mtime * 1000).toISOString())
		},
	},
}
</script>
