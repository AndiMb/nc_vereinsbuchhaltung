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
				{{ t('Mandat {referenz} von {inhaber}, bisherige IBAN {iban}.', { referenz: mandate.mandateReference, inhaber: mandate.accountHolder, iban: formatIban(mandate.iban) }) }}
			</p>

			<div class="vbh-form vbh-mandate-modes">
				<label>
					<input v-model="targetMode" type="radio" value="iban">
					{{ t('Gleicher Kontoinhaber, nur die IBAN hat sich geändert') }}
				</label>
				<label>
					<input v-model="targetMode" type="radio" value="name">
					{{ t('Derselbe Kontoinhaber, nur der Name war falsch geschrieben') }}
				</label>
				<label>
					<input v-model="targetMode" type="radio" value="holder">
					{{ t('Der Kontoinhaber wechselt (andere Person)') }}
				</label>
			</div>

			<template v-if="targetMode === 'iban'">
				<p class="vbh-hint">
					{{ t('Dasselbe Mandat bleibt bestehen, eine neue Unterschrift ist nicht nötig. Die Änderung wird der Bank beim nächsten Einzug als Amendment gemeldet.') }}
				</p>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Neue IBAN') }}
						<input ref="ibanInput" v-model="ibanForm.iban" placeholder="DE12 5001 0517 0648 4898 90">
					</label>
					<label>{{ t('BIC') }}
						<input v-model="ibanForm.bic" class="vbh-short" :placeholder="t('optional')">
					</label>
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
				<p class="vbh-hint">
					{{ t('Stille Korrektur (Tippfehler, Heirat): kein Amendment, kein neues Mandat. Nur wählen, wenn es dieselbe Person bleibt – sonst ist es ein Kontoinhaberwechsel.') }}
				</p>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Kontoinhaber') }}
						<input v-model="nameForm.accountHolder">
					</label>
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
				<div class="vbh-card vbh-card--danger">
					<p>
						{{ t('Das bisherige Mandat wird endgültig beendet. Für den neuen Kontoinhaber entsteht ein neues Mandat, das eine eigene Unterschrift braucht. Das lässt sich nicht rückgängig machen.') }}
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
						<input v-model="holderForm.iban" placeholder="DE12 5001 0517 0648 4898 90">
					</label>
					<label>{{ t('BIC') }}
						<input v-model="holderForm.bic" class="vbh-short" :placeholder="t('optional')">
					</label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Neuer Kontoinhaber') }}
						<input v-model="holderForm.accountHolder" :placeholder="t('Vor- und Nachname')">
					</label>
					<label>{{ t('Neues Mandat unterschrieben am') }}
						<input v-model="holderForm.signedAt" type="date">
					</label>
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
import { NcButton, NcModal } from '@nextcloud/vue'
import { formatMoney } from '../lib/format.js'
import { formatIban } from '../lib/mandateView.js'
import { focusOnOpen } from '../lib/modalFocus.js'

const emptyHolderForm = () => ({ iban: '', bic: '', accountHolder: '', signedAt: '' })

/** Schreibweise für den Vergleich „hat sich etwas geändert“: ohne Leerzeichen, Großbuchstaben. */
const normalize = (s) => String(s ?? '').replace(/\s+/g, '').toUpperCase()

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
	components: { NcModal, NcButton },
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
			return normalize(this.ibanForm.iban) !== normalize(this.mandate.iban)
				|| normalize(this.ibanForm.bic) !== normalize(this.mandate.bic)
		},

		canSaveName() {
			const name = this.nameForm.accountHolder.trim()
			return !!this.mandate && name !== '' && name !== this.mandate.accountHolder
		},

		canSaveHolder() {
			return !!this.holderForm.iban.trim() && !!this.holderForm.accountHolder.trim()
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
				focusOnOpen(this, () => this.$refs.ibanInput)
			}
		},
	},

	methods: {
		formatMoney,
		formatIban,

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
/*
 * Die drei Fälle als Radio-Liste untereinander. `.vbh-form label` stapelt
 * sonst Auswahlpunkt über Beschriftung (Feldbezeichnungen stehen über dem
 * Eingabefeld) – hier gehört der Punkt neben den Text.
 */
.vbh-mandate-modes {
	flex-direction: column;
	align-items: flex-start;
	gap: 6px;
}

.vbh-mandate-modes label {
	flex-direction: row;
	align-items: center;
	gap: 8px;
	font-size: 1em;
}
</style>
