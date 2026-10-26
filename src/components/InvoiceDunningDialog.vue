<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-inv-dun"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-inv-dun" class="vbh-modal-title">
				{{ t('{stage} zu Rechnung {number}', { stage: dunningLabel(form.level), number: invoice.number }) }}
			</h2>
			<NcLoadingIcon v-if="loading" :size="28" :name="t('Wird geladen…')" />
			<template v-else>
				<div class="vbh-form">
					<label>{{ t('Stufe') }}
						<select v-model.number="form.level" @change="reload">
							<option v-for="l in levels" :key="l" :value="l">{{ dunningLabel(l) }}</option>
						</select>
					</label>
					<label class="vbh-grow">{{ t('An') }}
						<input v-model="form.to" type="email" autocomplete="off">
					</label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Betreff') }}
						<input v-model="form.subject" maxlength="200">
					</label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Text') }}
						<textarea
							v-model="form.body"
							class="vbh-note-textarea"
							rows="10"
							maxlength="5000" />
					</label>
				</div>
				<p class="vbh-hint">
					{{ t('Angehängt wird das Mahnschreiben als PDF mit GiroCode über den offenen Betrag von {amount}. Es wird wie die Rechnung unveränderlich abgelegt.', { amount: formatMoney(remaining / 100) }) }}
				</p>
			</template>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="loading || busy || !form.to.trim()" @click="send">
					{{ t('Mahnung senden') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { dunningLabel } from '../lib/invoices.js'

/** Zahlungserinnerung oder Mahnung zu einer Rechnung verschicken (Epic #168, Stufe 3). */
export default {
	name: 'InvoiceDunningDialog',
	components: { NcModal, NcButton, NcLoadingIcon },
	props: {
		show: { type: Boolean, default: false },
		invoice: { type: Object, default: null },
	},

	emits: ['close', 'sent', 'update:show'],

	data() {
		return { loading: false, busy: false, error: '', remaining: 0, form: { level: 1, to: '', subject: '', body: '' } }
	},

	computed: {
		/** Eine Stufe lässt sich nicht wiederholen; bis zur letzten darf man auch überspringen. */
		levels() {
			const last = this.invoice?.dunning?.state?.level || 0
			return [1, 2, 3].filter((l) => l > last)
		},
	},

	watch: {
		show(open) {
			if (open) { this.load(null) }
		},
	},

	methods: {
		dunningLabel,
		formatMoney,

		reload() { return this.load(this.form.level) },

		async load(level) {
			this.loading = true
			this.error = ''
			try {
				const { data } = await api.invoiceDunningDefaults(this.invoice.id, level)
				this.form = { level: data.level, to: data.to, subject: data.subject, body: data.body }
				this.remaining = data.remainingCents
			} catch (e) {
				this.error = errMsg(e, this.t('Die Vorgaben konnten nicht geladen werden.'))
			} finally {
				this.loading = false
			}
		},

		async send() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await api.dunInvoice(this.invoice.id, this.form)
				this.$emit('sent', data)
			} catch (e) {
				this.error = errMsg(e, this.t('Die Mahnung konnte nicht gesendet werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
