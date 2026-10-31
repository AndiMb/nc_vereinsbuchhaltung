<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-reconfirm-self"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-reconfirm-self" class="vbh-modal-title">
				{{ t('Mandatstext bestätigen') }}
			</h2>

			<NcNoteCard type="info">
				{{ t('Der Text Ihres SEPA-Lastschriftmandats wurde aktualisiert. Ihr Mandat und Ihre Bankverbindung bleiben unverändert, Sie müssen nichts eingeben.') }}
			</NcNoteCard>
			<dl class="vbh-mandate-preview">
				<dt>{{ t('IBAN') }}</dt>
				<dd>{{ mandate && mandate.ibanMasked }}</dd>
				<dt>{{ t('Kontoinhaber') }}</dt>
				<dd>{{ mandate && mandate.accountHolder }}</dd>
			</dl>

			<h3 class="vbh-modal-subtitle">
				{{ t('Mandatstext') }}
			</h3>
			<NcLoadingIcon v-if="legalTextLoading" :size="24" />
			<!-- eslint-disable-next-line vue/no-v-html -- serverseitig erzeugtes, escaptes HTML (MandateFormRenderer::renderLegalText()) -->
			<div v-else class="vbh-mandate-legaltext" v-html="legalTextHtml" />

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="legalTextLoading || saving" @click="$emit('save')">
					{{ t('Ich bestätige den aktualisierten Mandatstext') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * „Mandatstext bestätigen“ im Self-Service: das Mitglied liest die aktuelle
 * Fassung (dieselbe Vorschau wie beim Erteilen) und bestätigt sie in der
 * angemeldeten Sitzung – die schnellere Alternative zum Link in der Mail der
 * Verwaltung. Es ändert sich nur das Zustimmungs-Protokoll des Mandats, nichts
 * an Referenz oder Bankverbindung. Der Text wird bei jedem Öffnen neu geladen;
 * bestätigt wird serverseitig die dann aktuelle Fassung.
 */
export default {
	name: 'SelfServiceMandateReconfirmDialog',
	components: { NcModal, NcButton, NcLoadingIcon, NcNoteCard },
	props: {
		show: { type: Boolean, default: false },
		mandate: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	data() {
		return { legalTextHtml: '', legalTextLoading: false }
	},

	watch: {
		show(open) {
			if (open) { this.loadLegalText() }
		},
	},

	methods: {
		async loadLegalText() {
			this.legalTextLoading = true
			try {
				const { data } = await api.selfMandateLegalText()
				this.legalTextHtml = data.html
			} catch (e) {
				showError(errMsg(e, this.t('Mandatstext konnte nicht geladen werden')))
			} finally {
				this.legalTextLoading = false
			}
		},
	},
}
</script>
