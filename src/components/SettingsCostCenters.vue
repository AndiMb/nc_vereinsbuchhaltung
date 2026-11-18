<template>
	<template v-if="loaded">
		<div class="vbh-card">
			<h4>{{ t('Kostenstellen') }}</h4>
			<p class="vbh-hint">
				{{ t('Mit Kostenstellen ordnen Sie einzelne Beträge einer Abteilung, Mannschaft oder einem Projekt zu – auch anteilig: von 1.000 für Ausrüstung gehen 600 an die Senioren und 400 an die A-Junioren, auf demselben Konto. Ein eigener Bericht zeigt Einnahmen, Ausgaben und Ergebnis je Kostenstelle.') }}
			</p>
			<SettingsToggle
				:modelValue="enabled"
				:disabled="saving"
				:label="t('Kostenstellen aktivieren')"
				@update:modelValue="toggle">
				{{ t('Schaltet das Feld „Kostenstelle“ beim Buchen und den Bericht „Kostenstellen“ ein. Die Angabe bleibt bei jeder Buchung freiwillig. Ausgeschaltet ist von der Funktion nichts zu sehen; bereits gespeicherte Zuordnungen bleiben erhalten und erscheinen wieder, sobald Sie die Funktion erneut einschalten.') }}
			</SettingsToggle>
			<p class="vbh-hint">
				{{ t('Der Schalter wirkt sofort, ein Speichern ist nicht nötig.') }}
			</p>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
		</div>
	</template>
	<NcLoadingIcon v-else :name="t('Einstellungen werden geladen')" :size="24" />
</template>

<script>
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
 *
 * Ein Modulschalter ohne weitere Felder wirkt sofort wie die Schalter des
 * Beitragsmoduls (einheitliches Speichermodell der Einstellungsseite:
 * Modulschalter sofort, Formularfelder mit dem Speichern-Knopf ihrer Karte).
 * Scheitert das Speichern, springt der Schalter zurück.
 */
export default {
	name: 'SettingsCostCenters',
	components: { NcLoadingIcon, SettingsToggle },

	emits: ['active'],

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
			this.$emit('active', this.enabled)
		} finally {
			this.loaded = true
		}
	},

	methods: {
		async toggle(on) {
			this.enabled = on
			const saved = await this.run(() => null, async () => {
				await api.saveSettings({ cost_centers_enabled: on ? '1' : '0' })
			})
			if (!saved) { this.enabled = !on }
			this.$emit('active', this.enabled)
		},
	},
}
</script>
