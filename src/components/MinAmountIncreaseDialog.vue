<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-minamount"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-minamount" class="vbh-modal-title">
				{{ t('Untergrenze anheben') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Bereits eingezogene Perioden werden nie neu berechnet – die Änderung wirkt erst ab der nächsten Forderung.') }}
			</p>
			<div class="vbh-form">
				<label>{{ t('Neue Untergrenze (€/Monat)') }}
					<AmountInput
						ref="amountInput"
						v-model="newMinMonthlyAmount"
						class="vbh-short"
						placeholder="8,00" />
				</label>
				<NcButton variant="secondary" :disabled="!newMinMonthlyAmount" @click="loadPreview">
					{{ t('Vorschau laden') }}
				</NcButton>
			</div>

			<div v-if="preview">
				<p v-if="preview.affected.length === 0" class="vbh-hint">
					{{ t('Keine Zuweisung liegt unter der neuen Untergrenze.') }}
				</p>
				<table v-else class="vbh-table">
					<caption>{{ t('Betroffene Zuweisungen') }}</caption>
					<thead>
						<tr>
							<th>{{ t('Mitglied') }}</th>
							<th>{{ t('Bisher') }}</th>
							<th>{{ t('Neu') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in preview.affected" :key="row.assignmentId">
							<td>{{ row.memberDisplayName }}</td>
							<td>{{ euro(row.oldAmountCents) }}</td>
							<td>{{ euro(row.newAmountCents) }}</td>
						</tr>
					</tbody>
				</table>
				<p v-if="preview.individualOverridesUnaffected.length > 0" class="vbh-hint">
					{{ n('Unberührt (individuelle Untergrenze): %n Zuweisung', 'Unberührt (individuelle Untergrenze): %n Zuweisungen', preview.individualOverridesUnaffected.length) }}
				</p>
			</div>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!preview" @click="apply">
					{{ t('Anheben') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcModal } from '@nextcloud/vue'
import AmountInput from './AmountInput.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/**
 * Untergrenzen-Erhöhung mit Vorschau (Spec §3.3, Issue #68 AK 5): betroffene
 * Zuweisungen namentlich alt→neu, individuelle Untergrenzen separat als
 * unberührt ausgewiesen. Nur `buchhalter` (Backend-Gate am Controller).
 */
export default {
	name: 'MinAmountIncreaseDialog',
	components: { NcModal, NcButton, AmountInput },
	props: {
		show: { type: Boolean, default: false },
		groupId: { type: [Number, String], default: null },
	},

	emits: ['close', 'applied', 'update:show'],

	data() {
		return { newMinMonthlyAmount: '', preview: null }
	},

	watch: {
		show(open) {
			if (open) {
				this.newMinMonthlyAmount = ''
				this.preview = null
				focusOnOpen(this, () => this.$refs.amountInput?.$el)
			}
		},
	},

	methods: {
		euro(cents) { return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) },

		async loadPreview() {
			try {
				const { data } = await api.previewMinAmountIncrease(this.groupId, Number(this.newMinMonthlyAmount))
				this.preview = data
			} catch (e) {
				this.preview = null
				showError(errMsg(e, 'Vorschau konnte nicht geladen werden'))
			}
		},

		async apply() {
			try {
				await api.applyMinAmountIncrease(this.groupId, Number(this.newMinMonthlyAmount))
				this.$emit('applied')
				this.$emit('close')
			} catch (e) { showError(errMsg(e, 'Untergrenze konnte nicht angehoben werden')) }
		},
	},
}
</script>
