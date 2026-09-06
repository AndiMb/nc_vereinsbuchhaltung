<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-member"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-member" class="vbh-modal-title">
				{{ t('Mitglied aufnehmen') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Ein Mitglied besteht hier aus zwei Angaben: seiner Bankverbindung (dem SEPA-Mandat) und seinem Beitrag. Beides ist einzeln möglich: ohne IBAN entsteht nur ein Beitrag (etwa für Überweiser), ohne Betrag nur ein Mandat.') }}
			</p>
			<div class="vbh-form">
				<label>{{ t('Zahler') }}
					<select v-model="form.memberKind">
						<option value="label">{{ t('Freier Zahlername') }}</option>
						<option value="user">{{ t('Nextcloud-Nutzer') }}</option>
					</select>
				</label>
				<label v-if="form.memberKind === 'user'" class="vbh-grow">{{ t('Nutzer') }}
					<NcSelect
						v-model="formMemberOption"
						:options="userOptions"
						label="label"
						:placeholder="t('– Nutzer wählen –')" />
				</label>
				<label v-else class="vbh-grow">{{ t('Name') }}
					<input v-model="form.memberLabel" :placeholder="t('z. B. Katrin Brunner')">
				</label>
				<label class="vbh-grow">{{ t('E-Mail') }}
					<input v-model="form.email" type="email" :placeholder="t('für die Vorankündigung')">
				</label>
			</div>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('IBAN') }}
					<input v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
				</label>
				<label>{{ t('BIC') }}
					<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
				</label>
				<label>{{ t('Mandat unterschrieben am') }}
					<input v-model="form.signedDate" type="date">
				</label>
			</div>

			<div class="vbh-form">
				<label>{{ t('Betrag (€)') }}
					<AmountInput
						v-model="form.amount"
						class="vbh-short" />
				</label>
				<label>{{ t('Frequenz') }}
					<select v-model="form.frequency">
						<option v-for="f in frequencies" :key="f.value" :value="f.value">
							{{ f.label }}
						</option>
					</select>
				</label>
				<label>{{ t('Erste Fälligkeit') }}
					<input v-model="form.startDate" type="date">
				</label>
				<label class="vbh-grow">{{ t('Ertragskonto') }}
					<select v-model="form.accountId">
						<option :value="null">
							{{ t('– optional –') }}
						</option>
						<option v-for="a in incomeAccounts" :key="a.id" :value="a.id">
							{{ a.number }} · {{ a.name }}
						</option>
					</select>
				</label>
			</div>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ t('Aufnehmen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcSelect } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AmountInput from './AmountInput.vue'
import { useAccounts } from '../composables/useAccounts.js'
import { usePermissions } from '../composables/usePermissions.js'
import { frequencyOptions } from '../lib/frequency.js'

function emptyForm() {
	return {
		memberKind: 'label',
		memberUid: '',
		memberLabel: '',
		email: '',
		iban: '',
		bic: '',
		signedDate: new Date().toISOString().slice(0, 10),
		amount: '',
		frequency: 'yearly',
		startDate: new Date().toISOString().slice(0, 10),
		accountId: null,
	}
}

/**
 * „Mitglied aufnehmen" – aus MembersList.vue (frueher SettingsMembers.vue)
 * herausgeloest, damit die Liste selbst nicht laenger unter dem Formular
 * beginnt (siehe NAVIGATION-KONZEPT.md Abschnitt 4).
 */
export default {
	name: 'MemberDialog',
	components: { NcModal, NcButton, NcSelect, AmountInput },
	props: {
		show: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		// Vorbelegung aus Einstellungen -> Beiträge & SEPA (SettingsSepaBasics.vue),
		// leerer String heisst "kein Standardbeitrag hinterlegt".
		defaultFeeAmount: { type: [Number, String], default: '' },
		defaultFeeFrequency: { type: String, default: 'yearly' },
	},

	emits: ['close', 'save', 'update:show'],

	setup() {
		return { ...toRefs(useAccounts().state), ...toRefs(usePermissions().state) }
	},

	data() {
		return {
			form: emptyForm(),
			frequencies: frequencyOptions(),
		}
	},

	computed: {
		userOptions() { return this.users.map((u) => ({ id: u.id, label: `${u.displayName} (${u.id})` })) },
		formMemberOption: {
			get() { return this.userOptions.find((o) => o.id === this.form.memberUid) ?? null },
			set(v) { this.form.memberUid = v ? v.id : '' },
		},

		incomeAccounts() {
			return this.accounts.filter((a) => a.type === 'income' && !a.isBank)
				.slice()
				.sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
		},

		canSave() {
			const hasMember = this.form.memberKind === 'user' ? !!this.form.memberUid : !!this.form.memberLabel.trim()
			const hasMandate = !!this.form.iban.trim() && !!this.form.signedDate
			const hasFee = Number(this.form.amount) > 0 && !!this.form.startDate
			return hasMember && (hasMandate || hasFee)
		},
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.form = emptyForm()
			// Vorbelegung: bei 80-100 Mitgliedern mit einheitlichem Beitrag muss
			// der Betrag sonst jedes Mal von Hand eingetippt werden. Wer einen
			// abweichenden Einzelfall anlegt, ueberschreibt das Feld einfach.
			if (this.defaultFeeAmount !== '' && this.defaultFeeAmount !== null) {
				this.form.amount = this.defaultFeeAmount
				this.form.frequency = this.defaultFeeFrequency
			}
		},
	},

	methods: {
		save() {
			if (!this.canSave) { return }
			this.$emit('save', {
				memberUid: this.form.memberKind === 'user' ? this.form.memberUid : null,
				memberLabel: this.form.memberKind === 'label' ? this.form.memberLabel.trim() : null,
				email: this.form.email.trim() || null,
				iban: this.form.iban.trim(),
				bic: this.form.bic.trim() || null,
				signedDate: this.form.signedDate,
				amount: this.form.amount,
				frequency: this.form.frequency,
				startDate: this.form.startDate,
				accountId: this.form.accountId,
			})
		},
	},
}
</script>
