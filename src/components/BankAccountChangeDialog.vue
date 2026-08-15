<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-bankchange"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-bankchange" class="vbh-modal-title">
				{{ t('Bankverbindung wechseln') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Das bisherige Mandat für „{name}" wird widerrufen, ein neues mit der eingegebenen Bankverbindung angelegt. Noch offene, nicht eingezogene Beiträge und Posten wandern automatisch auf das neue Mandat – sonst würden sie beim nächsten Einzug stillschweigend übersprungen.', { name: displayName }) }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Neue IBAN') }}
					<input v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
				</label>
				<label>{{ t('BIC') }}
					<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Mandat unterschrieben am') }}
					<input v-model="form.signedDate" type="date">
				</label>
				<label class="vbh-grow">{{ t('E-Mail für die Vorankündigung') }}
					<input v-model="form.email" type="email" :placeholder="t('optional')">
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('Der erste Einzug über das neue Mandat läuft als Ersteinzug (FRST) – eine neue Bankverbindung ist SEPA-rechtlich eine neue Einzugsermächtigung.') }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ t('Wechseln') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

function emptyForm() {
	return { iban: '', bic: '', signedDate: new Date().toISOString().slice(0, 10), email: '' }
}

/**
 * Wechselt die Bankverbindung eines Mandats (siehe
 * SepaMandateService::changeBankAccount()). Eigener Dialog statt Inline-
 * Formular wie bei den übrigen Zeilenaktionen, weil er mehrere Felder braucht
 * und die Konsequenz (altes Mandat weg, neues da, Beiträge umgehängt) eine
 * bewusste Eingabe verdient statt eines Klicks in der Tabellenzeile.
 */
export default {
	name: 'BankAccountChangeDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		mandate: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	data() {
		return { form: emptyForm() }
	},

	computed: {
		displayName() { return this.mandate?.displayName || '' },
		canSave() {
			return !!this.form.iban.trim() && !!this.form.signedDate
		},
	},

	watch: {
		show(open) {
			if (open) { this.form = emptyForm() }
		},
	},

	methods: {
		save() {
			this.$emit('save', {
				iban: this.form.iban.trim(),
				bic: this.form.bic.trim() || null,
				mandateType: this.mandate?.mandateType || 'RCUR',
				signedDate: this.form.signedDate,
				email: this.form.email.trim() || null,
			})
		},
	},
}
</script>
