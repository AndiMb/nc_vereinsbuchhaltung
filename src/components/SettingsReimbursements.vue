<template>
	<div v-if="loaded" class="vbh-card">
		<h4>{{ t('Auslagenerstattung') }}</h4>
		<p class="vbh-hint">
			{{ t('Mitglieder reichen privat verauslagte Ausgaben mit Beleg ein. Verwalter geben sie frei; erst wenn genügend verschiedene Verwalter zugestimmt haben, entsteht ein offener Posten zur Überweisung. Jeder Schritt steht in der Historie des Antrags.') }}
		</p>
		<SettingsToggle v-model="enabled" :label="t('Auslagenerstattung aktivieren')">
			{{ t('Schaltet den Unterreiter „Auslagen“ unter „Mein Bereich“ für Mitglieder und den Reiter „Auslagen“ unter „Buchungen“ für die Verwaltung ein. Ausgeschaltet können keine neuen Anträge eingereicht werden; bestehende bleiben sichtbar und lassen sich weiter bearbeiten.') }}
		</SettingsToggle>
		<div class="vbh-form">
			<label>{{ t('Nötige Freigaben') }}
				<input
					v-model.number="requiredApprovals"
					type="number"
					min="1"
					step="1"
					class="vbh-short">
			</label>
		</div>
		<p class="vbh-hint">
			{{ t('So viele verschiedene Verwalter müssen einen Antrag freigeben. Den eigenen Antrag kann niemand freigeben; deshalb braucht es mindestens eine Person mehr, als Freigaben nötig sind. Derzeit gibt es {count} Verwalter.', { count: approverCount }) }}
		</p>
		<p v-if="tooFew" class="vbh-hint vbh-hint--warning" role="note">
			{{ t('Für {n} Freigaben braucht es mindestens {min} Verwalter. Vergeben Sie unter „Berechtigungen“ die Rolle Verwalter an weitere Personen oder verringern Sie die Zahl.', { n: requiredApprovals, min: requiredApprovals + 1 }) }}
		</p>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
		<div class="vbh-form">
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('Speichern') }}
			</NcButton>
		</div>
	</div>
	<NcLoadingIcon v-else :size="24" />
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import SettingsToggle from './SettingsToggle.vue'
import api from '../api.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'

/**
 * Einstellungen der Auslagenerstattung (docs/reimbursement-design.md §2):
 * Schalter und Zahl der nötigen Freigaben. Schalter und Zahl werden
 * gemeinsam gespeichert, weil der Server beim Einschalten prüft, ob es genug
 * Verwalter für N+1 gibt - die Meldung dazu steht hier in der Karte.
 */
export default {
	name: 'SettingsReimbursements',
	components: { NcButton, NcLoadingIcon, SettingsToggle },

	setup() {
		return useSettingsCardSave()
	},

	data() {
		return {
			loaded: false,
			enabled: false,
			requiredApprovals: 2,
			approverCount: 0,
		}
	},

	computed: {
		tooFew() {
			return this.enabled && this.approverCount < Number(this.requiredApprovals) + 1
		},
	},

	async mounted() {
		try {
			const { data } = await api.getSettings({ withApprovers: 1 })
			this.enabled = !!data.reimbursement_enabled
			this.requiredApprovals = Number(data.reimbursement_required_approvals) || 2
			this.approverCount = Number(data.reimbursement_approver_count) || 0
		} finally {
			this.loaded = true
		}
	},

	methods: {
		save() {
			return this.run(
				() => (Number.isInteger(this.requiredApprovals) && this.requiredApprovals >= 1 ? null : this.t('Bitte eine ganze Zahl ab 1 angeben.')),
				// Scheitert das Speichern (z. B. zu wenige Verwalter), steht der Grund des Servers in der Karte.
				async () => {
					await api.saveSettings({
						reimbursement_enabled: this.enabled ? '1' : '0',
						reimbursement_required_approvals: String(this.requiredApprovals),
					})
				},
			)
		},
	},
}
</script>
