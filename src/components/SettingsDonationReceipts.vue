<template>
	<div class="vbh-card">
		<h4>{{ t('Zuwendungsbestätigungen') }}</h4>
		<p v-if="loadFailed" class="vbh-hint vbh-hint--error" role="alert">
			{{ t('Die Einstellungen der Zuwendungsbestätigungen konnten nicht geladen werden.') }}
		</p>
		<NcLoadingIcon v-else-if="!loaded" :size="24" :name="t('Wird geladen…')" />
		<template v-else>
			<SettingsToggle v-model="form.donation_receipts_enabled" :label="t('Zuwendungsbestätigungen über Mitgliedsbeiträge ausstellen')">
				{{ t('Die Akte eines Mitglieds bietet dann je abgeschlossenem Kalenderjahr die amtliche Sammelbestätigung nach § 10b EStG an; Mitglieder laden sie unter „Mein Beitrag“ herunter. Die informelle Beitragsbestätigung entfällt, solange der Schalter an ist. Keine Steuerberatung – im Zweifel mit Finanzamt oder Steuerberatung klären.') }}
			</SettingsToggle>

			<NcCheckboxRadioSwitch v-model="form.donation_fees_deductible" class="vbh-donation-confirm">
				{{ t('Der Verein fördert keine Zwecke, bei denen Mitgliedsbeiträge nicht abziehbar sind (Sport, kulturelle Betätigungen der Freizeitgestaltung, Heimatpflege, Freizeitzwecke – § 10b Abs. 1 Satz 8 EStG).') }}
			</NcCheckboxRadioSwitch>

			<fieldset class="vbh-donation-basis">
				<legend>{{ t('Grundlage der Steuerbegünstigung') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="form.donation_basis"
					type="radio"
					value="freistellung"
					name="vbh-donation-basis">
					{{ t('Freistellungsbescheid bzw. Anlage zum Körperschaftsteuerbescheid') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.donation_basis"
					type="radio"
					value="feststellung"
					name="vbh-donation-basis">
					{{ t('Feststellung der satzungsmäßigen Voraussetzungen nach § 60a AO') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Finanzamt') }}
					<input v-model="form.donation_tax_office" maxlength="120" :placeholder="t('z. B. Musterstadt')">
				</label>
				<label>{{ t('Steuernummer') }}
					<input v-model="form.donation_tax_number" maxlength="40" :placeholder="form.club_tax_number || ''">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Datum des Bescheids') }}
					<input v-model="form.donation_notice_date" type="date">
				</label>
				<label v-if="form.donation_basis === 'freistellung'">{{ t('Letzter Veranlagungszeitraum') }}
					<input v-model="form.donation_assessment_period" maxlength="40" :placeholder="t('z. B. 2022')">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Begünstigte Zwecke') }}
					<textarea
						v-model="form.donation_purposes"
						rows="3"
						maxlength="600"
						:placeholder="t('Wortlaut wie im Bescheid, z. B. der Bildung und Erziehung (§ 52 Abs. 2 Satz 1 Nr. 7 AO)')" />
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Ort') }}
					<input v-model="form.donation_place" maxlength="80" :placeholder="t('Ort des Vereins')">
				</label>
				<label class="vbh-grow">{{ t('Unterschrift (Name, Funktion)') }}
					<input
						v-model="form.donation_signatory"
						maxlength="120"
						:disabled="form.donation_machine_signed"
						:placeholder="t('z. B. Max Muster, Kassenwart')">
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('Ohne eigene Steuernummer gilt die aus den Rechnungseinstellungen; Name und Anschrift des Vereins kommen ebenfalls von dort. Ist der Freistellungsbescheid älter als 5 Jahre (Feststellung nach § 60a AO: 3 Jahre), stellt die App keine Bestätigung aus.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.donation_machine_signed">
				{{ t('Maschinelles Verfahren ohne Unterschrift (dem Finanzamt angezeigt, R 10b.1 Abs. 4 EStR)') }}
			</NcCheckboxRadioSwitch>

			<div class="vbh-form">
				<FolderPathField
					v-model="form.donation_receipt_folder"
					inputId="vbh-donation-folder"
					:user="storageUser"
					placeholder="Vereinsbuchhaltung/Zuwendungsbestätigungen"
					:label="t('Ablageordner für Zuwendungsbestätigungen')" />
			</div>
			<p v-if="!storageUser" class="vbh-hint">
				{{ t('Ohne Nutzer der Belegablage legt die App die Doppel intern ab; sie bleiben über die Akte des Mitglieds abrufbar.') }}
			</p>

			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FolderPathField from './FolderPathField.vue'
import SettingsToggle from './SettingsToggle.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Einstellungen der Zuwendungsbestätigungen (Issue #10): Grundlage der
 * Steuerbegünstigung, Zwecke, Unterschrift, Ablage. Eigener Endpunkt, nur für
 * Verwalter speicherbar.
 */
export default {
	name: 'SettingsDonationReceipts',
	components: { FolderPathField, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, SettingsToggle },
	props: {
		storageUser: { type: String, default: '' },
	},

	data() {
		return {
			loaded: false,
			loadFailed: false,
			saving: false,
			error: '',
			form: {},
		}
	},

	async mounted() {
		try {
			const { data } = await api.donationReceiptSettings()
			this.form = { ...data }
			this.loaded = true
		} catch {
			this.loadFailed = true
		}
	},

	methods: {
		async save() {
			this.saving = true
			this.error = ''
			const payload = { ...this.form }
			delete payload.club_tax_number
			try {
				const { data } = await api.saveDonationReceiptSettings(payload)
				this.form = { ...data }
				showSuccess(this.t('Einstellungen gespeichert.'))
			} catch (e) {
				this.error = errMsg(e, this.t('Die Einstellungen konnten nicht gespeichert werden.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-donation-confirm {
	margin-top: 12px;
}

.vbh-donation-basis {
	margin: 12px 0 4px;
	border: none;
	padding: 0;
}

.vbh-donation-basis legend {
	font-weight: bold;
	margin-bottom: 4px;
}
</style>
