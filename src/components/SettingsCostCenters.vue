<template>
	<template v-if="loaded">
		<div class="vbh-card">
			<h4>{{ t('Kostenstellen') }}</h4>
			<p class="vbh-hint">
				{{ t('Mit Kostenstellen ordnen Sie einzelne Beträge einer Abteilung, Mannschaft oder einem Projekt zu – auch anteilig: von 1.000 für Ausrüstung gehen 600 an die Senioren und 400 an die A-Junioren, auf demselben Konto. Ein eigener Bericht zeigt Einnahmen, Ausgaben und Ergebnis je Kostenstelle.') }}
			</p>
			<SettingsToggle v-model="enabled" :label="t('Kostenstellen aktivieren')">
				{{ t('Schaltet das Feld „Kostenstelle“ beim Buchen und den Bericht „Kostenstellen“ ein. Die Angabe bleibt bei jeder Buchung freiwillig. Ausgeschaltet ist von der Funktion nichts zu sehen; bereits gespeicherte Zuordnungen bleiben erhalten und erscheinen wieder, sobald Sie die Funktion erneut einschalten.') }}
			</SettingsToggle>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>
	</template>
	<NcLoadingIcon v-else :name="t('Einstellungen werden geladen')" :size="24" />
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import SettingsToggle from './SettingsToggle.vue'
import api from '../api.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'

/**
 * Schalter für Kostenstellen an der Buchungszeile (Issue #7). Standardmäßig
 * aus; die Stammdaten selbst pflegt man in der App unter Berichte →
 * Kostenstellen (BookingCostCenterPanel.vue), dort wo sie gebraucht werden.
 *
 * Schreibt nur das eine Feld – SettingsController::update() speichert
 * teilweise, die übrigen Einstellungen bleiben unberührt.
 */
export default {
	name: 'SettingsCostCenters',
	components: { NcButton, NcLoadingIcon, SettingsToggle },

	setup() {
		return { ...useSettingsCardSave() }
	},

	data() {
		return {
			loaded: false,
			enabled: false,
		}
	},

	async mounted() {
		try {
			const { data } = await api.getSettings()
			this.enabled = !!data.cost_centers_enabled
		} finally {
			this.loaded = true
		}
	},

	methods: {
		save() {
			return this.run(() => null, async () => {
				await api.saveSettings({ cost_centers_enabled: this.enabled ? '1' : '0' })
			})
		},
	},
}
</script>
