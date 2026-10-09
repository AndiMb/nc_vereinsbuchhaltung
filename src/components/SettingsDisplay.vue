<template>
	<div class="vbh-card">
		<h4>{{ t('Beträge in Buchungslisten') }}</h4>
		<p class="vbh-hint">
			{{ t('Gilt für die Tabellen in Übersicht, „Alle Buchungen" und „Zuzuordnen" und für alle Nutzer; die Buchungskarten am Handy zeigen die Richtung immer. Ein Buchungssatz hat kein Vorzeichen – ob Geld hinein- oder hinausgeht, ergibt sich aus Soll und Haben. Wer die Richtung trotzdem auf einen Blick sehen will, schaltet Vorzeichen und Farbe ein.') }}
		</p>
		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Darstellung') }}
				<select v-model="amountDisplayModel">
					<option value="plain">{{ t('Neutral: nur der Betrag') }}</option>
					<option value="signed">{{ t('Mit Vorzeichen und Farbe: Einnahmen grün (+), Ausgaben rot (−)') }}</option>
				</select>
			</label>
			<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">
				{{ t('Speichern') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

/**
 * Darstellung der Beträge in den Buchungslisten (Einstellung
 * `amount_display`, siehe SettingsController::AMOUNT_DISPLAYS und
 * src/lib/flow.js).
 */
export default {
	name: 'SettingsDisplay',
	components: { NcButton },
	props: {
		amountDisplay: { type: String, default: 'plain' },
		storageSaving: { type: Boolean, required: true },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsClub.vue
		saveStorageSettings: { type: Function, required: true },
	},

	emits: ['update:amountDisplay'],

	computed: {
		amountDisplayModel: {
			get() { return this.amountDisplay },
			set(v) { this.$emit('update:amountDisplay', v) },
		},
	},
}
</script>
