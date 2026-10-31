<template>
	<section
		ref="root"
		class="vbh-receipt-preview"
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
			<NcLoadingIcon v-if="loading" :size="18" />
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
			<p>{{ failed ? t('Die Datei lässt sich hier nicht anzeigen.') : t('Für diesen Dateityp ist keine Vorschau möglich.') }}</p>
			<NcButton variant="secondary" @click="openInTab">
				{{ t('Im neuen Tab öffnen') }}
			</NcButton>
		</div>
		<!-- Ohne sandbox: Chromes PDF-Viewer läuft in einem sandboxed iframe
		     nicht. Skripte in der Datei sperrt die Antwort selbst per CSP
		     (ReceiptPreviewPolicy::forInlineFile). -->
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
				@error="loading = false; failed = true">
		</div>
	</section>
</template>

<script>
import { mdiArrowLeft, mdiChevronLeft, mdiChevronRight, mdiOpenInNew } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import api from '../api.js'
import { previewKind } from '../lib/receiptPreview.js'

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
		return { mdiArrowLeft, mdiChevronLeft, mdiChevronRight, mdiOpenInNew, shownUrl: '', shownKind: 'none', loading: false, failed: false, timer: null }
	},

	computed: {
		url() { return this.file ? api.attachmentInboxViewUrl(this.file.fileId) : '' },
		kind() { return this.file ? previewKind(this.file.mime, this.file.name) : 'none' },
	},

	watch: {
		url: { handler: 'schedule', immediate: true },
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		/** Die erste Datei sofort, jede weitere nach kurzer Ruhe. */
		schedule(url) {
			clearTimeout(this.timer)
			const show = () => {
				this.shownUrl = url
				this.shownKind = this.kind
				this.failed = false
				this.loading = !!url && this.kind !== 'none'
			}
			if (!this.shownUrl || !url) { show() } else { this.timer = setTimeout(show, DEBOUNCE_MS) }
		},

		openInTab() {
			window.open(this.url, '_blank')
		},

		onKeydown(event) {
			if (!this.showNav || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) { return }
			event.preventDefault()
			this.$emit('step', event.key === 'ArrowUp' ? -1 : 1)
		},

		/** Fokus auf die Vorschau, damit ↑/↓ greifen (Rahmen ruft das im Rückfall-Layout). */
		focus() {
			this.$refs.root?.focus({ preventScroll: true })
		},
	},
}
</script>
