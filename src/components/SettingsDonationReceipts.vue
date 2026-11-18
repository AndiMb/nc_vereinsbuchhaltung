<template>
	<div class="vbh-card">
		<h4 class="vbh-donation-title">
			{{ t('Zuwendungsbestätigungen') }}
		</h4>
		<p v-if="loadFailed" class="vbh-hint vbh-hint--error" role="alert">
			{{ t('Die Einstellungen der Zuwendungsbestätigungen konnten nicht geladen werden.') }}
		</p>
		<NcLoadingIcon v-else-if="!loaded" :size="24" :name="t('Wird geladen…')" />
		<template v-else>
			<SettingsToggle
				:modelValue="form.donation_receipts_enabled"
				:disabled="saving"
				:label="t('Zuwendungsbestätigungen ausstellen')"
				@update:modelValue="toggle">
				{{ t('Mitgliedsbeiträge (in der Akte eines Mitglieds) und Geldspenden (unter Buchungen → Spenden) lassen sich dann amtlich nach § 10b EStG bestätigen; Mitglieder laden ihre Bestätigungen unter „Mein Beitrag“ herunter. Keine Steuerberatung – im Zweifel mit Finanzamt oder Steuerberatung klären.') }}
			</SettingsToggle>
			<p v-if="!form.donation_receipts_enabled" class="vbh-hint vbh-hint--info" data-testid="settings-module-off-zuwendungen">
				{{ t('Der Schalter wirkt sofort. Grundlage, Zwecke, Spendenkonten, Unterschrift und Ablage erscheinen, sobald er eingeschaltet ist.') }}
			</p>
			<p v-if="toggleError" class="vbh-hint vbh-hint--error" role="alert">
				{{ toggleError }}
			</p>
		</template>
		<!-- Ausgeschaltet bewirken die Angaben nichts (auch der Reiter „Spenden“
		     ist dann aus); gespeichert bleiben sie trotzdem. -->
		<template v-if="loaded && form.donation_receipts_enabled">
			<NcCheckboxRadioSwitch v-model="form.donation_fees_deductible" class="vbh-donation-confirm">
				{{ t('Der Verein fördert keine Zwecke, bei denen Mitgliedsbeiträge nicht abziehbar sind (Sport, kulturelle Betätigungen der Freizeitgestaltung, Heimatpflege, Freizeitzwecke – § 10b Abs. 1 Satz 8 EStG).') }}
			</NcCheckboxRadioSwitch>
			<p class="vbh-hint">
				{{ t('Mit diesem Häkchen enthalten die Sammelbestätigungen auch die Beiträge und ersetzen die informelle Beitragsbestätigung. Ohne es bestätigt die App nur Spenden, mit dem Satz für Vereine, deren Mitgliedsbeiträge nicht abziehbar sind.') }}
			</p>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Spendenkonten') }}
					<NcSelect
						v-model="selectedAccounts"
						:options="accountOptions"
						:multiple="true"
						:keepOpen="true"
						label="label"
						:placeholder="t('– Konten wählen –')" />
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('Buchungen mit Haben auf diesen Konten erscheinen unter Buchungen → Spenden; dort wird der Spender zugeordnet.') }}
			</p>

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
				{{ t('Ohne eigene Steuernummer gilt die aus der Karte „Angaben des Vereins“ oben unter „Verein“; Name und Anschrift des Vereins kommen ebenfalls von dort. Ist der Freistellungsbescheid älter als 5 Jahre (Feststellung nach § 60a AO: 3 Jahre), stellt die App keine Bestätigung aus.') }}
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
			<p class="vbh-hint">
				{{ t('Der Schalter oben wirkt sofort; die Angaben darunter übernimmt „Speichern“.') }}
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import FolderPathField from './FolderPathField.vue'
import SettingsToggle from './SettingsToggle.vue'
import api from '../api.js'
import { accountLabel } from '../lib/accountLabel.js'
import { errMsg } from '../lib/format.js'

/**
 * Einstellungen der Zuwendungsbestätigungen (Issue #10): Grundlage der
 * Steuerbegünstigung, Zwecke, Unterschrift, Ablage und die Spendenkonten
 * (Teil B). Eigener Endpunkt, nur für Verwalter speicherbar.
 *
 * Der Schalter wirkt sofort und schreibt nur sich selbst (der Endpunkt
 * speichert teilweise); die übrigen Angaben übernimmt „Speichern“, und sie
 * erscheinen erst, wenn der Schalter an ist.
 */
export default {
	name: 'SettingsDonationReceipts',
	components: { FolderPathField, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, SettingsToggle },
	props: {
		storageUser: { type: String, default: '' },
	},

	emits: ['active'],

	data() {
		return {
			loaded: false,
			loadFailed: false,
			saving: false,
			error: '',
			// Fehler des Schalters getrennt von dem des Formulars: die Meldung
			// soll bei dem stehen, was sie ausgelöst hat.
			toggleError: '',
			form: {},
			accounts: [],
			selectedAccounts: [],
		}
	},

	computed: {
		/** Ertragskonten, dazu bereits gewählte (auch wenn inzwischen anders eingeordnet). */
		accountOptions() {
			const chosen = new Set(this.form.donation_accounts || [])
			return this.accounts
				.filter((a) => !a.isBank && ((a.type === 'income' && a.active) || chosen.has(a.id)))
				.map((a) => ({ id: a.id, label: accountLabel(a) }))
		},
	},

	async mounted() {
		try {
			const [{ data }, accounts] = await Promise.all([api.donationReceiptSettings(), api.listAccounts()])
			this.accounts = accounts.data
			this.applyForm(data)
			this.loaded = true
			this.$emit('active', !!data.donation_receipts_enabled)
		} catch {
			this.loadFailed = true
		}
	},

	methods: {
		applyForm(data) {
			this.form = { ...data }
			const ids = new Set(data.donation_accounts || [])
			this.selectedAccounts = this.accountOptions.filter((o) => ids.has(o.id))
		},

		async toggle(on) {
			this.form.donation_receipts_enabled = on
			this.saving = true
			this.toggleError = ''
			try {
				const { data } = await api.saveDonationReceiptSettings({ donation_receipts_enabled: on })
				// Nur den Schalter übernehmen: noch nicht gespeicherte Angaben im
				// Formular sollen stehen bleiben.
				this.form.donation_receipts_enabled = !!data.donation_receipts_enabled
				showSuccess(this.t('Einstellungen gespeichert.'))
			} catch (e) {
				this.form.donation_receipts_enabled = !on
				this.toggleError = errMsg(e, this.t('Die Einstellungen konnten nicht gespeichert werden.'))
			} finally {
				this.saving = false
				this.$emit('active', !!this.form.donation_receipts_enabled)
			}
		},

		async save() {
			this.saving = true
			this.error = ''
			const payload = { ...this.form, donation_accounts: this.selectedAccounts.map((o) => o.id) }
			delete payload.club_tax_number
			try {
				const { data } = await api.saveDonationReceiptSettings(payload)
				this.applyForm(data)
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
/* Ein Wort von 23 Zeichen: auf 375 px breiten Schirmen mit breiterer Schrift passte es nicht in die Karte. */
.vbh-donation-title {
	hyphens: auto;
	overflow-wrap: break-word;
}

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
