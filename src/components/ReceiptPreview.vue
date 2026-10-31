<template>
	<section
		ref="root"
		class="vbh-receipt-preview"
		:class="{ 'is-pending': pending }"
		tabindex="-1"
		:aria-label="t('Belegvorschau')"
		@keydown="onKeydown">
		<div v-if="file" class="vbh-receipt-preview-head">
			<template v-if="showNav">
				<NcButton variant="tertiary" size="small" @click="$emit('back')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" :size="18" />
					</template>
					{{ t('Liste') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="t('Vorige Datei')"
					:title="t('Vorige Datei')"
					@click="$emit('step', -1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChevronLeft" :size="18" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="t('Nächste Datei')"
					:title="t('Nächste Datei')"
					@click="$emit('step', 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChevronRight" :size="18" />
					</template>
				</NcButton>
			</template>
			<span class="vbh-receipt-preview-name" :title="file.name">{{ file.name }}</span>
			<span v-if="picked" class="vbh-folder-status vbh-folder-status--linked">{{ t('übernommen') }}</span>
			<NcLoadingIcon v-if="loading || pending" :size="18" />
			<NcButton
				variant="tertiary"
				size="small"
				:aria-label="t('Im neuen Tab öffnen')"
				:title="t('Im neuen Tab öffnen')"
				@click="openInTab">
				<template #icon>
					<NcIconSvgWrapper :path="mdiOpenInNew" :size="18" />
				</template>
			</NcButton>
		</div>

		<p v-if="!file" class="vbh-receipt-preview-empty">
			{{ t('Datei anklicken für Vorschau') }}
		</p>
		<div v-else-if="shownKind === 'none' || failed" class="vbh-receipt-preview-empty">
			<p>{{ failMessage }}</p>
			<NcButton variant="secondary" @click="openInTab">
				{{ t('Im neuen Tab öffnen') }}
			</NcButton>
		</div>
		<!-- Ohne sandbox: Chromes PDF-Viewer läuft in einem sandboxed iframe
		     nicht. Das PDF kommt als blob: aus den schon geladenen und
		     geprüften Daten (loadPdf); ein blob:-Dokument erbt die CSP der
		     App-Seite, Skripte darin laufen also nicht frei. -->
		<iframe
			v-else-if="shownKind === 'pdf' && shownUrl"
			:key="shownUrl"
			class="vbh-receipt-preview-frame"
			:src="shownUrl"
			:title="file.name"
			@load="loading = false" />
		<div v-else-if="shownKind === 'image' && shownUrl" class="vbh-receipt-preview-image">
			<img
				:key="shownUrl"
				:src="shownUrl"
				:alt="file.name"
				@load="loading = false"
				@error="loading = false; failed = 'error'">
		</div>
	</section>
</template>

<script>
import { mdiArrowLeft, mdiChevronLeft, mdiChevronRight, mdiOpenInNew } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import api from '../api.js'
import { arrowStep, looksLikeCompletePdf, previewKind } from '../lib/receiptPreview.js'

// Beim schnellen Blättern mit den Pfeiltasten erst laden, wenn eine Weile
// keine weitere Taste kam - sonst ein Download je Tastendruck.
const DEBOUNCE_MS = 250

/**
 * Zeigt genau eine Datei des Wächter-Ordners neben der Belegliste
 * (Belegvorschau #231, Spec §3.1): PDF im eingebauten Viewer des Browsers,
 * Bilder als <img>, sonst ein Hinweis. Weiß nichts von Listen, Buchungen
 * oder Layout - das gehört den Rahmen (BookingDialog.vue,
 * AttachmentFolderDialog.vue).
 *
 * Mit showNav (die Vorschau steht anstelle der Liste, schmales Fenster)
 * kommen „Liste“ und Blättern dazu, ↑/↓ blättern dann auch hier.
 *
 * Ein blockiertes iframe (Hoster mit eigener, strengerer CSP) meldet kein
 * Ereignis - deshalb steht „Im neuen Tab öffnen“ immer in der Kopfzeile.
 *
 * PDFs lädt die Komponente zuerst selbst und prüft, ob sie vollständig
 * aussehen (looksLikeCompletePdf). Ein beschädigtes PDF bekäme sonst die
 * Fehlermeldung des Browser-Viewers, und deren „Schließen“ schließt in Edge
 * den ganzen App-Tab samt ungespeicherter Buchung. Angezeigt wird dann aus
 * den geladenen Daten (blob:), ohne zweiten Download.
 */
export default {
	name: 'ReceiptPreview',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon },
	props: {
		// {fileId, name, mime} aus der Belegliste, oder null
		file: { type: Object, default: null },
		// Die Datei wurde gerade übernommen (Kopfzeile zeigt das an).
		picked: { type: Boolean, default: false },
		showNav: { type: Boolean, default: false },
	},

	emits: ['back', 'step'],

	data() {
		// shownUrl/shownKind: was gerade dargestellt wird. Beide wechseln
		// gemeinsam erst nach der Ruhezeit - sonst läge kurz die alte URL in
		// der neuen Darstellung (ein PDF im <img>), und der Fehler bliebe stehen.
		// failed: false, 'broken' (PDF unvollständig) oder 'error' (Laden
		// oder Anzeigen schlug fehl). blobUrl: eigene blob:-Adresse des
		// gezeigten PDFs, beim Wechsel wieder freigegeben. loadSeq verwirft
		// Antworten, die erst nach einem weiteren Wechsel eintreffen. pending:
		// die Kopfzeile nennt schon die neue Datei, zu sehen ist noch die alte
		// (Ruhezeit) - Ladeanzeige und abgeblendeter Inhalt machen das kenntlich.
		return { mdiArrowLeft, mdiChevronLeft, mdiChevronRight, mdiOpenInNew, shownUrl: '', shownKind: 'none', loading: false, failed: false, pending: false, timer: null, blobUrl: '', loadSeq: 0 }
	},

	computed: {
		url() { return this.file ? api.attachmentInboxViewUrl(this.file.fileId) : '' },
		kind() { return this.file ? previewKind(this.file.mime, this.file.name) : 'none' },

		failMessage() {
			if (this.failed === 'broken') { return this.t('Die PDF-Datei scheint unvollständig oder beschädigt zu sein.') }
			if (this.failed) { return this.t('Die Datei lässt sich hier nicht anzeigen.') }
			return this.t('Für diesen Dateityp ist keine Vorschau möglich.')
		},
	},

	watch: {
		url: { handler: 'schedule', immediate: true },
	},

	beforeUnmount() {
		clearTimeout(this.timer)
		this.loadSeq++
		this.releaseBlob()
	},

	methods: {
		/** Die erste Datei sofort, jede weitere nach kurzer Ruhe. */
		schedule(url) {
			clearTimeout(this.timer)
			const show = () => {
				const kind = this.kind
				this.loadSeq++
				this.releaseBlob()
				this.pending = false
				this.shownKind = kind
				this.failed = false
				this.loading = !!url && kind !== 'none'
				if (url && kind === 'pdf') {
					this.shownUrl = ''
					this.loadPdf(url, this.loadSeq)
				} else {
					this.shownUrl = url
				}
			}
			if (!this.shownUrl || !url) {
				show()
			} else {
				this.pending = true
				this.timer = setTimeout(show, DEBOUNCE_MS)
			}
		},

		/**
		 * PDF laden, prüfen und als blob: anzeigen.
		 *
		 * @param {string} url Adresse der Datei
		 * @param {number} seq Stand von loadSeq beim Start
		 */
		async loadPdf(url, seq) {
			let bytes
			try {
				const response = await fetch(url, { credentials: 'same-origin' })
				if (!response.ok) { throw new Error(String(response.status)) }
				bytes = new Uint8Array(await response.arrayBuffer())
			} catch {
				if (seq === this.loadSeq) { this.loading = false; this.failed = 'error' }
				return
			}
			if (seq !== this.loadSeq) { return }
			if (!looksLikeCompletePdf(bytes)) {
				this.loading = false
				this.failed = 'broken'
				return
			}
			this.blobUrl = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }))
			this.shownUrl = this.blobUrl
		},

		releaseBlob() {
			if (this.blobUrl) { URL.revokeObjectURL(this.blobUrl) }
			this.blobUrl = ''
		},

		openInTab() {
			window.open(this.url, '_blank')
		},

		onKeydown(event) {
			const dir = arrowStep(event)
			if (!this.showNav || !dir) { return }
			event.preventDefault()
			this.$emit('step', dir)
		},

		/** Fokus auf die Vorschau, damit ↑/↓ greifen (Rahmen ruft das im Rückfall-Layout). */
		focus() {
			this.$refs.root?.focus({ preventScroll: true })
		},
	},
}
</script>
