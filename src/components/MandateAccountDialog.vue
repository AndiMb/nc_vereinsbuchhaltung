<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-staff-account"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-staff-account" class="vbh-modal-title">
				{{ t('Bankverbindung ändern') }}
			</h2>

			<p v-if="mandate" class="vbh-hint">
				{{ mandateAccountHint(mandate) }}
			</p>

			<fieldset class="vbh-mandate-modes">
				<legend class="hidden-visually">
					{{ t('Was hat sich geändert?') }}
				</legend>
				<NcCheckboxRadioSwitch
					v-model="targetMode"
					type="radio"
					name="vbh-staff-mandate-mode"
					value="iban">
					{{ t('Gleicher Kontoinhaber, nur die IBAN hat sich geändert') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="targetMode"
					type="radio"
					name="vbh-staff-mandate-mode"
					value="name">
					{{ t('Derselbe Kontoinhaber, nur der Name war falsch geschrieben') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="targetMode"
					type="radio"
					name="vbh-staff-mandate-mode"
					value="holder">
					{{ t('Der Kontoinhaber wechselt (andere Person)') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<template v-if="targetMode === 'iban'">
				<NcNoteCard type="info">
					{{ t('Dasselbe Mandat bleibt bestehen, eine neue Unterschrift ist nicht nötig. Die Änderung wird der Bank beim nächsten Einzug als Amendment gemeldet.') }}
				</NcNoteCard>
				<div class="vbh-mandate-fields">
					<MandateBankFields ref="bankFields" v-model:iban="ibanForm.iban" v-model:bic="ibanForm.bic" />
				</div>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="$emit('close')">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="!canSaveIban || saving" @click="saveIban">
						{{ t('IBAN ändern') }}
					</NcButton>
				</div>
			</template>

			<template v-else-if="targetMode === 'name'">
				<NcNoteCard type="info">
					{{ t('Stille Korrektur (Tippfehler, Heirat): kein Amendment, kein neues Mandat. Nur wählen, wenn es dieselbe Person bleibt – sonst ist es ein Kontoinhaberwechsel.') }}
				</NcNoteCard>
				<div class="vbh-mandate-fields">
					<NcTextField v-model="nameForm.accountHolder" :label="t('Kontoinhaber')" />
				</div>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="$emit('close')">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="!canSaveName || saving" @click="saveName">
						{{ t('Name korrigieren') }}
					</NcButton>
				</div>
			</template>

			<template v-else>
				<NcNoteCard type="error">
					<p>
						{{ t('Das bisherige Mandat wird endgültig beendet. Für den neuen Kontoinhaber entsteht ein neues Mandat, das eine eigene Unterschrift braucht. Das lässt sich nicht rückgängig machen.') }}
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
					<MandateBankFields v-model:iban="holderForm.iban" v-model:bic="holderForm.bic" />
					<NcTextField
						v-model="holderForm.accountHolder"
						:label="t('Neuer Kontoinhaber')"
						:placeholder="t('Vor- und Nachname')" />
					<NcDateTimePickerNative
						v-model="signedAtDate"
						type="date"
						:label="t('Neues Mandat unterschrieben am')" />
				</div>
				<p class="vbh-hint">
					{{ t('Mit Unterschriftsdatum ist das neue Mandat sofort aktiv, ohne bleibt es ein Entwurf („Unterschrift fehlt“), bis Sie es aktivieren.') }}
				</p>
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
import { NcButton, NcCheckboxRadioSwitch, NcDateTimePickerNative, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import MandateBankFields from './MandateBankFields.vue'
import { formatMoney } from '../lib/format.js'
import { formatIban, mandateAccountHint, normalizeBankValue } from '../lib/mandateView.js'
import { focusOnOpen } from '../lib/modalFocus.js'

const emptyHolderForm = () => ({ iban: '', bic: '', accountHolder: '', signedAt: '' })

/**
 * Bankverbindung eines Mandats ändern – die drei Fälle der Tabelle „Amendment
 * vs. neues Mandat“ (Spec §2.2) in einem Dialog: nur die IBAN (Amendment,
 * dasselbe Mandat), nur der Name (stille Korrektur) oder ein neuer
 * Kontoinhaber (neues Mandat, das alte endet als ersetzt). Wie im
 * Self-Service-Dialog (SelfServiceMandateAccountDialog.vue) ist die Wahl
 * „gleiches Konto vs. neuer Inhaber“ zugleich der Ausweg aus dem
 * Kontoinhaberwechsel-Reibungsdialog (Spec §3.4: „Ausweg als Primäraktion“):
 * der Wechsel selbst bleibt `variant="secondary"`, der Ausweg-Knopf ist
 * primär.
 *
 * Bewusst KEIN gemeinsamer Dialog mit dem Self-Service-Gegenstück: die
 * Verwaltung ersetzt ein Mandat nicht elektronisch mit Sofort-Zustimmung,
 * sondern legt einen Papier-Entwurf an (optional gleich mit
 * Unterschriftsdatum) und zeigt deshalb keinen Rechtstext; dazu kommt der
 * dritte Fall (Namenskorrektur), den ein Mitglied selbst nicht auslösen kann.
 */
export default {
	name: 'MandateAccountDialog',
	components: { NcModal, NcButton, NcCheckboxRadioSwitch, NcDateTimePickerNative, NcNoteCard, NcTextField, MandateBankFields },
	props: {
		show: { type: Boolean, default: false },
		/** Das aktive Mandat (Mandat-API-Form); trägt die bisherigen Werte für den Vorbelegungs-/Änderungsvergleich. */
		mandate: { type: Object, default: null },
		/** Startmodus beim Öffnen - 'iban' (Standard), 'name' oder 'holder'. */
		initialMode: { type: String, default: 'iban' },
		openClaimsTotalCents: { type: Number, default: 0 },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save-iban', 'save-name', 'save-holder', 'update:show'],

	data() {
		return {
			targetMode: 'iban',
			ibanForm: { iban: '', bic: '' },
			nameForm: { accountHolder: '' },
			holderForm: emptyHolderForm(),
		}
	},

	computed: {
		canSaveIban() {
			if (!this.mandate || !this.ibanForm.iban.trim()) { return false }
			return normalizeBankValue(this.ibanForm.iban) !== normalizeBankValue(this.mandate.iban)
				|| normalizeBankValue(this.ibanForm.bic) !== normalizeBankValue(this.mandate.bic)
		},

		canSaveName() {
			const name = this.nameForm.accountHolder.trim()
			return !!this.mandate && name !== '' && name !== this.mandate.accountHolder
		},

		canSaveHolder() {
			return !!this.holderForm.iban.trim() && !!this.holderForm.accountHolder.trim()
		},

		// Der Datumswähler arbeitet mit Date, die Schnittstelle mit „JJJJ-MM-TT“ (lokales Datum, ohne UTC-Versatz).
		signedAtDate: {
			get() { return this.holderForm.signedAt ? new Date(`${this.holderForm.signedAt}T00:00:00`) : null },
			set(date) {
				this.holderForm.signedAt = date instanceof Date && !Number.isNaN(date.getTime())
					? [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-')
					: ''
			},
		},
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.targetMode = this.initialMode
			// IBAN/BIC/Name sind vorbelegt, damit ein Tippfehler korrigiert statt neu
			// getippt wird; der Vergleich oben hält „unverändert“ gesperrt. Beim
			// Kontoinhaberwechsel bleibt alles leer – dort ist das Konto meist ein anderes.
			this.ibanForm = { iban: formatIban(this.mandate?.iban), bic: this.mandate?.bic ?? '' }
			this.nameForm = { accountHolder: this.mandate?.accountHolder ?? '' }
			this.holderForm = emptyHolderForm()
			if (this.targetMode === 'iban') {
				focusOnOpen(this, () => this.$refs.bankFields)
			}
		},
	},

	methods: {
		formatMoney,
		formatIban,
		mandateAccountHint,

		saveIban() {
			this.$emit('save-iban', { iban: this.ibanForm.iban.trim(), bic: this.ibanForm.bic.trim() || null })
		},

		saveName() {
			this.$emit('save-name', { accountHolder: this.nameForm.accountHolder.trim() })
		},

		saveHolder() {
			this.$emit('save-holder', {
				iban: this.holderForm.iban.trim(),
				bic: this.holderForm.bic.trim() || null,
				accountHolder: this.holderForm.accountHolder.trim(),
				signedAt: this.holderForm.signedAt || null,
			})
		},
	},
}
</script>

<style scoped>
/* Die Wahl der Fälle als Liste untereinander, ohne den Rahmen des Fieldsets. */
.vbh-mandate-modes {
	margin: 0 0 8px;
	padding: 0;
	border: 0;
}
</style>
