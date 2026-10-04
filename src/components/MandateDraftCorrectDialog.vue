<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-draft-correct"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-draft-correct" class="vbh-modal-title">
				{{ t('Entwurf korrigieren') }}
			</h2>

			<p class="vbh-hint">
				{{ t('Über diesen Entwurf wurde noch nie eingezogen – Sie ändern die Angaben deshalb direkt, ohne Amendment. Jede Korrektur steht im Verlauf.') }}
			</p>
			<p v-if="linkSent" class="vbh-hint vbh-hint--warning">
				{{ t('Der bereits verschickte Einmal-Link wird mit der Korrektur ungültig – danach senden Sie dem Mitglied einen neuen.') }}
			</p>
			<p v-else-if="paper" class="vbh-hint">
				{{ t('Die Angaben müssen zum unterschriebenen Formular passen. Ein schon gedrucktes Formular mit den alten Angaben drucken Sie nach der Korrektur neu aus.') }}
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
					<input v-model="form.accountHolder">
				</label>
			</div>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ t('Entwurf korrigieren') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import { canSaveDraftCorrection, formatIban } from '../lib/mandateView.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/**
 * „Entwurf korrigieren“ in der Mitglieder-Akte (Issue #118, MandatePanel.vue):
 * IBAN, BIC und Kontoinhaber eines Mandats im Zustand `entwurf` ändern, ohne
 * Amendment – über einen Entwurf wurde nie eingezogen, die Bank kennt nichts,
 * was ein Amendment nachziehen müsste. Die Felder sind mit den gespeicherten
 * Werten vorbelegt (ein Tippfehler wird korrigiert, nicht neu getippt);
 * „Korrigieren“ bleibt gesperrt, solange nichts geändert ist.
 *
 * Bei einem elektronischen Entwurf mit bereits verschicktem Einmal-Link
 * warnt der Dialog vor der Folge (der Link wird ungültig, neuer Versand nötig).
 * Das Mitglied selbst kann im Self-Service nicht korrigieren, nur verwerfen
 * (MandateDraftDiscardDialog.vue) – darum gibt es dieses Gegenstück dort nicht.
 */
export default {
	name: 'MandateDraftCorrectDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		/** Der Entwurf (Mandat-API-Form inkl. `activationLink` der Einzelansicht); trägt die gespeicherten Werte. */
		mandate: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	data() {
		return { form: { iban: '', bic: '', accountHolder: '' } }
	},

	computed: {
		canSave() { return canSaveDraftCorrection(this.mandate, this.form) },

		/** Ein elektronischer Entwurf, dessen Einmal-Link schon unterwegs (oder abgelaufen) ist. */
		linkSent() { return !!this.mandate && this.mandate.signatureType === 'elektronisch' && !!this.mandate.activationLink },

		paper() { return !!this.mandate && this.mandate.signatureType === 'papier' },
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.form = {
				iban: formatIban(this.mandate?.iban),
				bic: this.mandate?.bic ?? '',
				accountHolder: this.mandate?.accountHolder ?? '',
			}
			focusOnOpen(this, () => this.$refs.ibanInput)
		},
	},

	methods: {
		save() {
			this.$emit('save', {
				iban: this.form.iban.trim(),
				bic: this.form.bic.trim() || null,
				accountHolder: this.form.accountHolder.trim(),
			})
		},
	},
}
</script>
