<template>
	<div>
		<p class="vbh-hint">
			{{ t('Rein optionales Zusatzmodul für Vereine, die Mitgliedsbeiträge per Lastschrift einziehen. Wer das nicht braucht, kann diesen Abschnitt einfach ignorieren.') }}
		</p>

		<div class="vbh-card">
			<h4>{{ t('Grundeinstellungen') }}</h4>
			<p class="vbh-hint">
				{{ t('Gläubiger-ID und einziehendes Konto werden für den Sammeleinzug gebraucht. Die Gläubiger-ID vergibt die Deutsche Bundesbank auf Antrag; am einziehenden Konto muss eine IBAN hinterlegt sein.') }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('SEPA-Gläubiger-ID') }}
					<input v-model="sepaCreditorIdModel" placeholder="DE98ZZZ09999999999">
				</label>
				<label class="vbh-grow">{{ t('Einziehendes Konto') }}
					<select v-model="sepaDebtorAccountIdModel">
						<option :value="null">
							{{ t('– Konto wählen –') }}
						</option>
						<option v-for="a in bankAccounts" :key="a.id" :value="a.id">
							{{ a.number }} · {{ a.name }}
						</option>
					</select>
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveSettings">
					{{ t('Speichern') }}
				</NcButton>
			</div>
			<NcCheckboxRadioSwitch :checked="membershipEnabled" type="switch" @update:checked="changeMembershipEnabled">
				{{ t('Reiter „Beiträge" in der Hauptnavigation zeigen (Mitgliederliste und Sammeleinzug)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="!membershipEnabled && !membershipActive" class="vbh-hint">
				{{ t('Ohne diesen Schalter bleibt der Reiter ausgeblendet, bis das erste Mandat oder der erste Beitrag angelegt wird.') }}
			</p>
		</div>

		<div class="vbh-card">
			<h4>{{ t('Standard-Beitrag') }}</h4>
			<p class="vbh-hint">
				{{ t('Haben fast alle Mitglieder denselben Beitrag (z. B. 8 € monatlich), hier einmal hinterlegen: „Mitglied aufnehmen" schlägt Betrag und Frequenz dann vor, statt bei jedem Mitglied neu einzutippen. Abweichende Einzelfälle lassen sich weiterhin frei überschreiben.') }}
			</p>
			<div class="vbh-form">
				<label>{{ t('Betrag (€)') }}
					<input v-model="defaultFeeAmountModel"
						type="number"
						step="0.01"
						min="0"
						class="vbh-short"
						placeholder="8,00">
				</label>
				<label>{{ t('Frequenz') }}
					<select v-model="defaultFeeFrequencyModel">
						<option v-for="f in frequencies" :key="f.value" :value="f.value">
							{{ f.label }}
						</option>
					</select>
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveSettings">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useAccounts } from '../composables/useAccounts.js'
import { frequencyOptions } from '../lib/frequency.js'

/**
 * Die beiden Angaben, ohne die kein Sammeleinzug zustande kommt, der Schalter
 * fuer den Reiter „Beiträge" (ContributionsTab.vue), und der optionale
 * Standard-Beitrag (Betrag/Frequenz), den MemberDialog.vue beim Anlegen eines
 * neuen Mitglieds vorschlaegt - bei 80-100 Mitgliedern mit einheitlichem Satz
 * sonst 80-100 Mal derselbe Wert von Hand. Die Pflege der Mandate selbst steht
 * in MembersList.vue, wo sie zusammen mit den Beiträgen hingehört – vorher
 * lagen beide in getrennten Abschnitten, und jedes Mitglied musste zweimal
 * angelegt werden.
 *
 * Erreichbar ab Rolle Buchhalter (siehe SepaMandateController).
 */
export default {
	name: 'SettingsSepaBasics',
	components: { NcButton, NcCheckboxRadioSwitch },
	props: {
		sepaCreditorId: { type: String, default: '' },
		sepaDebtorAccountId: { type: Number, default: null },
		defaultFeeAmount: { type: [Number, String], default: '' },
		defaultFeeFrequency: { type: String, default: 'yearly' },
		membershipEnabled: { type: Boolean, default: false },
		// nur lesend - zeigt an, ob der Reiter unabhaengig vom Schalter schon
		// sichtbar ist (siehe App.vue::loadStorageSettings())
		membershipActive: { type: Boolean, default: false },
		storageSaving: { type: Boolean, default: false },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsGeneral.vue
		saveSettings: { type: Function, required: true },
	},
	setup() {
		return { ...toRefs(useAccounts().state) }
	},
	data() {
		return { frequencies: frequencyOptions() }
	},
	computed: {
		bankAccounts() { return this.accounts.filter(a => a.isBank) },
		sepaCreditorIdModel: {
			get() { return this.sepaCreditorId },
			set(v) { this.$emit('update:sepaCreditorId', v) },
		},
		sepaDebtorAccountIdModel: {
			get() { return this.sepaDebtorAccountId },
			set(v) { this.$emit('update:sepaDebtorAccountId', v) },
		},
		defaultFeeAmountModel: {
			get() { return this.defaultFeeAmount },
			set(v) { this.$emit('update:defaultFeeAmount', v) },
		},
		defaultFeeFrequencyModel: {
			get() { return this.defaultFeeFrequency },
			set(v) { this.$emit('update:defaultFeeFrequency', v) },
		},
	},
	methods: {
		// Kein reiner .sync-Setter: die neue Sichtbarkeit muss noch VOR dem
		// Speichern beim Elternteil ankommen, sonst sendet saveSettings() den
		// alten Wert (siehe App.vue::saveStorageSettings()).
		changeMembershipEnabled(v) {
			this.$emit('update:membershipEnabled', v)
			this.saveSettings()
		},
	},
}
</script>
