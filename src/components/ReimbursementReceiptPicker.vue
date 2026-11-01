<template>
	<div class="vbh-receipts">
		<div
			class="vbh-receipts-drop"
			:class="{ 'is-over': over, 'has-files': modelValue.length > 0 }"
			@dragover.prevent="over = true"
			@dragleave.prevent="over = false"
			@drop.prevent="onDrop">
			<NcIconSvgWrapper :path="mdiCloudUploadOutline" :size="modelValue.length ? 24 : 36" class="vbh-receipts-icon" />
			<div class="vbh-receipts-text">
				<strong>{{ modelValue.length ? t('Weiteren Beleg hinzufügen') : t('Beleg hierher ziehen oder auswählen') }}</strong>
				<span class="vbh-receipts-limits">{{ t('PDF, JPG, PNG, GIF oder WebP, höchstens 20 MB je Datei') }}</span>
			</div>
			<div class="vbh-receipts-buttons">
				<NcButton variant="secondary" @click="$refs.picker.click()">
					{{ t('Datei wählen') }}
				</NcButton>
				<!-- Nur am Handy: dort öffnet es die Kamera, am Desktop gäbe es denselben Dialog zweimal. -->
				<NcButton variant="secondary" class="vbh-receipts-camera" @click="$refs.camera.click()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCamera" :size="18" />
					</template>
					{{ t('Foto aufnehmen') }}
				</NcButton>
			</div>
			<input
				ref="picker"
				type="file"
				multiple
				hidden
				:accept="accept"
				@change="onPicked">
			<input
				ref="camera"
				type="file"
				hidden
				accept="image/*"
				capture="environment"
				@change="onPicked">
		</div>

		<ul v-if="errors.length" class="vbh-receipts-errors" role="alert">
			<li v-for="message in errors" :key="message">
				{{ message }}
			</li>
		</ul>

		<ul v-if="modelValue.length" class="vbh-receipts-list">
			<li v-for="(file, i) in modelValue" :key="key(file)">
				<img
					v-if="thumbs[key(file)]"
					:src="thumbs[key(file)]"
					alt=""
					class="vbh-receipts-thumb">
				<span v-else class="vbh-receipts-thumb vbh-receipts-thumb--file">
					<NcIconSvgWrapper :path="mdiFilePdfBox" :size="26" />
				</span>
				<span class="vbh-receipts-name">
					<span class="vbh-receipts-filename">{{ file.name }}</span>
					<span class="vbh-receipts-size">{{ formatFileSize(file.size) }}</span>
				</span>
				<NcButton variant="tertiary" :aria-label="tRaw('Beleg „{name}“ entfernen', { name: file.name })" @click="remove(i)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { mdiCamera, mdiClose, mdiCloudUploadOutline, mdiFilePdfBox } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { formatFileSize } from '../lib/format.js'
import { addReceipts, isImageReceipt, RECEIPT_ACCEPT, receiptKey } from '../lib/reimbursementFiles.js'

/**
 * Belegfeld des Auslagenantrags: Dateien ablegen oder auswählen (am Handy auch
 * fotografieren), mit Vorschaubild, Größe und Entfernen. Prüft Typ, Größe und
 * Anzahl schon beim Auswählen (lib/reimbursementFiles.js) und nennt je abgelehnter
 * Datei den Grund. Die Auswahl gehört dem Aufrufer (v-model).
 */
export default {
	name: 'ReimbursementReceiptPicker',
	components: { NcButton, NcIconSvgWrapper },

	props: {
		/** @type {import('vue').PropType<File[]>} */
		modelValue: { type: Array, default: () => [] },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			mdiCamera,
			mdiCloudUploadOutline,
			mdiClose,
			mdiFilePdfBox,
			accept: RECEIPT_ACCEPT,
			over: false,
			errors: [],
			// Vorschau-Adressen der Bilder; vor dem Entfernen und beim Schließen freigeben.
			thumbs: {},
		}
	},

	watch: {
		modelValue: {
			immediate: true,
			handler(files) {
				const keep = new Set(files.map(receiptKey))
				for (const key of Object.keys(this.thumbs)) {
					if (!keep.has(key)) {
						URL.revokeObjectURL(this.thumbs[key])
						delete this.thumbs[key]
					}
				}
				for (const file of files) {
					const key = receiptKey(file)
					if (!this.thumbs[key] && isImageReceipt(file) && typeof URL.createObjectURL === 'function') {
						this.thumbs[key] = URL.createObjectURL(file)
					}
				}
			},
		},
	},

	beforeUnmount() {
		Object.values(this.thumbs).forEach((url) => URL.revokeObjectURL(url))
	},

	methods: {
		formatFileSize,
		key: receiptKey,

		add(incoming) {
			const { files, errors } = addReceipts(this.modelValue, incoming)
			this.errors = errors
			if (files.length !== this.modelValue.length) { this.$emit('update:modelValue', files) }
		},

		onPicked(event) {
			this.add(Array.from(event.target.files || []))
			// Dieselbe Datei soll sich nach dem Entfernen erneut wählen lassen.
			event.target.value = ''
		},

		onDrop(event) {
			this.over = false
			this.add(Array.from(event.dataTransfer?.files || []))
		},

		remove(index) {
			this.errors = []
			this.$emit('update:modelValue', this.modelValue.filter((_, i) => i !== index))
		},
	},
}
</script>

<style scoped>
.vbh-receipts-drop {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px 16px;
	padding: 16px;
	border: 2px dashed var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	transition: border-color 0.12s ease, background-color 0.12s ease;
}

.vbh-receipts-drop.is-over {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.vbh-receipts-drop.has-files {
	padding: 10px 14px;
}

.vbh-receipts-icon {
	color: var(--color-text-maxcontrast);
}

.vbh-receipts-text {
	display: flex;
	flex: 1 1 200px;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.vbh-receipts-limits {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.vbh-receipts-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

/* Die Kamera gibt es nur an Geräten mit Touch-Bedienung. */
.vbh-receipts-camera {
	display: none;
}

@media (pointer: coarse) {
	.vbh-receipts-camera {
		display: inline-flex;
	}
}

.vbh-receipts-errors {
	margin: 8px 0 0;
	padding: 8px 12px 8px 28px;
	border-radius: var(--border-radius);
	background-color: var(--color-error);
	color: var(--color-error-text);
}

.vbh-receipts-list {
	display: grid;
	gap: 8px;
	margin: 10px 0 0;
	padding: 0;
	list-style: none;
}

.vbh-receipts-list li {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 6px 6px 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.vbh-receipts-thumb {
	flex: none;
	width: 44px;
	height: 44px;
	border-radius: var(--border-radius);
	object-fit: cover;
}

.vbh-receipts-thumb--file {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.vbh-receipts-name {
	display: flex;
	flex: 1;
	flex-direction: column;
	min-width: 0;
}

.vbh-receipts-filename {
	overflow: hidden;
	font-weight: 600;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.vbh-receipts-size {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
</style>
