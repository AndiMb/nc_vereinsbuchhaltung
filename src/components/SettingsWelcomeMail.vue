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
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving || !changed" @click="save">
					{{ t('Speichern') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="saving || template.isDefault" @click="reset">
					{{ t('Standardtext wiederherstellen') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import WelcomeMailFields from './WelcomeMailFields.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Vorlage der Willkommens-Mail (Betreff und Text mit Platzhaltern). Schreiben dürfen nur Verwalter
 * (WelcomeMailController::saveTemplate()); ohne gespeicherte Vorlage gilt der Standardtext.
 */
export default {
	name: 'SettingsWelcomeMail',
	components: { NcButton, WelcomeMailFields },

	data() {
		return { template: null, subject: '', body: '', saving: false }
	},

	computed: {
		changed() {
			return this.template !== null && (this.subject !== this.template.subject || this.body !== this.template.body)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			try {
				const { data } = await api.welcomeMailTemplate()
				this.apply(data)
			} catch (e) {
				showError(errMsg(e, this.t('Die Vorlage konnte nicht geladen werden')))
			}
		},

		apply(template) {
			this.template = template
			this.subject = template.subject
			this.body = template.body
		},

		async save() {
			this.saving = true
			try {
				const { data } = await api.saveWelcomeMailTemplate({ subject: this.subject, body: this.body })
				this.apply(data)
				showSuccess(this.t('Vorlage gespeichert.'))
			} catch (e) {
				showError(errMsg(e, this.t('Die Vorlage konnte nicht gespeichert werden')))
			} finally {
				this.saving = false
			}
		},

		async reset() {
			this.saving = true
			try {
				const { data } = await api.saveWelcomeMailTemplate({ subject: '', body: '' })
				this.apply(data)
				showSuccess(this.t('Standardtext wiederhergestellt.'))
			} catch (e) {
				showError(errMsg(e, this.t('Die Vorlage konnte nicht gespeichert werden')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
