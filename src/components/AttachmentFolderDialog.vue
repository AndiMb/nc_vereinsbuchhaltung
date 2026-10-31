<template>
	<NcModal
		v-if="show"
		:show="show"
		:name="isMobile ? title : ''"
		:labelId="isMobile ? undefined : 'vbh-modal-title-folder'"
		:size="isMobile ? 'full' : 'large'"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<!-- v-if statt nur :show: Ein dauerhaft eingehaengtes NcModal legt seine
		     Fokusfalle schon beim Laden der Seite an (unsichtbar) und verwendet sie
		     beim ersten Oeffnen weiter, statt sie oben auf den Stapel zu legen. Aus
		     dem Buchungsdialog heraus zog dessen Falle den Fokus dann immer wieder
		     aus dem Suchfeld zurueck. Frisch eingehaengt kommt die Falle nach oben. -->
		<div class="vbh-modal-inner" :class="{ 'vbh-folder-dialog--preview': !isMobile }">
			<h2 v-if="!isMobile" id="vbh-modal-title-folder" class="vbh-modal-title">
				{{ title }}
			</h2>

			<!-- Wofür der Beleg gesucht wird - zum Vergleich mit der Vorschau. -->
			<div v-if="context && contextLines.length" class="vbh-folder-context">
				<div v-for="(line, i) in contextLines" :key="i" class="vbh-folder-context-line">
					{{ line }}
				</div>
				<div v-if="moreBookings" class="vbh-folder-context-more">
					{{ t('+ {n} weitere', { n: moreBookings }) }}
				</div>
			</div>

			<div :class="{ 'vbh-folder-dialog-grid': !isMobile, 'is-wide': isWide }">
				<div class="vbh-folder-dialog-list">
					<!-- Schmales Fenster: die Vorschau steht anstelle der Liste,
					     die Liste bleibt eingehaengt (Suche und Auswahl bleiben). -->
					<ReceiptPreview
						v-if="previewInList"
						ref="fallbackPreview"
						:file="previewFile"
						showNav
						@back="backToList"
						@step="$refs.panel.step($event)" />
					<AttachmentFolderPanel
						v-show="!previewInList"
						ref="panel"
						:pick="pick"
						:canWrite="canWrite"
						:excludeFileIds="excludeFileIds"
						:noAutofocus="isMobile"
						:previewEnabled="!isMobile"
						:previewFileId="previewFile ? previewFile.fileId : null"
						@close="$emit('close')"
						@pick="$emit('pick', $event)"
						@preview="onPreview"
						@createBooking="$emit('create-booking', $event)"
						@openBooking="$emit('open-booking', $event)" />
				</div>
				<div v-if="!isMobile && isWide" class="vbh-folder-dialog-preview">
					<ReceiptPreview :file="previewFile" />
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import AttachmentFolderPanel from './AttachmentFolderPanel.vue'
import ReceiptPreview from './ReceiptPreview.vue'
import { formatDate, formatMoney } from '../lib/format.js'

// So viele Buchungen stehen im Kontextstreifen, der Rest als „+ n weitere“.
const CONTEXT_BOOKINGS = 3

/**
 * Die Dateien des Wächter-Ordners als eigener Dialog – in zwei Rollen:
 *
 * - pick: Auswahl für einen Bankumsatz unter „Zuzuordnen“ oder für die im
 *   Journal markierten Buchungen, Mehrfachauswahl, Ergebnis geht per
 *   pick-Event an App.vue. Standardmäßig nur offene Belege. (Im
 *   Buchungsdialog steht dieselbe Auswahl als Seitenleiste, siehe
 *   BookingDialog.vue.)
 * - Eingangskorb von der Übersicht aus: alle Belege mit ihrem Status,
 *   "Buchung anlegen" bei offenen, Sprung zur Buchung bei zugeordneten,
 *   darunter Belege, deren Datei fehlt.
 *
 * Am Desktop steht rechts neben der Liste die Belegvorschau (#231), bei
 * schmalem Fenster anstelle der Liste. Darüber ein Kontextstreifen mit dem
 * Bankumsatz bzw. den Buchungen, für die der Beleg gesucht wird
 * (lib/receiptPreview.js, txContext/bookingsContext).
 *
 * Der Inhalt selbst steckt in AttachmentFolderPanel.vue.
 */
export default {
	name: 'AttachmentFolderDialog',
	components: { NcModal, AttachmentFolderPanel, ReceiptPreview },
	props: {
		show: { type: Boolean, default: false },
		pick: { type: Boolean, default: false },
		// Eigene Überschrift statt der Standardtitel, z. B. „Beleg für 5
		// Buchungen wählen“ bei der Mehrfachauswahl im Journal.
		heading: { type: String, default: '' },
		isMobile: { type: Boolean, required: true },
		// Breites Fenster (App.vue): Liste und Vorschau nebeneinander.
		isWide: { type: Boolean, required: true },
		canWrite: { type: Boolean, required: true },
		excludeFileIds: { type: Array, default: () => [] },
		// {kind: 'tx', …} oder {kind: 'bookings', items}, im Eingangskorb null.
		context: { type: Object, default: null },
	},

	emits: ['close', 'create-booking', 'open-booking', 'pick', 'update:show'],

	data() {
		return { previewFile: null, previewOverList: false }
	},

	computed: {
		title() {
			return this.heading || (this.pick ? this.t('Beleg aus Ordner wählen') : this.t('Belege im Wächter-Ordner'))
		},

		previewInList() {
			return !this.isMobile && !this.isWide && this.previewFile !== null && this.previewOverList
		},

		/** Zeilen des Kontextstreifens, leere Angaben weggelassen. */
		contextLines() {
			const c = this.context
			if (!c) { return [] }
			const join = (parts) => parts.filter((p) => p !== '' && p !== null && p !== undefined).join(' · ')
			if (c.kind === 'tx') {
				return [join([formatDate(c.date), formatMoney(c.amount), c.purpose, c.counterparty])]
			}
			return c.items.slice(0, CONTEXT_BOOKINGS)
				.map((b) => join([b.entryNo ? '#' + b.entryNo : '', formatDate(b.date), formatMoney(b.amount), b.description]))
		},

		moreBookings() {
			return this.context?.kind === 'bookings' ? Math.max(0, this.context.items.length - CONTEXT_BOOKINGS) : 0
		},
	},

	watch: {
		show() {
			this.previewFile = null
			this.previewOverList = false
		},

		// Beim Wechsel der Fensterbreite verschwindet das fokussierte Element
		// (Liste bzw. Rückfall-Vorschau) - der Fokus wandert mit, sonst landet
		// er im Nichts und ↑/↓ greifen nicht mehr.
		isWide(isWide) {
			if (!this.previewFile || this.isMobile) { return }
			const id = this.previewFile.fileId
			if (isWide) {
				this.$nextTick(() => this.$refs.panel?.revealFile(id))
			} else {
				this.previewOverList = true
				this.$nextTick(() => this.$refs.fallbackPreview?.focus())
			}
		},
	},

	methods: {
		onPreview(file) {
			const wasOverList = this.previewInList
			this.previewFile = file
			if (this.isWide) { return }
			this.previewOverList = true
			if (!wasOverList) { this.$nextTick(() => this.$refs.fallbackPreview?.focus()) }
		},

		backToList() {
			const id = this.previewFile?.fileId
			this.previewOverList = false
			this.$nextTick(() => { if (id) { this.$refs.panel?.revealFile(id) } })
		},
	},
}
</script>
