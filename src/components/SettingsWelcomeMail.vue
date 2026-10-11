<template>
	<div class="vbh-card">
		<p class="vbh-hint">
			{{ t('Diese Mail können Sie beim Aufnehmen eines Mitglieds und aus der Akte an die E-Mail-Adresse des Mitglieds schicken – unabhängig davon, ob es ein Nextcloud-Konto hat. Beim Versenden lässt sich der Text für den Einzelfall anpassen.') }}
		</p>
		<template v-if="template">
			<WelcomeMailFields
				v-model:subject="subject"
				v-model:body="body"
				:placeholders="template.placeholders" />
			<p v-if="template.isDefault" class="vbh-hint">
				{{ t('Noch keine eigene Vorlage gespeichert – es gilt der Standardtext.') }}
			</p>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving || !changed" @click="store(subject, body)">
					{{ t('Speichern') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="saving || template.isDefault" @click="store('', '')">
					{{ t('Standardtext wiederherstellen') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import WelcomeMailFields from './WelcomeMailFields.vue'
import api from '../api.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'
import { errMsg } from '../lib/format.js'

/**
 * Vorlage der Willkommens-Mail (Betreff und Text mit Platzhaltern). Schreiben dürfen nur Verwalter
 * (WelcomeMailController::saveTemplate()); ohne gespeicherte Vorlage gilt der Standardtext. Fehler
 * des Servers (leeres Feld, unbekannter Platzhalter) stehen in der Karte, wie bei den übrigen.
 */
export default {
	name: 'SettingsWelcomeMail',
	components: { NcButton, WelcomeMailFields },

	setup() {
		return useSettingsCardSave()
	},

	data() {
		return { template: null, subject: '', body: '' }
	},

	computed: {
		changed() {
			return this.subject !== this.template.subject || this.body !== this.template.body
		},
	},

	async mounted() {
		try {
			const { data } = await api.welcomeMailTemplate()
			this.apply(data)
		} catch (e) {
			showError(errMsg(e, this.t('Die Vorlage konnte nicht geladen werden')))
		}
	},

	methods: {
		apply(template) {
			this.template = template
			this.subject = template.subject
			this.body = template.body
		},

		/** Beide Felder leer speichern = zurück zum Standardtext (siehe WelcomeMailService::saveTemplate()). */
		store(subject, body) {
			return this.run(() => null, async () => {
				const { data } = await api.saveWelcomeMailTemplate({ subject, body })
				this.apply(data)
			})
		},
	},
}
</script>
