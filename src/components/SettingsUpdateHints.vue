<template>
	<div class="vbh-card" data-testid="settings-update-hints">
		<h4>{{ t('Hinweise auf neue Versionen') }}</h4>
		<SettingsToggle
			:modelValue="enabled"
			:disabled="!loaded || saving"
			:label="t('Nach einem Update auf Neuerungen hinweisen')"
			@update:modelValue="toggle">
			{{ t('Nach einem Update zeigt die App Verwaltern, Buchhaltern und Revisoren einmal „Was ist neu?“, und Nextcloud meldet allen, die die App nutzen – auch Mitgliedern ohne Rolle –, dass die Vereinsbuchhaltung aktualisiert wurde. Ausgeschaltet entfällt beides für alle. Die Liste der Neuerungen bleibt über die Hilfe (?) erreichbar.') }}
		</SettingsToggle>
		<p class="vbh-hint">
			{{ t('Der Schalter wirkt sofort, ein Speichern ist nicht nötig.') }}
		</p>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import SettingsToggle from './SettingsToggle.vue'
import api from '../api.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'

/**
 * Schalter „Hinweise auf neue Versionen“ (UpdateHintSettings). Wirkt sofort
 * wie die Modulschalter; scheitert das Speichern, springt er zurück.
 */
export default {
	name: 'SettingsUpdateHints',
	components: { SettingsToggle },

	setup() {
		return { ...useSettingsCardSave() }
	},

	data() {
		return {
			loaded: false,
			enabled: true,
		}
	},

	async mounted() {
		try {
			const { data } = await api.getSettings()
			this.enabled = data.update_hints_enabled !== false
			this.loaded = true
		} catch {
			this.error = this.t('Die Einstellungen konnten nicht geladen werden.')
		}
	},

	methods: {
		async toggle(on) {
			this.enabled = on
			const saved = await this.run(() => null, async () => {
				await api.saveSettings({ update_hints_enabled: on ? '1' : '0' })
			})
			if (!saved) { this.enabled = !on }
		},
	},
}
</script>
