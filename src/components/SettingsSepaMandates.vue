<template>
	<div class="vbh-card">
		<h4>{{ t('Mandate') }}</h4>
		<p class="vbh-hint">
			{{ t('Wie neue Mandatsreferenzen aussehen, wo die Nachweise der Mandate (unterschriebenes Formular, Zustimmung) liegen und wann die App vor dem Verfall eines Mandats warnt.') }}
		</p>
		<div class="vbh-form">
			<label>{{ t('Mandatsreferenz-Präfix') }}
				<input
					v-model="draft.mandateReferencePrefix"
					type="text"
					class="vbh-short"
					:maxlength="prefixMaxLength"
					:placeholder="defaultPrefix">
			</label>
			<label>{{ t('Ablauf-Vorwarnung (Tage vor Verfall)') }}
				<input
					v-model.number="draft.expiryWarningDays"
					type="number"
					class="vbh-short"
					:min="daysMin"
					:max="daysMax">
			</label>
		</div>
		<p class="vbh-hint">
			<!-- Das Beispiel steht ausserhalb von t(): dessen Variablen werden als HTML maskiert, Vue maskiert ein zweites Mal -->
			{{ t('Die Referenz besteht aus Präfix und laufender Nummer, z. B.') }} <code>{{ exampleReference }}</code>.
			{{ t('Leer lassen für den Standard „{default}".', { default: defaultPrefix }) }}
			{{ t('Die Ablauf-Vorwarnung (Standard 180 Tage) gilt vor dem Verfall nach 36 Monaten ohne Einzug.') }}
		</p>
		<div class="vbh-form">
			<FolderPathField
				v-model="draft.mandateDocumentFolder"
				inputId="vbh-mandate-document-folder"
				:user="storageUser"
				placeholder="SEPA-Mandate"
				:label="t('Nachweis-Ordner')" />
		</div>
		<p v-if="!storageUser" class="vbh-hint vbh-hint--warning">
			{{ t('Nachweise werden im Home des Nutzers abgelegt, der unter „Belege" für die Ablage im Nextcloud-Dateibaum gewählt ist. Dort ist noch keiner gewählt – bis dahin lassen sich keine Nachweise hochladen.') }}
		</p>
		<p v-else class="vbh-hint">
			<!-- Der Nutzername steht ausserhalb von t(): dessen Variablen werden als HTML maskiert, Vue maskiert ein zweites Mal -->
			{{ t('Ablage-Nutzer:') }} <strong>{{ storageUser }}</strong>.
			{{ t('Der Ordner wird in dessen Home bei Bedarf angelegt. Leer lassen für den Standardordner.') }}
		</p>
		<NcCheckboxRadioSwitch v-model="draft.showMissingDocumentWarning" type="switch">
			{{ t('Auf Mandate ohne Nachweis hinweisen') }}
		</NcCheckboxRadioSwitch>
		<p class="vbh-hint">
			{{ t('Dann weist die App bei jedem laufenden Mandat ohne hinterlegten Nachweis darauf hin. Vereine, die Nachweise nicht in der App ablegen, können das abschalten.') }}
		</p>
		<div class="vbh-form">
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import FolderPathField from './FolderPathField.vue'
import { useSepaSettings } from '../composables/useSepaSettings.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'
import { DAYS_MAX, DAYS_MIN, daysError, DEFAULT_REFERENCE_PREFIX, REFERENCE_PREFIX_MAX_LENGTH } from '../lib/sepaSettings.js'

/**
 * Mandate in den Einstellungen (Spec §3.2/§4, Issue #101):
 * `mandate_reference_prefix`, `mandate_document_folder`,
 * `show_missing_document_warning` und `expiry_warning_days`. Alle vier laufen
 * über den allgemeinen Einstellungssatz (`/settings`) in einem Speichern-
 * Aufruf. Der Nachweis-Ordner liegt - wie die XML-Ablage - im Home des
 * Belegablage-Nutzers (MandateDocumentService).
 */
export default {
	name: 'SettingsSepaMandates',
	components: { FolderPathField, NcButton, NcCheckboxRadioSwitch },
	props: {
		// Nutzer der Belegablage im Nextcloud-Dateibaum (SettingsApp.vue); leer = noch keiner gewählt
		storageUser: { type: String, default: '' },
	},

	setup() {
		const { state, saveMandateSettings } = useSepaSettings()
		const card = useSettingsCardSave()
		return { settings: state, saveMandateSettings, ...card }
	},

	data() {
		return {
			daysMin: DAYS_MIN,
			daysMax: DAYS_MAX,
			defaultPrefix: DEFAULT_REFERENCE_PREFIX,
			prefixMaxLength: REFERENCE_PREFIX_MAX_LENGTH,
			draft: {
				mandateReferencePrefix: this.settings.mandateReferencePrefix,
				mandateDocumentFolder: this.settings.mandateDocumentFolder,
				showMissingDocumentWarning: this.settings.showMissingDocumentWarning,
				expiryWarningDays: this.settings.expiryWarningDays,
			},
		}
	},

	computed: {
		exampleReference() {
			return `${this.draft.mandateReferencePrefix.trim() || this.defaultPrefix}-1`
		},
	},

	methods: {
		save() {
			return this.run(
				() => this.prefixError() ?? daysError(this.draft.expiryWarningDays, this.t('Ablauf-Vorwarnung')),
				async () => {
					await this.saveMandateSettings(this.draft)
					// Ein leeres Ordnerfeld heißt „Standardordner": der Server nennt ihn beim Zurückgeben
					this.draft.mandateDocumentFolder = this.settings.mandateDocumentFolder
				},
			)
		},

		prefixError() {
			return /[/\\]/.test(this.draft.mandateReferencePrefix)
				? this.t('Mandatsreferenz-Präfix: Schrägstriche sind nicht erlaubt.')
				: null
		},
	},
}
</script>
