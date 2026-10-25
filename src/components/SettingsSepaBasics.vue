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
			<NcCheckboxRadioSwitch :modelValue="membershipEnabled" type="switch" @update:modelValue="changeMembershipEnabled">
				{{ t('Reiter „Beiträge" in der Hauptnavigation zeigen (Mitgliederliste und Sammeleinzug)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="!membershipEnabled && !membershipActive" class="vbh-hint">
				{{ t('Ohne diesen Schalter bleibt der Reiter ausgeblendet, bis das erste Mandat oder der erste Beitrag angelegt wird.') }}
			</p>
			<NcCheckboxRadioSwitch :modelValue="selfServiceEnabled" type="switch" @update:modelValue="changeSelfServiceEnabled">
				{{ t('Self-Service „Mein Beitrag" für verknüpfte Nextcloud-Konten freischalten') }}
			</NcCheckboxRadioSwitch>
			<p class="vbh-hint">
				{{ t('Betrifft nur Mitglieder, deren Nextcloud-Konto in der Mitgliederakte verknüpft ist – sie sehen dann ihre eigenen Stammdaten, unabhängig von einer Buchhaltungsrolle.') }}
			</p>
			<NcCheckboxRadioSwitch
				:modelValue="selfServiceTransferSwitchEnabled"
				:disabled="!selfServiceEnabled"
				type="switch"
				@update:modelValue="changeSelfServiceTransferSwitchEnabled">
				{{ t('Mitglieder dürfen ihre Zahlungsart unter „Mein Beitrag" selbst auf Überweisung umstellen') }}
			</NcCheckboxRadioSwitch>
			<p class="vbh-hint">
				{{ t('Nur in diese Richtung: zurück zur Lastschrift stellt der Vorstand um („Beitrag ändern"). Das Mandat bleibt bei der Umstellung bestehen und wird nur nicht mehr genutzt.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { useAccounts } from '../composables/useAccounts.js'

/**
 * Die beiden Angaben, ohne die kein Sammeleinzug zustande kommt, und der Schalter
 * fuer den Reiter „Beiträge" (ContributionsTab.vue). Betrag und Turnus eines
 * Mitglieds kommen von seiner Beitragsgruppe (ContributionGroupDialog.vue), nicht
 * aus einer vereinsweiten Einstellung. Die Pflege der Mandate selbst steht
 * in MembersList.vue, wo sie zusammen mit den Beiträgen hingehört – vorher
 * lagen beide in getrennten Abschnitten, und jedes Mitglied musste zweimal
 * angelegt werden.
 *
 * Schreiben dürfen nur Verwalter (siehe SettingsController::update()).
 */
export default {
	name: 'SettingsSepaBasics',
	components: { NcButton, NcCheckboxRadioSwitch },
	props: {
		sepaCreditorId: { type: String, default: '' },
		sepaDebtorAccountId: { type: Number, default: null },
		membershipEnabled: { type: Boolean, default: false },
		// nur lesend - zeigt an, ob der Reiter unabhaengig vom Schalter schon
		// sichtbar ist (siehe App.vue::loadStorageSettings())
		membershipActive: { type: Boolean, default: false },
		// Self-Service-Zugang (Spec §3.4): einfacher Bool-Schalter, siehe
		// SelfServiceService/SettingsController.
		selfServiceEnabled: { type: Boolean, default: false },
		// Zusatzschalter zum Self-Service: Zahlungsart selbst auf Überweisung umstellen
		// (SelfServiceService::canSwitchToTransfer()); ohne Self-Service wirkungslos.
		selfServiceTransferSwitchEnabled: { type: Boolean, default: false },
		storageSaving: { type: Boolean, default: false },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsGeneral.vue
		saveSettings: { type: Function, required: true },
	},

	emits: ['update:membershipEnabled', 'update:selfServiceEnabled', 'update:selfServiceTransferSwitchEnabled', 'update:sepaCreditorId', 'update:sepaDebtorAccountId'],

	setup() {
		return { ...toRefs(useAccounts().state) }
	},

	computed: {
		bankAccounts() { return this.accounts.filter((a) => a.isBank) },
		sepaCreditorIdModel: {
			get() { return this.sepaCreditorId },
			set(v) { this.$emit('update:sepaCreditorId', v) },
		},

		sepaDebtorAccountIdModel: {
			get() { return this.sepaDebtorAccountId },
			set(v) { this.$emit('update:sepaDebtorAccountId', v) },
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

		changeSelfServiceEnabled(v) {
			this.$emit('update:selfServiceEnabled', v)
			this.saveSettings()
		},

		changeSelfServiceTransferSwitchEnabled(v) {
			this.$emit('update:selfServiceTransferSwitchEnabled', v)
			this.saveSettings()
		},
	},
}
</script>
