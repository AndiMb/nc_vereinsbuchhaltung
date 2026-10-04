<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-draft-discard"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-draft-discard" class="vbh-modal-title">
				{{ t('Mandats-Entwurf verwerfen') }}
			</h2>

			<div class="vbh-card vbh-card--danger">
				<p v-if="staff">
					{{ t('Der Entwurf endet damit und lässt sich nicht wiederherstellen. Eingezogen wurde über ihn nie etwas; danach können Sie ein neues Mandat anlegen.') }}
				</p>
				<p v-else>
					{{ t('Der Entwurf endet damit und lässt sich nicht wiederherstellen. Es wurde nie etwas darüber eingezogen; danach können Sie ein neues Mandat erteilen.') }}
				</p>
				<p v-if="staff && electronic">
					{{ t('Ein bereits verschickter Einmal-Link wird ungültig.') }}
				</p>
			</div>

			<template v-if="staff">
				<p class="vbh-hint">
					{{ t('Nur ein Tippfehler in den Angaben? Dann korrigieren Sie den Entwurf stattdessen – er bleibt bestehen.') }}
				</p>
				<div class="vbh-modal-actions">
					<NcButton variant="primary" :disabled="saving" @click="$emit('switch-to-correct')">
						{{ t('Entwurf stattdessen korrigieren') }}
					</NcButton>
				</div>

				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Grund des Verwerfens (Pflicht)') }}
						<textarea
							ref="noteInput"
							v-model="note"
							rows="2"
							:placeholder="t('z. B. Mitglied wünscht kein Lastschriftmandat')" />
					</label>
				</div>
				<p class="vbh-hint">
					{{ t('Der Grund steht im Verlauf des Mandats.') }}
				</p>
			</template>
			<p v-else class="vbh-hint">
				{{ t('Nur die IBAN vertippt? Verwerfen Sie den Entwurf und erteilen Sie das Mandat danach neu, mit den richtigen Angaben.') }}
			</p>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="saving || !canConfirm" @click="$emit('save', note.trim())">
					{{ t('Entwurf endgültig verwerfen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import { focusOnOpen } from '../lib/modalFocus.js'

/**
 * Bestätigungsdialog „Mandats-Entwurf verwerfen“ (Issue #118) – derselbe
 * Dialog für beide Seiten wie SelfServiceMandateRevokeDialog.vue:
 *
 * - Verwaltung (`staff`, MandatePanel.vue): Pflicht-Notiz zum Grund; als
 *   Ausweg der Weg „stattdessen korrigieren“ (Tippfehler), wie beim Widerruf
 *   der Ausweg „nur ein neues Konto“ die Primäraktion ist (Spec §3.4).
 * - Mitglied (SelfServiceTab.vue): keine Notiz – wer verworfen hat, steht über
 *   den Kanal ohnehin im Verlauf, das Backend setzt eine feste Notiz. Statt des
 *   Korrektur-Wegs (den hat das Mitglied nicht) der Hinweis, nach dem Verwerfen
 *   neu zu erteilen.
 *
 * Das ist kein Widerruf: der Entwurf war nie wirksam, es wird nichts
 * zurückgenommen und keine Zahlungsaufforderung ausgelöst. `save` liefert die
 * (bereinigte) Notiz; im Self-Service bleibt sie leer und wird ignoriert.
 */
export default {
	name: 'MandateDraftDiscardDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		/** Verwaltungssicht (Mitglieder-Akte): Pflicht-Notiz und Korrektur-Ausweg statt der Mitgliedersicht. */
		staff: { type: Boolean, default: false },
		/** Elektronischer Entwurf: ein ausgesendeter Einmal-Link wird mit ungültig. */
		electronic: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'switch-to-correct', 'update:show'],

	data() {
		return { note: '' }
	},

	computed: {
		/** Die Verwaltung muss einen Grund nennen (Pflicht-Notiz); das Mitglied nicht. */
		canConfirm() { return !this.staff || this.note.trim() !== '' },
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.note = ''
			if (this.staff) {
				focusOnOpen(this, () => this.$refs.noteInput)
			}
		},
	},
}
</script>
