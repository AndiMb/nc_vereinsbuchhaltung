<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-inv-mail"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-inv-mail" class="vbh-modal-title">
				<template v-if="testOnly">
					{{ t('Testversand für den Rechnungsentwurf') }}
				</template>
				<template v-else>
					{{ t('Rechnung {number} per E-Mail senden', { number: invoice.number }) }}
				</template>
			</h2>
			<NcLoadingIcon v-if="loading" :size="28" :name="t('Wird geladen…')" />
			<template v-else>
				<p v-if="testOnly" class="vbh-hint">
					{{ t('Ein Entwurf geht noch nicht an Kunden. Der Testversand zeigt Ihnen, wie die Mail mit Absender, Logo und PDF ankommt.') }}
				</p>
				<div v-else class="vbh-form">
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
							rows="9"
							maxlength="5000" />
					</label>
				</div>
				<p class="vbh-hint">
					{{ tRaw('Angehängt wird das PDF „{name}“.', { name: fileName }) }}
				</p>
				<fieldset v-if="eDocuments.length" class="vbh-inv-attach">
					<legend>{{ t('E-Rechnung mitsenden') }}</legend>
					<label v-for="d in eDocuments" :key="d.id" class="vbh-checkinline">
						<input v-model="documentIds" type="checkbox" :value="d.id">
						{{ tRaw('{name} ({profile})', { name: d.fileName, profile: d.profile }) }}
					</label>
					<p class="vbh-hint">
						{{ t('Ein ZUGFeRD-PDF ersetzt das einfache PDF, XML-Dateien gehen zusätzlich als Anhang mit.') }}
					</p>
				</fieldset>
				<NcCheckboxRadioSwitch v-if="boardEmail && !testOnly" v-model="form.copyBoard" type="switch">
					{{ t('Kopie an Vorstand') }}
				</NcCheckboxRadioSwitch>
				<TestSendHint :testTo="testTo" />
			</template>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton
					:variant="testOnly ? 'primary' : 'secondary'"
					:disabled="loading || busy || !testTo"
					:title="testTo ? tRaw('Schickt diese Mail mit „[TEST]“ im Betreff an {email}. An der Rechnung ändert sich nichts.', { email: testTo }) : ''"
					@click="sendTest">
					{{ t('Test an mich senden') }}
				</NcButton>
				<NcButton
					v-if="!testOnly"
					variant="primary"
					:disabled="loading || busy || !form.to.trim()"
					@click="send">
					{{ t('Senden') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import TestSendHint from './TestSendHint.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { eInvoiceDocuments } from '../lib/invoices.js'

/** Rechnung per Mail senden (Epic #168): Vorgaben vom Server, Text vor dem Senden anpassbar. */
export default {
	name: 'InvoiceMailDialog',
	components: { NcModal, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, TestSendHint },
	props: {
		show: { type: Boolean, default: false },
		invoice: { type: Object, default: null },
		/** Nur Testversand (Entwurf): ohne Empfängerfeld, ohne „Senden“ (#216). */
		testOnly: { type: Boolean, default: false },
	},

	emits: ['close', 'sent', 'update:show'],

	data() {
		return { loading: false, busy: false, error: '', fileName: '', boardEmail: '', testTo: '', documentIds: [], form: { to: '', subject: '', body: '', copyBoard: false } }
	},

	computed: {
		eDocuments() { return eInvoiceDocuments(this.invoice?.documents) },
	},

	watch: {
		show(open) {
			if (open) { this.load() }
		},
	},

	methods: {
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await api.invoiceMailDefaults(this.invoice.id)
				this.form = { to: data.to, subject: data.subject, body: data.body, copyBoard: !!data.copyBoard }
				this.boardEmail = data.boardEmail || ''
				this.testTo = data.testTo || ''
				this.fileName = data.fileName
				// Was an E-Rechnung schon da ist, geht standardmäßig mit.
				this.documentIds = this.eDocuments.map((d) => d.id)
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
				const { data } = await api.sendInvoice(this.invoice.id, { ...this.form, documentIds: this.documentIds })
				this.$emit('sent', data)
			} catch (e) {
				this.error = errMsg(e, this.t('Die E-Mail konnte nicht gesendet werden.'))
			} finally {
				this.busy = false
			}
		},

		/** Dieselbe Mail an die eigene Adresse; die Rechnung bleibt, wie sie ist (#216). */
		async sendTest() {
			this.busy = true
			this.error = ''
			try {
				const { subject, body } = this.form
				const { data } = await api.sendInvoiceTest(this.invoice.id, { subject, body, documentIds: this.documentIds })
				showSuccess(this.tRaw('Testmail an {email} gesendet.', { email: data.sentTo }))
			} catch (e) {
				this.error = errMsg(e, this.t('Die Testmail konnte nicht gesendet werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
