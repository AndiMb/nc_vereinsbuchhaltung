<template>
	<div>
		<NcSettingsSection :name="t('Auf dieser Seite')">
			<SettingsToc :groups="toc" />
		</NcSettingsSection>

		<!-- Reihenfolge und Überschriften stehen in lib/settingsSections.js;
		     ein Unit-Test prüft, dass diese Liste und die Seite übereinstimmen. -->
		<div id="settings-section_verein">
			<NcSettingsSection :name="sec.verein.name" :docUrl="helpUrl('verein')">
				<SettingsClub
					v-model:clubName="clubName"
					v-model:brandColor="brandColor"
					v-model:clubCurrency="clubCurrency"
					:clubCurrencies="clubCurrencies"
					:hasLogo="hasLogo"
					:storageSaving="storageSaving"
					:saveStorageSettings="saveSettings"
					@changed="loadSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_berechtigungen">
			<NcSettingsSection :name="sec.berechtigungen.name" :docUrl="helpUrl('berechtigungen')">
				<SettingsPermissions @help="openHandbuch" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_belege">
			<NcSettingsSection :name="sec.belege.name" :docUrl="helpUrl('belege')">
				<SettingsAttachments
					v-model:storageMode="storageMode"
					v-model:storageUser="storageUser"
					v-model:storagePath="storagePath"
					:storageSaving="storageSaving"
					:saveStorageSettings="saveSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_bankdaten">
			<NcSettingsSection :name="sec.bankdaten.name" :docUrl="helpUrl('bankdaten')">
				<SettingsStatementWatch
					v-model:statementWatchUser="statementWatchUser"
					v-model:statementWatchPath="statementWatchPath"
					:storageSaving="storageSaving"
					:saveStorageSettings="saveSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_geschaeftsjahr">
			<NcSettingsSection :name="sec.geschaeftsjahr.name" :docUrl="helpUrl('geschaeftsjahr')">
				<SettingsPeriods :storageUser="storageUser" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_darstellung">
			<NcSettingsSection :name="sec.darstellung.name" :docUrl="helpUrl('darstellung')">
				<SettingsDisplay
					v-model:amountDisplay="amountDisplay"
					:storageSaving="storageSaving"
					:saveStorageSettings="saveSettings" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_ampel">
			<NcSettingsSection :name="sec.ampel.name" :docUrl="helpUrl('ampel')">
				<SettingsBudgetStatus v-model:thresholds="budgetStatusThresholds" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_beitraege-sepa">
			<NcSettingsSection :name="sec['beitraege-sepa'].name" :docUrl="helpUrl('beitraege-sepa')">
				<SettingsSepaBasics
					v-model:sepaCreditorId="sepaCreditorId"
					v-model:sepaDebtorAccountId="sepaDebtorAccountId"
					v-model:membershipEnabled="membershipEnabled"
					v-model:selfServiceEnabled="selfServiceEnabled"
					v-model:selfServiceTransferSwitchEnabled="selfServiceTransferSwitchEnabled"
					:storageSaving="storageSaving"
					:saveSettings="saveSepaBasics" />
				<!-- Ausgeschaltetes Modul: Fristen, Mandate, Rücklastschriften,
				     Rechtstext und Willkommens-Mail ausblenden – rund 30 Felder, die
				     dann nichts bewirken. Der Schalter oben bleibt sichtbar. -->
				<SettingsSepaModule v-if="membershipEnabled" :storageUser="storageUser" />
				<p v-else-if="settingsLoaded" class="vbh-hint vbh-hint--info" data-testid="settings-module-off-beitraege">
					{{ t('Das Beitragsmodul ist ausgeschaltet. Beitragsjahr und Fristen, Mandate, Rücklastschriften, Mandats-Rechtstext und Willkommens-Mail erscheinen hier, sobald Sie den Reiter „Beiträge“ einschalten.') }}
				</p>
			</NcSettingsSection>
		</div>

		<!-- Rechtstext und Willkommens-Mail gehören fachlich zum Beitragsmodul
		     und stehen deshalb direkt darunter; eigene Abschnitte bleiben sie,
		     weil ihre Anker von außen angesprungen werden. Der Anker-Container
		     bleibt auch bei ausgeschaltetem Modul stehen: ein Sprunglink landet
		     dann beim Hinweis direkt darüber statt ins Leere. -->
		<div id="settings-section_mandats-rechtstext">
			<NcSettingsSection v-if="membershipEnabled" :name="sec['mandats-rechtstext'].name" :docUrl="helpUrl('mandats-rechtstext')">
				<SettingsMandateLegalText :clubName="clubName" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_willkommens-mail">
			<NcSettingsSection v-if="membershipEnabled" :name="sec['willkommens-mail'].name" :docUrl="helpUrl('willkommens-mail')">
				<SettingsWelcomeMail />
			</NcSettingsSection>
		</div>

		<!-- Eigener Abschnitt statt unter „Beiträge & SEPA“: Zuwendungsbestätigungen
		     gibt es auch ohne Beitragsmodul, für Spenden externer Spender (#10, Teil B). -->
		<div id="settings-section_zuwendungen">
			<NcSettingsSection :name="sec.zuwendungen.name" :docUrl="helpUrl('zuwendungen')">
				<SettingsDonationReceipts :storageUser="storageUser" @active="modules.zuwendungen = $event" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_rechnungen">
			<NcSettingsSection :name="sec.rechnungen.name" :docUrl="helpUrl('rechnungen')">
				<SettingsInvoices :storageUser="storageUser" @active="modules.rechnungen = $event" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_auslagen">
			<NcSettingsSection :name="sec.auslagen.name" :docUrl="helpUrl('auslagen')">
				<SettingsReimbursements @active="modules.auslagen = $event" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_kostenstellen">
			<NcSettingsSection :name="sec.kostenstellen.name" :docUrl="helpUrl('kostenstellen')">
				<SettingsCostCenters @active="modules.kostenstellen = $event" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_daten">
			<NcSettingsSection :name="sec.daten.name" :docUrl="helpUrl('daten')">
				<!-- Der Import legt Zeiträume an und löscht sie mit „Vorher alle
				     Daten löschen" – die Tabelle unter „Geschäftsjahr" hängt am
				     selben Singleton und muss davon erfahren. -->
				<SettingsXbucImport v-model:busy="busy" @changed="reloadPeriods" />
			</NcSettingsSection>
		</div>

		<div id="settings-section_sicherung">
			<NcSettingsSection :name="sec.sicherung.name" :docUrl="helpUrl('sicherung')">
				<!-- Leeren und Einspielen ersetzen den ganzen Bestand samt
				     Geschäftsjahren – die Tabelle unter „Geschäftsjahr" muss davon erfahren. -->
				<SettingsBackup v-model:busy="busy" @changed="reloadPeriods" />
			</NcSettingsSection>
		</div>

		<!-- Ganz am Ende und direkt unter „Bestand vollständig leeren“ (letzte
		     Karte der Sicherung): die beiden Löschwege stehen nebeneinander, damit
		     ihr unterschiedlicher Umfang auffällt, statt verwechselt zu werden. -->
		<div id="settings-section_zuruecksetzen">
			<NcSettingsSection :name="sec.zuruecksetzen.name" :docUrl="helpUrl('zuruecksetzen')">
				<p class="vbh-hint">
					{{ t('Es gibt zwei Löschwege: „Alle Daten löschen“ setzt nur die Buchhaltung zurück, Mitglieder, Mandate und Beitragsgruppen bleiben. „Bestand vollständig leeren“ (direkt darüber unter „Sicherung und Umzug“) entfernt auch Mitglieder und Einstellungen und bereitet das Einspielen einer Sicherung vor.') }}
				</p>
				<div class="vbh-card vbh-card--danger">
					<h4>{{ t('Alle Daten löschen') }}</h4>
					<p class="vbh-hint">
						{{ t('Löscht unwiderruflich alle Konten, Buchungen und Importe, alle Rechnungen samt PDF und E-Rechnung sowie den Einzug (Lastschrift-Läufe, Einzugsposten, Rücklastschriften, Mahnstufen). Mitglieder, Mandate, Beitragsgruppen und Zuweisungen bleiben bestehen. Rechnungen sind acht Jahre aufzubewahren: Sichern Sie sie vorher mit dem Beleg-ZIP (Berichte → Auswertung).') }}
					</p>
					<NcButton variant="error" :disabled="busy" @click="resetAll">
						{{ t('Alle Daten löschen') }}
					</NcButton>
				</div>
			</NcSettingsSection>
		</div>

		<!-- Die Rueckfrage vor nicht umkehrbaren Aktionen, siehe App.vue - dort
			derselbe Aufbau, da useConfirm() ein Modul-Singleton ohne eigenen
			Host ist und von SettingsPermissions/SettingsPeriods/
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import SettingsAttachments from './components/SettingsAttachments.vue'
import SettingsBackup from './components/SettingsBackup.vue'
import SettingsBudgetStatus from './components/SettingsBudgetStatus.vue'
import SettingsClub from './components/SettingsClub.vue'
import SettingsCostCenters from './components/SettingsCostCenters.vue'
import SettingsDisplay from './components/SettingsDisplay.vue'
import SettingsDonationReceipts from './components/SettingsDonationReceipts.vue'
import SettingsInvoices from './components/SettingsInvoices.vue'
import SettingsMandateLegalText from './components/SettingsMandateLegalText.vue'
import SettingsPeriods from './components/SettingsPeriods.vue'
import SettingsPermissions from './components/SettingsPermissions.vue'
import SettingsReimbursements from './components/SettingsReimbursements.vue'
import SettingsSepaBasics from './components/SettingsSepaBasics.vue'
import SettingsSepaModule from './components/SettingsSepaModule.vue'
import SettingsStatementWatch from './components/SettingsStatementWatch.vue'
import SettingsToc from './components/SettingsToc.vue'
import SettingsWelcomeMail from './components/SettingsWelcomeMail.vue'
import SettingsXbucImport from './components/SettingsXbucImport.vue'
import api from './api.js'
import { useAccounts } from './composables/useAccounts.js'
import { useConfirm } from './composables/useConfirm.js'
import { usePeriods } from './composables/usePeriods.js'
import { usePermissions } from './composables/usePermissions.js'
import { THRESHOLD_DEFAULTS } from './lib/budgetStatusSettings.js'
import { setClubCurrency } from './lib/clubCurrency.js'
import { setClubTimeZone } from './lib/clubTime.js'
import { errMsg } from './lib/format.js'
import { withPasswordConfirmation } from './lib/passwordGate.js'
import { SAVE_PARTS, settingsPayload } from './lib/settingsSave.js'
import { settingsSections, tocGroups } from './lib/settingsSections.js'

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
		SettingsBackup,
		SettingsBudgetStatus,
		SettingsClub,
		SettingsCostCenters,
		SettingsDonationReceipts,
		SettingsDisplay,
		SettingsInvoices,
		SettingsReimbursements,
		SettingsMandateLegalText,
		SettingsPeriods,
		SettingsPermissions,
		SettingsSepaBasics,
		SettingsSepaModule,
		SettingsStatementWatch,
		SettingsToc,
		SettingsWelcomeMail,
		SettingsXbucImport,
	},

	setup() {
		// Rueckfrage vor nicht umkehrbaren Aktionen (siehe App.vue). users/
		// permissions/accounts/periods kommen direkt aus den jeweiligen
		// Singletons in die Kindkomponenten (SettingsAttachments,
		// SettingsStatementWatch, SettingsPermissions, SettingsSepaBasics,
		// SettingsPeriods, SettingsXbucImport) - hier nur zum Anstossen des
		// Ladens gebraucht, siehe mounted().
		return { ...useConfirm() }
	},

	data() {
		return {
			// Überschriften und Handbuch-Kapitel der Abschnitte, siehe lib/settingsSections.js
			sections: settingsSections(),
			// Erst nach dem ersten Laden stehen die Modulschalter fest; vorher
			// soll kein „ausgeschaltet“ aufblitzen.
			settingsLoaded: false,
			// An/aus der übrigen Module, nur für das Inhaltsverzeichnis: die Karten
			// laden und speichern ihre Schalter selbst und melden sie per @active.
			modules: { zuwendungen: false, rechnungen: false, auslagen: false, kostenstellen: false },
			storageMode: 'appdata',
			storageUser: '',
			storagePath: '',
			clubName: '',
			brandColor: '',
			// Vereinswährung (#202); savedCurrency ist der Stand des Servers –
			// ändert er sich, lädt die Seite neu, damit alle Beträge passen.
			clubCurrency: 'EUR',
			savedCurrency: 'EUR',
			clubCurrencies: [],
			hasLogo: false,
			// Betragsdarstellung in den Buchungslisten: 'plain' | 'signed'
			amountDisplay: 'plain',
			// Ampel-Schwellen der visuellen Gliederungsansicht; speichert
			// SettingsBudgetStatus selbst (nicht über saveSettings()).
			budgetStatusThresholds: { ...THRESHOLD_DEFAULTS },
			storageSaving: false,
			// Überwachter Ordner für Kontoauszüge (leer = aus); der stündliche
			// Hintergrundjob liest daraus ein.
			statementWatchUser: '',
			statementWatchPath: '',
			// SEPA-Lastschrift (optionales Zusatzmodul, siehe SettingsSepaBasics.vue)
			sepaCreditorId: '',
			sepaDebtorAccountId: null,
			// Schalter fuer den Reiter „Beiträge" in der App; ohne gespeicherte
			// Einstellung liefert das Backend „an", sobald Mitglieder bestehen
			// (siehe SettingsController::currentSettings()).
			membershipEnabled: false,
			// Self-Service-Zugang (Spec §3.4): einfacher Bool-Schalter, ab
			// Verwalter änderbar (siehe SettingsSepaBasics.vue).
			selfServiceEnabled: false,
			selfServiceTransferSwitchEnabled: false,
			// Gemeinsames Ladeflag fuer xbuc-Import und "Alle Daten löschen".
			busy: false,
		}
	},

	computed: {
		/** Abschnitte nach Anker-ID, für Überschrift und Hilfe-Link im Template. */
		sec() {
			return Object.fromEntries(this.sections.map((s) => [s.id, s]))
		},

		toc() {
			const off = new Set(Object.keys(this.modules).filter((id) => !this.modules[id]))
			if (!this.membershipEnabled) { off.add('beitraege-sepa') }
			// Ohne Beitragsmodul sind Rechtstext und Willkommens-Mail ausgeblendet.
			const hidden = new Set(this.membershipEnabled ? [] : ['mandats-rechtstext', 'willkommens-mail'])
			return tocGroups(this.sections, this.settingsLoaded ? { off, hidden } : {})
		},
	},

	async mounted() {
		// Laden anstoßen, bevor auf die Einstellungen gewartet wird.
		const settings = this.loadSettings()
		usePermissions().loadPermissions()
		useAccounts().loadAccounts()
		// Ein Aufruf statt der frueheren zwei (loadYears + loadClosedYears):
		// /periods liefert Zeitraeume und Festschreibung in einem Satz.
		usePeriods().loadPeriods()
		// Die Seite wird erst nach dem Parsen gemountet - ein #settings-
		// section_<id>-Anker in der URL scrollt deshalb nicht von allein. Erst
		// nach den Einstellungen: davon hängt ab, welche Module ihre Karten
		// zeigen, und damit, wo der Abschnitt steht.
		await settings
		this.$nextTick(() => {
			if (location.hash) {
				document.getElementById(location.hash.slice(1))?.scrollIntoView({ block: 'start' })
			}
		})
	},

	methods: {
		errMsg,

		reloadPeriods() {
			return usePeriods().loadPeriods()
		},

		async loadSettings() {
			try {
				const { data } = await api.getSettings()
				// „Heute“ der Unterseiten in der Vereinszone (#263); diese Seite hat keinen Initial State.
				setClubTimeZone(data.club_time_zone)
				setClubCurrency(data.club_currency)
				this.clubCurrency = data.club_currency || 'EUR'
				this.savedCurrency = this.clubCurrency
				this.clubCurrencies = data.club_currencies || []
				this.storageMode = data.storage_mode || 'appdata'
				this.storageUser = data.storage_user || ''
				this.storagePath = data.storage_path || 'Vereinsbuchhaltung/Belege'
				this.clubName = data.club_name || ''
				this.brandColor = data.brand_color || ''
				this.amountDisplay = data.amount_display === 'signed' ? 'signed' : 'plain'
				this.budgetStatusThresholds = { ...THRESHOLD_DEFAULTS, ...data.budget_status_thresholds }
				this.hasLogo = !!data.has_logo
				this.statementWatchUser = data.statement_watch_user || ''
				this.statementWatchPath = data.statement_watch_path || ''
				this.sepaCreditorId = data.sepa_creditor_id || ''
				this.sepaDebtorAccountId = data.sepa_debtor_account_id || null
				this.membershipEnabled = !!data.membership_enabled
				this.selfServiceEnabled = !!data.self_service_enabled
				this.selfServiceTransferSwitchEnabled = !!data.self_service_transfer_switch_enabled
				this.modules = {
					zuwendungen: !!data.donation_receipts_enabled,
					rechnungen: !!data.invoice_module_active,
					auslagen: !!data.reimbursement_enabled,
					kostenstellen: !!data.cost_centers_enabled,
				}
				this.settingsLoaded = true
			} catch { /* ignorieren */ }
		},

		// Speichern einer Karte: nur deren Felder (lib/settingsSave.js). Ohne
		// Angabe – so ruft SettingsSepaBasics.vue die Funktion auf, die zum
		// SEPA-Modul gehört und unverändert bleibt – über saveSepaBasics() der
		// Teil 'sepa'. cost_center_mode bleibt bei ReportsTab/App.vue.
		async saveSettings(...args) {
			// Als Klick-Handler bekäme die Funktion das Ereignis mit; nur bekannte
			// Teile zählen, sonst wie früher der vollständige Satz.
			const parts = args.filter((p) => SAVE_PARTS.includes(p))
			this.storageSaving = true
			try {
				const { data } = await api.saveSettings(settingsPayload(parts, this))
				const saved = new Set(parts.length ? parts : SAVE_PARTS)
				// Die Auswahl „intern" räumt den Nutzer serverseitig ab – die Felder
				// sollen danach dasselbe zeigen wie der Server. Nur wenn die Ablage
				// gespeichert wurde: sonst überschriebe die Antwort eine noch nicht
				// gespeicherte Eingabe in der Karte „Belege".
				if (saved.has('storage')) {
					this.storageUser = data.storage_user || ''
				}
				if (saved.has('sepa')) {
					// Ohne gespeicherten Schalter gilt das Modul als an, sobald es
					// Mitglieder gibt – maßgeblich ist, was der Server meldet.
					this.membershipEnabled = !!data.membership_enabled
				}
				// Neue Vereinswährung: SEPA-Abschnitte, Beschriftungen und Beträge hängen daran.
				if (data.club_currency && data.club_currency !== this.savedCurrency) {
					window.location.reload()
					return
				}
				if (data.storage_backfilled > 0) {
					showSuccess(this.t('Einstellungen gespeichert. {n} vorhandene Belege wurden dem Wächter-Ordner zugeordnet.', { n: data.storage_backfilled }))
				} else {
					showSuccess(this.t('Einstellungen gespeichert.'))
				}
			} catch (e) {
				const msg = (e?.response?.data?.message) || this.t('Speichern fehlgeschlagen (HTTP {status})', { status: e?.response?.status ?? this.t('Netzwerkfehler') })
				showError(msg)
				// Nichts gespeichert: die Währungsauswahl zeigt wieder den Stand des Servers.
				this.clubCurrency = this.savedCurrency
				// Die Modulschalter wirken sofort; scheitert das, sollen sie (und
				// die davon abhängigen Karten) nicht einen Stand zeigen, den der
				// Server nicht hat.
				if (!parts.length || parts.includes('sepa')) {
					await this.reloadModuleFlags()
				}
			} finally { this.storageSaving = false }
		},

		saveSepaBasics() {
			return this.saveSettings('sepa')
		},

		/** Nur die Schalter des Beitragsmoduls neu laden, ohne andere Eingaben zu überschreiben. */
		async reloadModuleFlags() {
			try {
				const { data } = await api.getSettings()
				this.membershipEnabled = !!data.membership_enabled
				this.selfServiceEnabled = !!data.self_service_enabled
				this.selfServiceTransferSwitchEnabled = !!data.self_service_transfer_switch_enabled
			} catch { /* bleibt beim lokalen Stand */ }
		},

		/** Hilfe-Symbol neben der Überschrift: das passende Handbuch-Kapitel. */
		helpUrl(id) {
			return api.handbuchUrl(this.sec[id].help)
		},

		openHandbuch() {
			window.open(api.handbuchUrl('setup'), '_blank')
		},

		async resetAll() {
			if (!await this.askConfirm(this.t('Alle Daten löschen'), this.t('Wirklich ALLE Konten, Buchungen und Importe, alle Rechnungen samt PDF und E-Rechnung sowie den Einzug (Läufe, Rücklastschriften, Mahnstufen) löschen? Rechnungen sind acht Jahre aufzubewahren – haben Sie sie mit dem Beleg-ZIP gesichert? Mitglieder, Mandate, Beitragsgruppen und Zuweisungen bleiben bestehen.'))) { return }
			if (!await withPasswordConfirmation()) { return }
			this.busy = true
			try {
				await api.reset()
				showSuccess(this.t('Alle Daten gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Zurücksetzen fehlgeschlagen'))) } finally { this.busy = false }
		},
	},
}
</script>
