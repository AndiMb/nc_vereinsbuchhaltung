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

			<fieldset class="vbh-mandate-modes">
				<legend class="hidden-visually">
					{{ t('Was hat sich geändert?') }}
				</legend>
				<NcCheckboxRadioSwitch
					v-model="targetMode"
					type="radio"
					name="vbh-self-mandate-mode"
					value="iban">
					{{ t('Gleiches Konto, nur die IBAN hat sich geändert') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="targetMode"
					type="radio"
					name="vbh-self-mandate-mode"
					value="holder">
					{{ t('Der Kontoinhaber wechselt') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<template v-if="targetMode === 'iban'">
				<NcNoteCard type="info">
					{{ t('Vorschau: Wirkt ab sofort. Kein Sperrfenster – Sie können die IBAN bis zur Einreichung des nächsten Einzugs jederzeit ändern.') }}
				</NcNoteCard>
				<div class="vbh-mandate-fields">
					<MandateBankFields ref="bankFields" v-model:iban="form.iban" v-model:bic="form.bic" />
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
				<NcNoteCard type="error">
					<p>
						{{ t('Das bisherige Mandat wird endgültig beendet, ein neues wird sofort elektronisch erteilt. Das lässt sich nicht rückgängig machen.') }}
					</p>
					<p v-if="openClaimsTotalCents > 0">
						{{ t('Noch offen: {betrag}', { betrag: formatMoney(openClaimsTotalCents / 100) }) }}
					</p>
				</NcNoteCard>

				<NcNoteCard type="info">
					<p>
						{{ t('Nur ein neues Konto bei derselben Person? Dafür reicht die IBAN-Änderung – ohne neues Mandat.') }}
					</p>
					<NcButton class="vbh-notecard-action" variant="primary" @click="targetMode = 'iban'">
						{{ t('Ich habe nur ein neues Konto → IBAN ändern') }}
					</NcButton>
				</NcNoteCard>

				<h3 class="vbh-modal-subtitle">
					{{ t('Neues Mandat für den neuen Kontoinhaber') }}
				</h3>
				<div class="vbh-mandate-fields">
					<MandateBankFields v-model:iban="form.iban" v-model:bic="form.bic" />
					<NcTextField
						v-model="form.accountHolder"
						:label="t('Neuer Kontoinhaber')"
						:placeholder="t('Vor- und Nachname')" />
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
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import MandateBankFields from './MandateBankFields.vue'
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
	components: { NcModal, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcTextField, MandateBankFields },
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
				this.$nextTick(() => focusOnOpen(this, () => this.$refs.bankFields))
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

<style scoped>
/* Die Wahl „nur IBAN / neuer Inhaber“ als Liste untereinander, ohne den Rahmen des Fieldsets. */
.vbh-mandate-modes {
	margin: 0 0 8px;
	padding: 0;
	border: 0;
}
</style>
