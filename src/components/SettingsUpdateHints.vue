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
 * Schalter „Hinweise auf neue Versionen“ (UpdateHintSettings im Backend),
 * standardmäßig an. Aus heißt: kein „Was ist neu“-Splash nach einem Update
 * (App.vue, whatsNewBootAction()) und keine Nextcloud-Benachrichtigung
 * „Vereinsbuchhaltung wurde aktualisiert“ (UpdateNotificationFilter) – für
 * alle, auch Mitglieder ohne Rolle.
 *
 * Wirkt sofort wie die Modulschalter; scheitert das Speichern, springt der
 * Schalter zurück und der Grund steht in der Karte. Schreibt nur das eine
 * Feld – SettingsController::update() speichert teilweise.
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
			// Bis zur Antwort der Stand ab Werk; der Schalter ist so lange gesperrt.
			enabled: true,
		}
	},

	async mounted() {
		try {
			const { data } = await api.getSettings()
			this.enabled = data.update_hints_enabled !== false
			this.loaded = true
		} catch {
			// Bleibt gesperrt: ohne geladenen Stand nichts überschreiben.
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
