<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-welcome"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-welcome" class="vbh-modal-title">
				{{ t('Willkommens-Mail senden') }}
			</h2>
			<p class="vbh-hint">
				{{ tRaw('Geht an {email}. Der Text kommt aus der Vorlage der Einstellungen und lässt sich hier für diese eine Mail anpassen.', { email: member.email || '–' }) }}
			</p>
			<WelcomeMailFields
				v-if="template"
				v-model:subject="subject"
				v-model:body="body"
				:placeholders="template.placeholders" />
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSend || sending" @click="send">
					{{ t('Mail senden') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcModal } from '@nextcloud/vue'
import WelcomeMailFields from './WelcomeMailFields.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/** Willkommens-Mail an ein Mitglied der Akte schicken, mit dem Text der Vorlage oder einem angepassten. */
export default {
	name: 'WelcomeMailDialog',
	components: { NcButton, NcModal, WelcomeMailFields },
	props: {
		show: { type: Boolean, default: false },
		member: { type: Object, required: true },
	},

	emits: ['close', 'update:show'],

	data() {
		return { template: null, subject: '', body: '', sending: false }
	},

	computed: {
		canSend() {
			return !!this.member.email && this.subject.trim() !== '' && this.body.trim() !== ''
		},
	},

	watch: {
		async show(open) {
			if (!open) { return }
			try {
				const { data } = await api.welcomeMailTemplate()
				this.template = data
				this.subject = data.subject
				this.body = data.body
			} catch (e) {
				this.template = null
				showError(errMsg(e, this.t('Die Vorlage konnte nicht geladen werden')))
			}
		},
	},

	methods: {
		async send() {
			if (!this.canSend) { return }
			this.sending = true
			try {
				await api.sendWelcomeMail(this.member.id, { subject: this.subject, body: this.body })
				showSuccess(this.t('Willkommens-Mail gesendet.'))
				this.$emit('close')
			} catch (e) {
				showError(errMsg(e, this.t('Die Willkommens-Mail konnte nicht gesendet werden')))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>
