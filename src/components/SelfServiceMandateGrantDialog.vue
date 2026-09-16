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
				{{ mode === 'confirm' ? t('Mandat bestätigen') : t('Mandat erfassen und erteilen') }}
			</h2>

			<template v-if="mode === 'confirm'">
				<p class="vbh-hint">
					{{ t('Für Sie liegt ein elektronischer Mandats-Entwurf vor. Mit der Bestätigung erteilen Sie das SEPA-Lastschriftmandat – wirksam ab sofort.') }}
				</p>
				<dl class="vbh-mandate-preview">
					<dt>{{ t('IBAN') }}</dt>
					<dd>{{ mandate && mandate.ibanMasked }}</dd>
					<dt>{{ t('Kontoinhaber') }}</dt>
					<dd>{{ mandate && mandate.accountHolder }}</dd>
				</dl>
			</template>
			<template v-else>
				<p class="vbh-hint">
					{{ t('Vorschau: Wirkt ab sofort – mit der Bestätigung erteilen Sie das SEPA-Lastschriftmandat elektronisch.') }}
				</p>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('IBAN') }}
						<input ref="ibanInput" v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
					</label>
					<label>{{ t('BIC') }}
						<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
					</label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Kontoinhaber') }}
						<input v-model="form.accountHolder" :placeholder="defaultAccountHolder">
					</label>
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
					{{ mode === 'confirm' ? t('Ich stimme zu und erteile das Mandat') : t('Jetzt erteilen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcModal } from '@nextcloud/vue'
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
	components: { NcModal, NcButton, NcLoadingIcon },
	props: {
		show: { type: Boolean, default: false },
		/** 'grant' (kein Mandat vorhanden) oder 'confirm' (Entwurf bestätigen). */
		mode: { type: String, default: 'grant' },
		member: { type: Object, default: null },
		/** Bei mode==='confirm': der bestehende, unbestätigte Entwurf. */
		mandate: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	data() {
		return { form: emptyForm(), legalTextHtml: '', legalTextLoading: false }
	},

	computed: {
		defaultAccountHolder() {
			return (this.member && this.member.displayName) || ''
		},

		canSave() {
			if (this.legalTextLoading) { return false }
			return this.mode === 'confirm' || !!this.form.iban.trim()
		},
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.form = emptyForm()
			if (this.mode === 'grant') {
				this.$nextTick(() => focusOnOpen(this, () => this.$refs.ibanInput))
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
			if (this.mode === 'confirm') {
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
