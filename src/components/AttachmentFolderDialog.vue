<template>
	<NcModal
		v-if="show"
		:show="show"
		:name="isMobile ? title : ''"
		:labelId="isMobile ? undefined : 'vbh-modal-title-folder'"
		:size="isMobile ? 'full' : 'normal'"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<!-- v-if statt nur :show: Ein dauerhaft eingehaengtes NcModal legt seine
		     Fokusfalle schon beim Laden der Seite an (unsichtbar) und verwendet sie
		     beim ersten Oeffnen weiter, statt sie oben auf den Stapel zu legen. Aus
		     dem Buchungsdialog heraus zog dessen Falle den Fokus dann immer wieder
		     aus dem Suchfeld zurueck. Frisch eingehaengt kommt die Falle nach oben. -->
		<div class="vbh-modal-inner">
			<h2 v-if="!isMobile" id="vbh-modal-title-folder" class="vbh-modal-title">
				{{ title }}
			</h2>
			<AttachmentFolderPanel
				:pick="pick"
				:canWrite="canWrite"
				:excludeFileIds="excludeFileIds"
				:noAutofocus="isMobile"
				@close="$emit('close')"
				@pick="$emit('pick', $event)"
				@createBooking="$emit('create-booking', $event)"
				@openBooking="$emit('open-booking', $event)" />
		</div>
	</NcModal>
</template>

<script>
import { NcModal } from '@nextcloud/vue'
import AttachmentFolderPanel from './AttachmentFolderPanel.vue'

/**
 * Die Dateien des Wächter-Ordners als eigener Dialog – in zwei Rollen:
 *
 * - pick: Auswahl für einen Bankumsatz unter „Zuzuordnen“, Mehrfachauswahl,
 *   Ergebnis geht per pick-Event an App.vue. Standardmäßig nur offene
 *   Belege. (Im Buchungsdialog steht dieselbe Auswahl als Seitenleiste,
 *   siehe BookingDialog.vue.)
 * - Eingangskorb von der Übersicht aus: alle Belege mit ihrem Status,
 *   "Buchung anlegen" bei offenen, Sprung zur Buchung bei zugeordneten,
 *   darunter Belege, deren Datei fehlt.
 *
 * Der Inhalt selbst steckt in AttachmentFolderPanel.vue.
 */
export default {
	name: 'AttachmentFolderDialog',
	components: { NcModal, AttachmentFolderPanel },
	props: {
		show: { type: Boolean, default: false },
		pick: { type: Boolean, default: false },
		isMobile: { type: Boolean, required: true },
		canWrite: { type: Boolean, required: true },
		excludeFileIds: { type: Array, default: () => [] },
	},

	emits: ['close', 'create-booking', 'open-booking', 'pick', 'update:show'],

	computed: {
		title() {
			return this.pick ? this.t('Beleg aus Ordner wählen') : this.t('Belege im Wächter-Ordner')
		},
	},
}
</script>
