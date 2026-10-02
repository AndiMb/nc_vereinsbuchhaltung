<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-account"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-account" class="vbh-modal-title">
				{{ t('Bankverbindung ändern') }}
			</h2>

			<div class="vbh-form">
				<label>
					<input v-model="targetMode" type="radio" value="iban">
					{{ t('Gleiches Konto, nur die IBAN hat sich geändert') }}
				</label>
				<label>
					<input v-model="targetMode" type="radio" value="holder">
					{{ t('Der Kontoinhaber wechselt') }}
				</label>
			</div>

			<template v-if="targetMode === 'iban'">
				<p class="vbh-hint">
					{{ t('Vorschau: Wirkt ab sofort. Kein Sperrfenster – Sie können die IBAN bis zur Einreichung des nächsten Einzugs jederzeit ändern.') }}
				</p>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Neue IBAN') }}
						<input ref="ibanInput" v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
					</label>
					<label>{{ t('BIC') }}
						<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
					</label>
				</div>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="$emit('close')">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="!form.iban.trim() || saving" @click="saveIban">
						{{ t('IBAN ändern') }}
					</NcButton>
				</div>
			</template>

			<template v-else>
				<div class="vbh-card vbh-card--danger">
					<p>
						{{ t('Das bisherige Mandat wird endgültig beendet, ein neues wird sofort elektronisch erteilt. Das lässt sich nicht rückgängig machen.') }}
					</p>
					<p v-if="openClaimsTotalCents > 0">
						{{ t('Noch offen: {betrag}', { betrag: formatMoney(openClaimsTotalCents / 100) }) }}
					</p>
				</div>

				<p class="vbh-hint">
					{{ t('Nur ein neues Konto bei derselben Person? Dafür reicht die IBAN-Änderung – ohne neues Mandat.') }}
				</p>
				<div class="vbh-modal-actions">
					<NcButton variant="primary" @click="targetMode = 'iban'">
						{{ t('Ich habe nur ein neues Konto → IBAN ändern') }}
					</NcButton>
				</div>

				<h3 class="vbh-modal-subtitle">
					{{ t('Neues Mandat für den neuen Kontoinhaber') }}
				</h3>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Neue IBAN') }}
						<input v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
					</label>
					<label>{{ t('BIC') }}
						<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
					</label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Neuer Kontoinhaber') }}
						<input v-model="form.accountHolder" :placeholder="t('Vor- und Nachname')">
					</label>
				</div>

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
					<NcButton variant="secondary" :disabled="!canSaveHolder || saving" @click="saveHolder">
						{{ t('Kontoinhaber wechseln') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

function emptyForm() {
	return { iban: '', bic: '', accountHolder: '' }
}

/**
 * IBAN ändern ODER Kontoinhaberwechsel (Spec §3.4) in einem Dialog: die Wahl
 * "gleiches Konto vs. neuer Inhaber" IST der Ausweg aus dem
 * Kontoinhaberwechsel-Reibungsdialog (Spec „Ausweg als Primäraktion" -
 * `targetMode` auf 'iban' zurückzustellen ersetzt einen separaten
 * Dialogwechsel). Der Kontoinhaberwechsel selbst bleibt bewusst NICHT die
 * primäre Schaltfläche (variant="secondary"), der Ausweg-Button ist es.
 */
export default {
	name: 'SelfServiceMandateAccountDialog',
	components: { NcModal, NcButton, NcLoadingIcon },
	props: {
		show: { type: Boolean, default: false },
		/** Startmodus beim Öffnen - 'iban' (Standard) oder 'holder'. */
		initialMode: { type: String, default: 'iban' },
		openClaimsTotalCents: { type: Number, default: 0 },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save-iban', 'save-holder', 'update:show'],

	data() {
		return { targetMode: 'iban', form: emptyForm(), legalTextHtml: '', legalTextLoading: false }
	},

	computed: {
		canSaveHolder() {
			return !this.legalTextLoading && !!this.form.iban.trim() && !!this.form.accountHolder.trim()
		},
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.targetMode = this.initialMode
			this.form = emptyForm()
			if (this.targetMode === 'iban') {
				this.$nextTick(() => focusOnOpen(this, () => this.$refs.ibanInput))
			}
		},

		targetMode(mode) {
			if (mode === 'holder' && !this.legalTextHtml) {
				this.loadLegalText()
			}
		},
	},

	methods: {
		formatMoney,

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

		saveIban() {
			this.$emit('save-iban', { iban: this.form.iban.trim(), bic: this.form.bic.trim() || null })
		},

		saveHolder() {
			this.$emit('save-holder', {
				iban: this.form.iban.trim(),
				bic: this.form.bic.trim() || null,
				accountHolder: this.form.accountHolder.trim(),
			})
		},
	},
}
</script>
