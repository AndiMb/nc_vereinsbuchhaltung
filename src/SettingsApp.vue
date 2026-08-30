<template>
	<div>
		<div id="settings-section_verein">
			<NcSettingsSection :name="t('Verein')">
				<SettingsClub
					v-model:clubName="clubName"
					v-model:brandColor="brandColor"
					:hasLogo="hasLogo"
					:storageSaving="storageSaving"
					:saveStorageSettings="saveSettings"
					@changed="loadSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_belege">
			<NcSettingsSection :name="t('Belege')">
				<SettingsAttachments
					v-model:storageUser="storageUser"
					v-model:storagePath="storagePath"
					:users="users"
					:storageSaving="storageSaving"
					:saveStorageSettings="saveSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_bankdaten">
			<NcSettingsSection :name="t('Bankdaten')">
				<SettingsStatementWatch
					v-model:statementWatchUser="statementWatchUser"
					v-model:statementWatchPath="statementWatchPath"
					:users="users"
					:storageSaving="storageSaving"
					:saveStorageSettings="saveSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_beitraege-sepa">
			<NcSettingsSection :name="t('Beiträge & SEPA')">
				<SettingsSepaBasics
					v-model:sepaCreditorId="sepaCreditorId"
					v-model:sepaDebtorAccountId="sepaDebtorAccountId"
					v-model:defaultFeeAmount="defaultFeeAmount"
					v-model:defaultFeeFrequency="defaultFeeFrequency"
					v-model:membershipEnabled="membershipEnabled"
					:membershipActive="membershipActive"
					:storageSaving="storageSaving"
					:saveSettings="saveSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_berechtigungen">
			<NcSettingsSection :name="t('Berechtigungen')">
				<SettingsPermissions @help="openHandbuch" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_jahresabschluss">
			<NcSettingsSection :name="t('Jahresabschluss')">
				<SettingsYearClose />
			</NcSettingsSection>
		</div>

		<div id="settings-section_daten">
			<NcSettingsSection :name="t('Daten')">
				<SettingsXbucImport v-model:busy="busy" />
				<div class="vbh-card vbh-card--danger">
					<h4>{{ t('Alle Daten löschen') }}</h4>
					<p class="vbh-hint">
						{{ t('Löscht alle Konten, Buchungen und Importe dieses Kontos unwiderruflich.') }}
					</p>
					<NcButton variant="error" :disabled="busy" @click="resetAll">
						{{ t('Alle Daten löschen') }}
					</NcButton>
				</div>
			</NcSettingsSection>
		</div>

		<!-- Die Rueckfrage vor nicht umkehrbaren Aktionen, siehe App.vue - dort
			derselbe Aufbau, da useConfirm() ein Modul-Singleton ohne eigenen
			Host ist und von SettingsPermissions/SettingsYearClose/
			SettingsXbucImport aus dieser Seite heraus aufgerufen wird. -->
		<NcDialog
			v-if="confirm.open"
			:name="confirm.title"
			:message="confirm.message"
			:noClose="true"
			:buttons="confirmButtons"
			@update:open="closeConfirm(false)" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcDialog, NcSettingsSection } from '@nextcloud/vue'
import { toRef } from 'vue'
import SettingsAttachments from './components/SettingsAttachments.vue'
import SettingsClub from './components/SettingsClub.vue'
import SettingsPermissions from './components/SettingsPermissions.vue'
import SettingsSepaBasics from './components/SettingsSepaBasics.vue'
import SettingsStatementWatch from './components/SettingsStatementWatch.vue'
import SettingsXbucImport from './components/SettingsXbucImport.vue'
import SettingsYearClose from './components/SettingsYearClose.vue'
import api from './api.js'
import { useAccounts } from './composables/useAccounts.js'
import { useConfirm } from './composables/useConfirm.js'
import { usePermissions } from './composables/usePermissions.js'
import { useYears } from './composables/useYears.js'
import { errMsg } from './lib/format.js'

/**
 * Wurzelkomponente der Nextcloud-Einstellungsseite (Settings\AdminSettings /
 * Settings\PersonalSettings, eigener Webpack-Entry siehe settings.js). Beide
 * Formulare (Verwaltung und Persönlich) rendern dieselbe Seite - welche der
 * beiden ein Nutzer zu sehen bekommt, entscheidet bereits das Backend
 * (PersonalSettings::getSection()), hier ist also jeder Aufruf ein
 * App-Verwalter.
 *
 * Übernimmt die Rolle, die vorher das NcModal in App.vue hatte: hält den
 * vollständigen Einstellungssatz (bis auf den Kostenstellen-Modus, der
 * weiterhin bei ReportsTab/App.vue liegt) und reicht ihn per Prop/.sync an
 * dieselben sieben Settings*.vue-Komponenten durch wie zuvor.
 */
export default {
	name: 'SettingsApp',
	components: {
		NcButton,
		NcDialog,
		NcSettingsSection,
		SettingsAttachments,
		SettingsClub,
		SettingsPermissions,
		SettingsSepaBasics,
		SettingsStatementWatch,
		SettingsXbucImport,
		SettingsYearClose,
	},

	setup() {
		// Rueckfrage vor nicht umkehrbaren Aktionen (siehe App.vue).
		// permissions/accounts/years kommen direkt aus den jeweiligen
		// Singletons in die Kindkomponenten (SettingsPermissions,
		// SettingsSepaBasics, SettingsYearClose) - hier nur zum Anstossen des
		// Ladens gebraucht, siehe mounted(). Die Nutzerliste dagegen erwarten
		// SettingsAttachments/SettingsStatementWatch als Prop (vor v0.25.0 kam
		// sie in App.vue aus toRefs(permissions.state)).
		return { ...useConfirm(), users: toRef(usePermissions().state, 'users') }
	},

	data() {
		return {
			storageUser: '',
			storagePath: '',
			clubName: '',
			brandColor: '',
			hasLogo: false,
			storageSaving: false,
			// Überwachter Ordner für Kontoauszüge (leer = aus); der stündliche
			// Hintergrundjob liest daraus ein.
			statementWatchUser: '',
			statementWatchPath: '',
			// SEPA-Lastschrift (optionales Zusatzmodul, siehe SettingsSepaBasics.vue)
			sepaCreditorId: '',
			sepaDebtorAccountId: null,
			// Vorbelegung fuer "Mitglied aufnehmen" (MemberDialog.vue in der App)
			defaultFeeAmount: '',
			defaultFeeFrequency: 'yearly',
			// Schalter fuer den Reiter „Beiträge" in der App; membershipActive
			// kommt vom Backend (Schalter ODER bereits vorhandene Mandate/
			// Beitraege, siehe SettingsController::index()).
			membershipEnabled: false,
			membershipActive: false,
			// Gemeinsames Ladeflag fuer xbuc-Import und "Alle Daten löschen".
			busy: false,
		}
	},

	mounted() {
		this.loadSettings()
		usePermissions().loadPermissions()
		useAccounts().loadAccounts()
		const years = useYears()
		years.loadYears()
		years.loadClosedYears()
		// Die Seite wird erst nach dem Parsen gemountet - ein #settings-
		// section_<id>-Anker in der URL scrollt deshalb nicht von allein.
		this.$nextTick(() => {
			if (location.hash) {
				document.getElementById(location.hash.slice(1))?.scrollIntoView({ block: 'start' })
			}
		})
	},

	methods: {
		errMsg,

		async loadSettings() {
			try {
				const { data } = await api.getSettings()
				this.storageUser = data.storage_user || ''
				this.storagePath = data.storage_path || 'Vereinsbuchhaltung/Belege'
				this.clubName = data.club_name || ''
				this.brandColor = data.brand_color || ''
				this.hasLogo = !!data.has_logo
				this.statementWatchUser = data.statement_watch_user || ''
				this.statementWatchPath = data.statement_watch_path || ''
				this.sepaCreditorId = data.sepa_creditor_id || ''
				this.sepaDebtorAccountId = data.sepa_debtor_account_id || null
				this.defaultFeeAmount = data.default_fee_amount ?? ''
				this.defaultFeeFrequency = data.default_fee_frequency || 'yearly'
				this.membershipEnabled = !!data.membership_enabled
				this.membershipActive = !!data.membership_active
			} catch { /* ignorieren */ }
		},

		// Gemeinsame Speichern-Funktion aller Abschnitte ausser "Daten": schreibt
		// den vollstaendigen Satz dieser Seite (elf Felder, alles ausser
		// cost_center_mode - das bleibt bei ReportsTab/App.vue, siehe
		// SettingsController::update()).
		async saveSettings() {
			this.storageSaving = true
			try {
				const { data } = await api.saveSettings({
					storage_user: this.storageUser,
					storage_path: this.storagePath || 'Vereinsbuchhaltung/Belege',
					club_name: this.clubName,
					brand_color: this.brandColor,
					statement_watch_user: this.statementWatchUser,
					statement_watch_path: this.statementWatchPath,
					sepa_creditor_id: this.sepaCreditorId,
					sepa_debtor_account_id: this.sepaDebtorAccountId || '',
					default_fee_amount: this.defaultFeeAmount || '',
					default_fee_frequency: this.defaultFeeFrequency,
					membership_enabled: this.membershipEnabled ? '1' : '0',
				})
				this.membershipActive = !!data.membership_active
				showSuccess(this.t('Einstellungen gespeichert.'))
			} catch (e) {
				const msg = (e?.response?.data?.message) || this.t('Speichern fehlgeschlagen (HTTP {status})', { status: e?.response?.status ?? this.t('Netzwerkfehler') })
				showError(msg)
			} finally { this.storageSaving = false }
		},

		openHandbuch() {
			window.open(api.handbuchUrl('setup'), '_blank')
		},

		async resetAll() {
			if (!await this.askConfirm(this.t('Alle Daten löschen'), this.t('Wirklich ALLE Konten, Buchungen und Importe löschen?'))) { return }
			this.busy = true
			try {
				await api.reset()
				showSuccess(this.t('Alle Daten gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Zurücksetzen fehlgeschlagen'))) } finally { this.busy = false }
		},
	},
}
</script>
