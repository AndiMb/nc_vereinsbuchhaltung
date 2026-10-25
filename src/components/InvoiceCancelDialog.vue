<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-inv-cancel"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-inv-cancel" class="vbh-modal-title">
				{{ t('Rechnung {number} stornieren', { number: invoice.number }) }}
			</h2>
			<p class="vbh-hint vbh-hint--warning">
				{{ t('Es entsteht eine Stornorechnung, die alle Positionen umkehrt und auf die Rechnung verweist. Die Rechnung gilt danach als storniert. Das lässt sich nicht rückgängig machen.') }}
			</p>
			<p v-if="invoice.settledCents > 0" class="vbh-hint vbh-hint--info">
				{{ t('Es sind bereits {amount} eingegangen. Die Zahlung bleibt gebucht; die Rückzahlung ordnen Sie später wie jede Auszahlung dem Erlöskonto zu.', { amount: formatMoney(invoice.settledCents / 100) }) }}
			</p>
			<label class="vbh-inv-cancel-reason">{{ t('Grund der Stornierung (Pflicht)') }}
				<textarea
					ref="reason"
					v-model="reason"
					class="vbh-note-textarea"
					rows="3"
					maxlength="500"
					:placeholder="t('z. B. falscher Empfänger, Leistung nicht erbracht')" />
			</label>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy || reason.trim().length < 3" @click="submit">
					{{ t('Stornorechnung erstellen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/** Storno einer festgeschriebenen Rechnung (Epic #168). Meldet `cancelled` mit Original und Stornorechnung. */
export default {
	name: 'InvoiceCancelDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		invoice: { type: Object, default: null },
	},

	emits: ['close', 'cancelled', 'update:show'],

	data() {
		return { reason: '', busy: false, error: '' }
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.reason = ''
			this.error = ''
			focusOnOpen(this, () => this.$refs.reason)
		},
	},

	methods: {
		formatMoney,

		async submit() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await api.cancelInvoice(this.invoice.id, this.reason.trim())
				this.$emit('cancelled', data)
			} catch (e) {
				this.error = errMsg(e, this.t('Die Rechnung konnte nicht storniert werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-inv-cancel-reason { display: flex; flex-direction: column; gap: 3px; font-weight: 600; margin-top: 8px; }

.vbh-inv-cancel-reason textarea { font-weight: normal; width: 100%; box-sizing: border-box; }
</style>
