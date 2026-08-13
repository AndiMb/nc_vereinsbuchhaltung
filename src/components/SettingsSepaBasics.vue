<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('SEPA-Lastschrift') }}
		</h3>
		<p class="vbh-hint">
			{{ t('Rein optionales Zusatzmodul für Vereine, die Mitgliedsbeiträge per Lastschrift einziehen. Wer das nicht braucht, kann diesen und die beiden folgenden Abschnitte einfach ignorieren.') }}
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
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { useAccounts } from '../composables/useAccounts.js'

/**
 * Die beiden Angaben, ohne die kein Sammeleinzug zustande kommt. Die Pflege
 * der Mandate selbst steht in SettingsMembers.vue, wo sie zusammen mit den
 * Beiträgen hingehört – vorher lagen beide in getrennten Abschnitten, und
 * jedes Mitglied musste zweimal angelegt werden.
 *
 * Nur für Verwalter erreichbar (siehe SepaMandateController).
 */
export default {
	name: 'SettingsSepaBasics',
	components: { NcButton },
	props: {
		sepaCreditorId: { type: String, default: '' },
		sepaDebtorAccountId: { type: Number, default: null },
		storageSaving: { type: Boolean, default: false },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsGeneral.vue
		saveSettings: { type: Function, required: true },
	},
	setup() {
		return { ...toRefs(useAccounts().state) }
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
	},
}
</script>
