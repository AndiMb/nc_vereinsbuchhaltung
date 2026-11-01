<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-grant"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-grant" class="vbh-modal-title">
				{{ title }}
			</h2>

			<template v-if="existingMandate">
				<NcNoteCard type="info">
					<template v-if="mode === 'reconfirm'">
						{{ t('Der Text Ihres SEPA-Lastschriftmandats wurde aktualisiert. Ihr Mandat und Ihre Bankverbindung bleiben unverändert, Sie müssen nichts eingeben.') }}
					</template>
					<template v-else>
						{{ t('Für Sie liegt ein elektronischer Mandats-Entwurf vor. Mit der Bestätigung erteilen Sie das SEPA-Lastschriftmandat – wirksam ab sofort.') }}
					</template>
				</NcNoteCard>
				<dl class="vbh-mandate-preview">
					<dt>{{ t('IBAN') }}</dt>
					<dd>{{ mandate && mandate.ibanMasked }}</dd>
					<dt>{{ t('Kontoinhaber') }}</dt>
					<dd>{{ mandate && mandate.accountHolder }}</dd>
				</dl>
			</template>
			<template v-else>
				<NcNoteCard type="info">
					{{ t('Vorschau: Wirkt ab sofort – mit der Bestätigung erteilen Sie das SEPA-Lastschriftmandat elektronisch.') }}
				</NcNoteCard>
				<div class="vbh-mandate-fields">
					<MandateBankFields
						ref="bankFields"
						v-model:iban="form.iban"
						v-model:bic="form.bic"
						:ibanLabel="t('IBAN')" />
					<NcTextField
						v-model="form.accountHolder"
						:label="t('Kontoinhaber')"
						:placeholder="defaultAccountHolder" />
				</div>
			</template>

			<h3 class="vbh-modal-subtitle">
				{{ t('Mandatstext') }}
			</h3>
			<NcLoadingIcon v-if="legalTextLoading" :size="24" />
			<!-- eslint-disable-next-line vue/no-v-html -- serverseitig erzeugtes, escaptes HTML (MandateFormRenderer::renderLegalText()) -->
			<div v-else class="vbh-mandate-legaltext" v-html="legalTextHtml" />

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ saveLabel }}
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
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MandateBankFields from './MandateBankFields.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

function emptyForm() {
	return { iban: '', bic: '', accountHolder: '' }
}

/**
 * Mandat erfassen + elektronisch erteilen ODER einen von der Verwaltung
 * angelegten elektronischen Entwurf direkt bestätigen (Spec §3.4, Issue #75) -
 * dieselbe Vorschau-vor-Speichern-Pflicht (Mandatstext), nur mit/ohne eigene
 * Bankdaten-Eingabe je nach `mode`. Der Mandatstext wird bei jedem Öffnen neu
 * geladen (kein clientseitig fixierter Versions-Roundtrip nötig, siehe
 * SelfServiceMandateService-Klassendoc: Vorschau und Bestätigung liegen im
 * selben synchronen Dialog).
 */
export default {
	name: 'SelfServiceMandateGrantDialog',
	components: { NcModal, NcButton, NcLoadingIcon, NcNoteCard, NcTextField, MandateBankFields },
	props: {
		show: { type: Boolean, default: false },
		/** 'grant' (kein Mandat vorhanden), 'confirm' (Entwurf bestätigen) oder 'reconfirm' (neue Fassung des Mandatstexts für ein wirksames Mandat bestätigen). */
		mode: { type: String, default: 'grant' },
		member: { type: Object, default: null },
		/** Bei mode 'confirm' und 'reconfirm': das bestehende Mandat (Entwurf bzw. wirksam). */
		mandate: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	data() {
		return { form: emptyForm(), legalTextHtml: '', legalTextLoading: false }
	},

	computed: {
		/** Ein bestehendes Mandat wird bestätigt (statt eines neuen erfasst): keine Bankdaten-Eingabe. */
		existingMandate() { return this.mode === 'confirm' || this.mode === 'reconfirm' },

		title() {
			if (this.mode === 'reconfirm') { return this.t('Mandatstext bestätigen') }
			return this.mode === 'confirm' ? this.t('Mandat bestätigen') : this.t('Mandat erfassen und erteilen')
		},

		saveLabel() {
			if (this.mode === 'reconfirm') { return this.t('Ich bestätige den aktualisierten Mandatstext') }
			return this.mode === 'confirm' ? this.t('Ich stimme zu und erteile das Mandat') : this.t('Jetzt erteilen')
		},

		defaultAccountHolder() {
			return (this.member && this.member.displayName) || ''
		},

		canSave() {
			if (this.legalTextLoading) { return false }
			return this.existingMandate || !!this.form.iban.trim()
		},
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.form = emptyForm()
			if (this.mode === 'grant') {
				this.$nextTick(() => focusOnOpen(this, () => this.$refs.bankFields))
			}
			this.loadLegalText()
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

		save() {
			if (this.existingMandate) {
				this.$emit('save', {})
				return
			}
			this.$emit('save', {
				iban: this.form.iban.trim(),
				bic: this.form.bic.trim() || null,
				accountHolder: this.form.accountHolder.trim() || null,
			})
		},
	},
}
</script>
