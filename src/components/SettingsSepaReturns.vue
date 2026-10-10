<template>
	<div class="vbh-card">
		<h4>{{ t('Rücklastschriften und Mahnwesen') }}</h4>
		<p class="vbh-hint">
			{{ t('Wird eine Lastschrift zurückgegeben, bucht die App den Beitrag auf das ursprüngliche Erlöskonto zurück und die Bankgebühr auf das Konto für Rücklastschriftgebühren. Ohne Standard-Erlöskonto lassen sich Beitragsforderungen ohne eigenes Erlöskonto weder als Einzug noch als Rücklastschrift verbuchen.') }}
		</p>
		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Konto für Rücklastschriftgebühren (Aufwand)') }}
				<select v-model="draft.returnFeeAccountId">
					<option :value="null">
						{{ t('– Konto wählen –') }}
					</option>
					<option v-for="a in feeAccounts" :key="a.id" :value="a.id">
						{{ a.label }}{{ a.unsuitable ? t(' (nicht geeignet)') : '' }}
					</option>
				</select>
			</label>
			<label class="vbh-grow">{{ t('Standard-Erlöskonto für Beitragsforderungen (Ertrag)') }}
				<select v-model="draft.contributionDefaultAccountId">
					<option :value="null">
						{{ t('– Konto wählen –') }}
					</option>
					<option v-for="a in revenueAccounts" :key="a.id" :value="a.id">
						{{ a.label }}{{ a.unsuitable ? t(' (nicht geeignet)') : '' }}
					</option>
				</select>
			</label>
		</div>
		<p v-if="feeAccountMissing || revenueAccountMissing" class="vbh-hint vbh-hint--warning">
			{{ t('Ein gespeichertes Konto gibt es nicht mehr. Bitte wählen Sie ein neues Konto und speichern Sie.') }}
		</p>
		<NcCheckboxRadioSwitch v-model="draft.returnFeeRechargeEnabled" type="switch">
			{{ t('Rücklastschriftgebühren an das Mitglied weiterbelasten') }}
		</NcCheckboxRadioSwitch>
		<p class="vbh-hint">
			{{ t('Aus (Standard): Die Bankgebühr trägt der Verein. An: Bei Rücklastschriften wegen fehlender Deckung oder unbrauchbarem Konto stellt die App dem Mitglied die Bankgebühr als eigene Forderung in Rechnung, gebucht auf dem Konto für Rücklastschriftgebühren. Dafür muss ein Konto gewählt sein.') }}
		</p>
		<div class="vbh-form">
			<label>{{ t('Mahnabstand (Tage)') }}
				<input
					v-model.number="draft.dunningIntervalDays"
					type="number"
					class="vbh-short"
					:min="daysMin"
					:max="daysMax">
			</label>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<p class="vbh-hint">
			{{ t('So viele Tage liegen zwischen Zahlungsaufforderung und Zahlungserinnerung sowie zwischen Zahlungserinnerung und Mahnung (Standard 14 Tage). Eine Stundung hält die Mahnuhr an.') }}
		</p>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useAccounts } from '../composables/useAccounts.js'
import { useSepaSettings } from '../composables/useSepaSettings.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'
import { accountMissing, accountOptions, DAYS_MAX, DAYS_MIN, daysError } from '../lib/sepaSettings.js'

/**
 * Rücklastschriften und Mahnwesen in den Einstellungen (Spec §3.6/§3.10/§4,
 * Issue #101): Konto für Rücklastschriftgebühren, Gebühren-Weiterbelastung
 * (Opt-in), Standard-Erlöskonto für Beitragsforderungen und Mahnabstand.
 * Die Kontoart prüft der Server (SepaSettingsAccountValidator): das
 * Gebührenkonto ist ein Aufwandskonto, das Erlöskonto ein Ertragskonto - die
 * Auswahl bietet deshalb nur passende Konten an.
 */
export default {
	name: 'SettingsSepaReturns',
	components: { NcButton, NcCheckboxRadioSwitch },

	setup() {
		const { state, saveReturnSettings } = useSepaSettings()
		const card = useSettingsCardSave()
		return { settings: state, saveReturnSettings, accounts: useAccounts().state, ...card }
	},

	data() {
		return {
			daysMin: DAYS_MIN,
			daysMax: DAYS_MAX,
			draft: {
				returnFeeAccountId: this.settings.returnFeeAccountId,
				returnFeeRechargeEnabled: this.settings.returnFeeRechargeEnabled,
				contributionDefaultAccountId: this.settings.contributionDefaultAccountId,
				dunningIntervalDays: this.settings.dunningIntervalDays,
			},
		}
	},

	computed: {
		feeAccounts() { return accountOptions(this.accounts.accounts, 'expense', this.draft.returnFeeAccountId) },
		revenueAccounts() { return accountOptions(this.accounts.accounts, 'income', this.draft.contributionDefaultAccountId) },
		feeAccountMissing() { return accountMissing(this.accounts.accounts, this.draft.returnFeeAccountId) },
		revenueAccountMissing() { return accountMissing(this.accounts.accounts, this.draft.contributionDefaultAccountId) },
	},

	methods: {
		save() {
			return this.run(
				() => daysError(this.draft.dunningIntervalDays, this.t('Mahnabstand')),
				() => this.saveReturnSettings(this.draft),
			)
		},
	},
}
</script>
