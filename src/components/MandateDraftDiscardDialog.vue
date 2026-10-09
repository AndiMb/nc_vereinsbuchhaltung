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

			<NcNoteCard type="error">
				<p v-if="staff">
					{{ t('Der Entwurf endet damit und lässt sich nicht wiederherstellen. Eingezogen wurde über ihn nie etwas; danach können Sie ein neues Mandat anlegen.') }}
				</p>
				<p v-else>
					{{ t('Der Entwurf endet damit und lässt sich nicht wiederherstellen. Es wurde nie etwas darüber eingezogen; danach können Sie ein neues Mandat erteilen.') }}
				</p>
				<p v-if="staff && electronic">
					{{ t('Ein bereits verschickter Einmal-Link wird ungültig.') }}
				</p>
			</NcNoteCard>

			<template v-if="staff">
				<NcNoteCard type="info">
					<p>
						{{ t('Nur ein Tippfehler in den Angaben? Dann korrigieren Sie den Entwurf stattdessen – er bleibt bestehen.') }}
					</p>
					<NcButton
						class="vbh-notecard-action"
						variant="primary"
						:disabled="saving"
						@click="$emit('switch-to-correct')">
						{{ t('Entwurf stattdessen korrigieren') }}
					</NcButton>
				</NcNoteCard>

				<NcTextArea
					ref="noteInput"
					v-model="note"
					:label="t('Grund des Verwerfens (Pflicht)')"
					:placeholder="t('z. B. Mitglied wünscht kein Lastschriftmandat')"
					:helperText="t('Der Grund steht im Verlauf des Mandats.')"
					rows="2" />
			</template>
			<NcNoteCard v-else type="info">
				{{ t('Nur die IBAN vertippt? Verwerfen Sie den Entwurf und erteilen Sie das Mandat danach neu, mit den richtigen Angaben.') }}
			</NcNoteCard>

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
import { NcButton, NcModal, NcNoteCard, NcTextArea } from '@nextcloud/vue'
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
	components: { NcModal, NcButton, NcNoteCard, NcTextArea },
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
