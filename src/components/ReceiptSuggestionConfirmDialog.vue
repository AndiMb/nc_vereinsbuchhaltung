<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-suggest"
		size="large"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="close"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner vbh-suggest-confirm">
			<h2 id="vbh-modal-title-suggest" class="vbh-modal-title">
				{{ t('Passende Belege zuordnen') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Diese Belege passen mit Rechnungsnummer und Betrag zur Buchung. Entfernen Sie das Häkchen, wo Sie nicht zuordnen möchten.') }}
			</p>
			<ul class="vbh-suggest-confirm-list">
				<li v-for="p in pairs" :key="p.journalId" class="vbh-suggest-confirm-row">
					<NcCheckboxRadioSwitch
						:modelValue="isChecked(p.journalId)"
						:disabled="saving"
						@update:modelValue="setChecked(p.journalId, $event)">
						<span class="vbh-suggest-confirm-pair">
							<span class="vbh-suggest-confirm-booking">
								<strong>#{{ p.entryNo }}</strong> · {{ formatDate(p.date) }} · {{ p.description || t('(ohne Beschreibung)') }} · {{ formatMoney(p.amount) }}
							</span>
							<span class="vbh-suggest-confirm-file">
								↔ {{ p.fileName }}
								<span class="vbh-suggest-reasons">{{ reasonLine(p.reasons) }}</span>
							</span>
						</span>
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="close">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !selectedCount" @click="submit">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" :name="t('Wird zugeordnet …')" />
					</template>
					{{ t('Zuordnen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'
import { reasonLine, skippedSummary } from '../lib/receiptSuggestions.js'

/**
 * Sammelbestätigung der sicheren Belegvorschläge (#229): eine Zeile je Paar
 * Buchung ↔ Datei, alle vorausgewählt. „Zuordnen“ verknüpft die markierten
 * Paare in einem Zug; übersprungene (Datei inzwischen vergeben, Buchung
 * gesperrt) meldet der Dialog als Warnung.
 */
export default {
	name: 'ReceiptSuggestionConfirmDialog',
	components: { NcModal, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon },

	props: {
		show: { type: Boolean, default: false },
		/** Paare `{journalId, entryNo, date, description, amount, fileId, fileName, reasons}` */
		pairs: { type: Array, default: () => [] },
	},

	emits: ['update:show', 'done'],

	data() {
		return { unchecked: new Set(), saving: false }
	},

	computed: {
		selectedCount() { return this.pairs.filter((p) => !this.unchecked.has(p.journalId)).length },
	},

	watch: {
		// Jedes Öffnen beginnt mit allen Paaren markiert.
		show(open) { if (open) { this.unchecked = new Set() } },
	},

	methods: {
		formatDate,
		formatMoney,
		reasonLine,

		isChecked(id) { return !this.unchecked.has(id) },

		setChecked(id, on) {
			const next = new Set(this.unchecked)
			if (on) { next.delete(id) } else { next.add(id) }
			this.unchecked = next
		},

		close() {
			if (this.saving) { return }
			this.$emit('update:show', false)
		},

		async submit() {
			const chosen = this.pairs
				.filter((p) => !this.unchecked.has(p.journalId))
				.map((p) => ({ journalId: p.journalId, fileId: p.fileId }))
			if (!chosen.length) { return }
			this.saving = true
			try {
				const { data } = await api.linkSuggestedReceipts(chosen)
				const linked = data.linked || []
				const skipped = data.skipped || []
				if (linked.length) {
					showSuccess(this.n('%n Beleg zugeordnet', '%n Belege zugeordnet', linked.length))
				}
				if (skipped.length) {
					showWarning(skippedSummary(skipped, this.pairs, this.t, this.n))
				}
				this.$emit('update:show', false)
				this.$emit('done', { linked, skipped })
			} catch (e) {
				showError(errMsg(e, this.t('Zuordnen fehlgeschlagen')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
