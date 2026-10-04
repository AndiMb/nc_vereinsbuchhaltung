<template>
	<div class="vbh-card">
		<h4>{{ t('Ablage der Einzugsdatei (XML)') }}</h4>
		<p class="vbh-hint">
			{{ t('Beim Freigeben eines Einzugs erzeugt die App die SEPA-Datei für die Bank. Auf Wunsch legt sie zusätzlich eine Kopie in einem Nextcloud-Ordner ab, z. B. für das Vereinsarchiv. Die Datei enthält alle IBAN im Klartext – schalten Sie die Ablage nur ein, wenn der Ordner entsprechend geschützt ist.') }}
		</p>
		<NcCheckboxRadioSwitch v-model="draft.xmlFolderEnabled" type="switch">
			{{ t('Einzugsdatei zusätzlich im Nextcloud-Ordner ablegen') }}
		</NcCheckboxRadioSwitch>
		<div class="vbh-form">
			<FolderPathField
				v-model="draft.xmlFolderPath"
				inputId="vbh-xml-folder-path"
				:user="storageUser"
				placeholder="SEPA-Einreichungen"
				:label="t('Ablageordner')" />
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<p v-if="!storageUser" class="vbh-hint vbh-hint--warning">
			{{ t('Der Ordner liegt im Home des Nutzers, der unter „Belege" für die Ablage im Nextcloud-Dateibaum gewählt ist. Dort ist noch keiner gewählt – ohne ihn lässt sich die Ablage nicht einschalten.') }}
		</p>
		<p v-else class="vbh-hint">
			<!-- Der Nutzername steht ausserhalb von t(): dessen Variablen werden als HTML maskiert, Vue maskiert ein zweites Mal -->
			{{ t('Ablage-Nutzer:') }} <strong>{{ storageUser }}</strong>.
			{{ t('Der Ordner wird in dessen Home bei Bedarf angelegt. Leer lassen für den Standardordner.') }}
		</p>
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

/**
 * XML-Ablage der Einzugsdatei in den Einstellungen (Spec §3.5/§4,
 * `xml_folder_enabled` + `xml_folder_path`, Issue #101). Default aus: die
 * Datei enthält alle IBAN im Klartext. Der Ordner liegt im Home des
 * Belegablage-Nutzers (DebitBatchXmlStorageService) - der Server verlangt ihn
 * deshalb beim Einschalten und meldet es verständlich, wenn er fehlt.
 */
export default {
	name: 'SettingsSepaXmlStorage',
	components: { FolderPathField, NcButton, NcCheckboxRadioSwitch },
	props: {
		// Nutzer der Belegablage im Nextcloud-Dateibaum (SettingsApp.vue); leer = noch keiner gewählt
		storageUser: { type: String, default: '' },
	},

	setup() {
		const { state, saveXmlStorage } = useSepaSettings()
		const card = useSettingsCardSave()
		return { settings: state, saveXmlStorage, ...card }
	},

	data() {
		return {
			draft: {
				xmlFolderEnabled: this.settings.xmlFolderEnabled,
				xmlFolderPath: this.settings.xmlFolderPath,
			},
		}
	},

	methods: {
		save() {
			return this.run(
				() => null,
				async () => {
					await this.saveXmlStorage(this.draft)
					// Ein leeres Feld heißt „Standardordner": der Server nennt ihn beim Zurückgeben
					this.draft.xmlFolderPath = this.settings.xmlFolderPath
				},
			)
		},
	},
}
</script>
