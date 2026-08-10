<template>
	<div class="vbh">
		<header class="vbh-header">
			<div class="vbh-titlebar">
				<h2>Vereinsbuchhaltung</h2>
				<div v-if="primaryBank && !isMobile" class="vbh-bankchip" :class="{ warn: Math.abs(primaryBank.open) > 0.005 }">
					<span class="vbh-bankchip-label">{{ primaryBank.name }}</span>
					<span class="vbh-bankchip-value">{{ formatMoney(primaryBank.balance) }}</span>
					<span v-if="Math.abs(primaryBank.open) > 0.005" class="vbh-bankchip-hint">{{ t('{amount} offen', { amount: formatMoney(primaryBank.open) }) }}</span>
				</div>
				<NcLoadingIcon v-if="busy" :size="24" :name="t('Wird geladen…')" />
			</div>
			<div v-if="canRead" class="vbh-navbar" :class="{ 'vbh-navbar--mobile': isMobile }">
				<nav v-if="!isMobile" class="vbh-tabs">
					<button v-for="tab in visibleTabs"
						:key="tab.id"
						:class="{ active: activeTab === tab.id }"
						@click="activeTab = tab.id">
						<NcIconSvgWrapper :path="tab.icon" :size="18" inline />
						{{ tab.label }}
						<span v-if="tab.id === 'bookings' && unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
					</button>
				</nav>
				<div class="vbh-navright">
					<NcButton v-if="canWrite && !isMobile"
						variant="primary"
						class="vbh-newbooking-btn"
						:title="t('Neue Buchung anlegen (von überall)')"
						@click="openNewBooking">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" :size="20" />
						</template>
						<span class="vbh-newbooking-label">{{ t('Buchung') }}</span>
					</NcButton>
					<label class="vbh-yearsel" :title="yearClosed ? t('Geschäftsjahr abgeschlossen (festgeschrieben)') : t('Geschäftsjahr (Kalenderjahr)')">
						<span>{{ t('Jahr') }}</span>
						<select v-model="selectedYear">
							<option :value="null">{{ t('Alle Jahre') }}</option>
							<option v-for="y in years" :key="y" :value="y">{{ y }}{{ closedYearSet[y] ? ' 🔒' : '' }}</option>
						</select>
					</label>
					<NcButton variant="tertiary"
						:aria-label="t('Hilfe')"
						:title="t('Hilfe')"
						@click="openHelp()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiHelpCircleOutline" :size="20" />
						</template>
					</NcButton>
					<NcButton v-if="canWrite"
						variant="tertiary"
						:aria-label="t('Einstellungen & Import')"
						:title="t('Einstellungen & Import')"
						@click="openSettings">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCog" :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</header>

		<div v-if="me && !canRead" class="vbh-noaccess">
			<h3>{{ t('Kein Zugriff') }}</h3>
			<p>{{ t('Du hast keine Berechtigung für die Vereinsbuchhaltung. Bitte wende dich an eine Verwalterin oder einen Verwalter.') }}</p>
		</div>

		<div v-if="demoActive" class="vbh-demobanner">
			<span><strong>{{ t('Beispieldaten aktiv.') }}</strong> {{ t('Das ist ein Beispielverein zum Ausprobieren, keine echten Daten.') }}</span>
			<NcButton variant="secondary" :disabled="busy" @click="resetAll">
				{{ t('Zurücksetzen & mit echten Daten starten') }}
			</NcButton>
		</div>

		<div v-if="showRevisorIntro" class="vbh-revisorintro">
			<h3>{{ t('Willkommen als Kassenprüfer/in') }}</h3>
			<ul>
				<li>{{ t('Buchungen einsehen (Tab „Buchungen")') }}</li>
				<li>{{ t('Kontoauszug und Saldenliste prüfen (Tabs „Konten" und „Berichte")') }}</li>
				<li>{{ t('Kassenbericht drucken (Tab „Berichte" → Auswertung)') }}</li>
			</ul>
			<p>{{ t('Ändern ist mit dieser Rolle nicht möglich.') }}</p>
			<div class="vbh-modal-actions">
				<a :href="pruefleitfadenUrl"
					target="_blank"
					rel="noopener"
					class="vbh-export-btn"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> {{ t('Prüfleitfaden') }}</a>
				<NcButton variant="tertiary" @click="dismissRevisorIntro">
					{{ t('Verstanden') }}
				</NcButton>
			</div>
		</div>

		<main v-show="canRead" class="vbh-main">
			<!-- ============ ÜBERSICHT (DASHBOARD) ============ -->
			<section v-show="activeTab === 'dashboard'" class="vbh-section scroll" :class="{ 'vbh-fadein': sectionFade }">
				<DashboardTab :is-active="activeTab === 'dashboard'"
					:is-mobile="isMobile"
					:busy="busy"
					:club-name="clubName"
					:attachment-count-map="attachmentCountMap"
					:recent-journal="recentJournal"
					:click-paperclip="clickPaperclip"
					:open-booking-card="openBookingCard"
					@navigate="onSetupNavigate"
					@open-wizard="showSetupWizard = true"
					@help="openHelp"
					@go-unassigned="goToUnassigned"
					@go-open-items="goToOpenItems"
					@show-all-bookings="activeTab = 'bookings'" />
			</section>

			<!-- ============ BUCHUNGEN (JOURNAL + TRANSAKTIONEN) ============ -->
			<section v-show="activeTab === 'bookings'" class="vbh-section vbh-flex-col" :class="{ 'vbh-fadein': sectionFade }">
				<BookingsTab :is-mobile="isMobile"
					:booking-view="bookingView"
					:attachment-count-map="attachmentCountMap"
					:suggestions-by-id="suggestionsById"
					:open-import="openImport"
					:click-paperclip="clickPaperclip"
					:open-booking-card="openBookingCard"
					:edit-booking="editBooking"
					:create-rule-from-tx="createRuleFromTx"
					:remove-booking="removeBooking"
					:remove-transaction="removeTransaction"
					:open-account-picker="openAccountPicker"
					:on-assign="onAssign"
					:open-split-assign="openSplitAssign"
					:apply-suggestion="applySuggestion"
					@update:booking-view="bookingView = $event"
					@help="openHelp('bookings')" />
			</section>

			<!-- ============ KONTEN ============ -->
			<section v-show="activeTab === 'accounts'" class="vbh-section split" :class="{ 'vbh-fadein': sectionFade, 'vbh-drill': isMobile }">
				<AccountsTab :is-mobile="isMobile"
					:selected-account-id="selectedAccountId"
					:statement="statement"
					:statement-include-children.sync="statementIncludeChildren"
					:opening-form.sync="openingForm"
					:select-account="selectAccount"
					:close-account-detail="closeAccountDetail"
					:reload-statement="reloadStatement"
					:reassign-booking="reassignBooking"
					:open-new-account="openNewAccount"
					:open-edit-account="openEditAccount"
					:delete-account="deleteAccount"
					:save-opening="saveOpening"
					:seed-accounts="seedAccounts"
					@help="openHelp('accounts')" />
			</section>

			<!-- ============ BERICHTE (AUSWERTUNG + KOSTENSTELLEN + FINANZPLAN) ============ -->
			<section v-show="activeTab === 'reports'" class="vbh-section vbh-flex-col" :class="{ 'vbh-fadein': sectionFade }">
				<ReportsTab :is-active="activeTab === 'reports'"
					:is-mobile="isMobile"
					:report-view="reportView"
					:report-data="reportData"
					:budget-data="budgetData"
					:budget-snapshots="budgetSnapshots"
					:audit-entries="auditEntries"
					:audit-loading="auditLoading"
					:audit-end="auditEnd"
					:selected-c-c-code="selectedCCCode"
					:selected-sphere-code="selectedSphereCode"
					:cc-expanded="ccExpanded"
					:cc-bookings="ccBookings"
					:rename-name="renameName"
					:select-c-c="selectCC"
					:is-c-c-selected="isCCSelected"
					:toggle-c-c-account="toggleCCAccount"
					:save-rename="saveRename"
					:select-sphere="selectSphere"
					:is-sphere-selected="isSphereSelected"
					:load-audit="loadAudit"
					:open-snapshot="openSnapshot"
					@update:report-view="reportView = $event"
					@update:selected-c-c-code="selectedCCCode = $event"
					@update:selected-sphere-code="selectedSphereCode = $event"
					@update:rename-name="renameName = $event"
					@help="openHelp"
					@budget-changed="loadBudget"
					@snapshots-changed="loadBudgetSnapshots" />
			</section>
		</main>

		<MobileNav v-if="canRead && isMobile"
			:tabs="visibleTabs"
			:active-tab="activeTab"
			:unassigned-count="unassignedCount"
			:can-write="canWrite"
			@select="id => { activeTab = id }"
			@new-booking="openNewBooking" />

		<!-- ============ EINSTELLUNGEN MODAL ============ -->
		<NcModal :show.sync="showSettings"
			:name="t('Einstellungen & Import')"
			size="large"
			@close="showSettings = false">
			<div class="vbh-modal-inner">
				<h3>{{ t('Kontoumsätze importieren (CSV-CAMT)') }}</h3>
				<div class="vbh-card">
					<p class="vbh-hint">
						{{ t('Der CSV-Import ist direkt im Tab „Buchungen" erreichbar.') }}
					</p>
					<NcButton variant="secondary" @click="showSettings = false; openImport()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiUpload" :size="18" />
						</template>
						{{ t('Kontoumsätze importieren…') }}
					</NcButton>
				</div>

				<SettingsXbucImport :imports="imports"
					:busy.sync="busy"
					@changed="onXbucImported"
					@help="openHelp('bookings')" />

				<SettingsRules v-if="canWrite"
					:rules="rules"
					:accounts-by-id="accountsById"
					:account-options-list="accountOptionsList"
					@changed="loadRules" />

				<SettingsSpheres v-if="canWrite"
					:accounts="accounts"
					@changed="onSpheresChanged"
					@help="openHelp('spheres')" />

				<SettingsCostCenters v-if="canWrite"
					:mode="costCenterMode"
					@changed="onCostCentersChanged" />

				<SettingsPermissions v-if="isAdmin"
					@help="openHelp('setup')" />

				<SettingsSepaMandates v-if="isAdmin"
					:sepa-creditor-id.sync="sepaCreditorId"
					:sepa-debtor-account-id.sync="sepaDebtorAccountId"
					:storage-saving="storageSaving"
					:save-settings="saveStorageSettings" />

				<SettingsGeneral v-if="isAdmin"
					:club-name.sync="clubName"
					:cost-center-mode.sync="costCenterMode"
					:storage-user.sync="storageUser"
					:storage-path.sync="storagePath"
					:brand-color.sync="brandColor"
					:statement-watch-user.sync="statementWatchUser"
					:statement-watch-path.sync="statementWatchPath"
					:has-logo="hasLogo"
					:users="users"
					:storage-saving="storageSaving"
					:save-storage-settings="saveStorageSettings"
					@changed="loadStorageSettings" />

				<SettingsYearClose v-if="isAdmin"
					:busy="busy"
					:reset-all="resetAll" />
			</div>
		</NcModal>

		<!-- ============ IMPORT-DIALOG (CSV-CAMT) ============ -->
		<ImportDialog :show="showImport"
			:busy.sync="busy"
			@update:show="showImport = $event"
			@close="closeImport"
			@go-assign="goAssignAfterImport"
			@imported="onImported" />

		<!-- ============ BUCHUNGS-DIALOG ============ -->
		<BookingDialog :show="showBooking"
			:booking-form.sync="bookingForm"
			:booking-mode="bookingMode"
			:booking-locked="bookingLocked"
			:booking-tour="bookingTour"
			:is-mobile="isMobile"
			:can-write="canWrite"
			:pending-files.sync="pendingFiles"
			:booking-attachments="bookingAttachments"
			:attachment-uploading="attachmentUploading"
			:set-booking-kind="setBookingKind"
			:set-booking-mode="setBookingMode"
			:open-account-picker="openAccountPicker"
			:add-pending-files="addPendingFiles"
			:upload-attachment="uploadAttachment"
			:delete-attachment="deleteAttachment"
			:open-viewer="openViewer"
			:attachment-download-url="attachmentDownloadUrl"
			:next-tour-step="nextTourStep"
			:end-tour="endTour"
			@update:show="showBooking = $event"
			@close="closeBooking"
			@save="saveBooking"
			@delete="deleteBookingFromDialog" />

		<!-- ============ UMSATZ AUFTEILEN (ZUORDNEN) ============ -->
		<SplitAssignDialog :show="splitAssign.open"
			:tx="splitAssign.tx"
			:parts="splitAssign.parts"
			:is-mobile="isMobile"
			:open-account-picker="openAccountPicker"
			@update:show="splitAssign.open = $event"
			@update:parts="splitAssign.parts = $event"
			@close="closeSplitAssign"
			@save="saveSplitAssign" />

		<!-- ============ KONTO-DIALOG ============ -->
		<AccountDialog :show="showAccount"
			:account-edit-id="accountEditId"
			:initial-form="newAccount"
			:cost-center-mode="costCenterMode"
			@update:show="showAccount = $event"
			@close="closeAccount"
			@save="saveAccount"
			@help="openHelp('spheres')" />

		<!-- ============ PLAN-STAND DETAIL ============ -->
		<BudgetSnapshotModal :show="snapshotView.open"
			:snapshot="snapshotView.data"
			:current-plan-for-account="currentPlanForAccount"
			@update:show="snapshotView.open = $event"
			@close="closeSnapshot" />

		<!-- ============ BESTÄTIGUNGS-DIALOG ============ -->
		<!-- ============ KONTOAUSWAHL-SHEET (mobil) ============ -->
		<AccountPickerSheet :open="accountPicker.open"
			:title="accountPicker.title"
			:options="accountPickerOptions"
			:recent="recentAccountOptions"
			:suggestion="accountPickerSuggestion"
			:current-id="accountPickerCurrentId"
			@close="closeAccountPicker"
			@pick="onAccountPicked"
			@suggest="onAccountPickerSuggest" />

		<!-- ============ HILFE ============ -->
		<HelpModal :show="showHelp"
			:topic="helpTopic"
			@close="closeHelp"
			@update:show="showHelp = $event" />

		<!-- ============ SETUP-ASSISTENT (erster Verwalter-Login) ============ -->
		<SetupWizard :show="showSetupWizard"
			@close="closeSetupWizard"
			@update:show="showSetupWizard = $event"
			@choose="onWizardChoice" />

		<!-- Die Rueckfrage vor nicht umkehrbaren Aktionen. Sie steht hier, weil
			es genau eine geben soll; ausgeloest wird sie ueber useConfirm() aus
			jeder Komponente heraus. -->
		<NcDialog v-if="confirm.open"
			:name="confirm.title"
			:message="confirm.message"
			:no-close="true"
			:buttons="confirmButtons"
			@update:open="closeConfirm(false)" />
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { showError, showInfo, showSuccess, showUndo } from '@nextcloud/dialogs'
import {
	NcButton,
	NcDialog,
	NcIconSvgWrapper,
	NcLoadingIcon,
	NcModal,
} from '@nextcloud/vue'
import { mdiCog, mdiPlus, mdiUpload, mdiPrinter, mdiViewDashboardOutline, mdiSwapHorizontal, mdiFileTreeOutline, mdiChartBar, mdiHelpCircleOutline } from '@mdi/js'
import api from './api.js'
import { formatMoney, formatDate, formatDateTime, typeLabel, amountClass, budgetDiffClass, errMsg } from './lib/format.js'
import { splitSideOf, splitRemainder, splitBalanced } from './lib/split.js'
import SettingsRules from './components/SettingsRules.vue'
import SettingsSpheres from './components/SettingsSpheres.vue'
import SettingsCostCenters from './components/SettingsCostCenters.vue'
import SettingsXbucImport from './components/SettingsXbucImport.vue'
import SettingsPermissions from './components/SettingsPermissions.vue'
import SettingsSepaMandates from './components/SettingsSepaMandates.vue'
import SettingsGeneral from './components/SettingsGeneral.vue'
import SettingsYearClose from './components/SettingsYearClose.vue'
import ImportDialog from './components/ImportDialog.vue'
import AccountDialog from './components/AccountDialog.vue'
import BookingDialog from './components/BookingDialog.vue'
import SplitAssignDialog from './components/SplitAssignDialog.vue'
import BudgetSnapshotModal from './components/BudgetSnapshotModal.vue'
import DashboardTab from './components/DashboardTab.vue'
import AccountsTab from './components/AccountsTab.vue'
import BookingsTab from './components/BookingsTab.vue'
import ReportsTab from './components/ReportsTab.vue'
import MobileNav from './components/MobileNav.vue'
import AccountPickerSheet from './components/AccountPickerSheet.vue'
import HelpModal from './components/HelpModal.vue'
import SetupWizard from './components/SetupWizard.vue'
import { useAuth } from './composables/useAuth.js'
import { useYears } from './composables/useYears.js'
import { useAccounts } from './composables/useAccounts.js'
import { useBalances } from './composables/useBalances.js'
import { useJournal } from './composables/useJournal.js'
import { usePermissions } from './composables/usePermissions.js'
import { useSync } from './composables/useSync.js'
import { useOpenItems } from './composables/useOpenItems.js'
import { useCostCenters } from './composables/useCostCenters.js'
import { useConfirm } from './composables/useConfirm.js'
import { useSort } from './composables/useSort.js'

export default {
	name: 'App',
	components: {
		NcButton,
		NcDialog,
		NcIconSvgWrapper,
		NcLoadingIcon,
		NcModal,
		SettingsRules,
		SettingsSpheres,
		SettingsCostCenters,
		SettingsXbucImport,
		SettingsPermissions,
		SettingsSepaMandates,
		SettingsGeneral,
		SettingsYearClose,
		ImportDialog,
		AccountDialog,
		BookingDialog,
		SplitAssignDialog,
		BudgetSnapshotModal,
		DashboardTab,
		AccountsTab,
		BookingsTab,
		ReportsTab,
		MobileNav,
		AccountPickerSheet,
		HelpModal,
		SetupWizard,
	},
	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const balances = useBalances()
		const journal = useJournal()
		const permissions = usePermissions()
		const sync = useSync()
		const openItems = useOpenItems()
		const costCenters = useCostCenters()
		return {
			loadOpenItems: openItems.loadOpenItems,
			loadCostCenters: costCenters.loadCostCenters,
			...toRefs(auth.state),
			canRead: auth.canRead,
			canWrite: auth.canWrite,
			isAdmin: auth.isAdmin,
			// eigener Name, damit App.vue seine eigene loadMe()-Methode mit
			// Zusatzlogik (Tab-Umschalten, Revisor-Hinweis) behalten kann.
			authLoadMe: auth.loadMe,
			...toRefs(years.state),
			closedYearSet: years.closedYearSet,
			yearClosed: years.yearClosed,
			isYearClosed: years.isYearClosed,
			loadYears: years.loadYears,
			loadClosedYears: years.loadClosedYears,
			...toRefs(accounts.state),
			accountsById: accounts.accountsById,
			accountsSorted: accounts.accountsSorted,
			childrenOf: accounts.childrenOf,
			// eigene Namen, da App.vue-eigene loadAccounts()/seedAccounts() noch
			// das lokale openingForm nachziehen bzw. eine Erfolgsmeldung zeigen.
			accountsLoad: accounts.loadAccounts,
			accountsSeedDefaults: accounts.seedDefaults,
			...toRefs(balances.state),
			loadBalances: balances.loadBalances,
			// eigener Name, damit App.vue seine eigene loadSphereReport()-Methode
			// mit Zusatzlogik (selectedSphereCode zurücksetzen) behalten kann.
			balancesLoadSphereReport: balances.loadSphereReport,
			...toRefs(journal.state),
			journalRows: journal.journalRows,
			unassignedCount: journal.unassignedCount,
			// eigener Name, da App.vue seine eigene loadJournal()-Methode mit
			// Zusatzlogik (Beleg-Zähler nachladen) behalten kann.
			journalLoad: journal.loadJournal,
			loadTransactions: journal.loadTransactions,
			...toRefs(permissions.state),
			loadPermissions: permissions.loadPermissions,
			...toRefs(sync.state),
			checkRemoteRevision: sync.checkRemoteRevision,
			// Rueckfrage und Sortierung: gemeinsamer Zustand statt
			// Funktions-Props durch den ganzen Komponentenbaum.
			...useConfirm(),
			...useSort(),
		}
	},
	data() {
		return {
			activeTab: 'dashboard',
			allTabs: [
				{ id: 'dashboard', label: this.t('Übersicht'), need: 'read', icon: mdiViewDashboardOutline },
				{ id: 'bookings', label: this.t('Buchungen'), need: 'read', icon: mdiSwapHorizontal },
				{ id: 'accounts', label: this.t('Konten'), need: 'read', icon: mdiFileTreeOutline },
				{ id: 'reports', label: this.t('Berichte'), need: 'read', icon: mdiChartBar },
			],
			bookingView: 'journal',
			reportView: 'summary',
			showSettings: false,
			budgetData: null,
			budgetSnapshots: [],
			snapshotView: { open: false, data: null },
			busy: false,
			imports: [],
			reportData: null,
			selectedCCCode: false,
			selectedSphereCode: false,
			renameName: '',
			ccExpanded: {},
			ccBookings: {},
			newAccount: { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null, sphere: '' },
			accountEditId: null,
			openingForm: {},
			selectedAccountId: null,
			statement: null,
			statementIncludeChildren: true,
			showBooking: false,
			showAccount: false,
			bookingMode: 'simple',
			showImport: false,
			rules: [],
			sectionFade: true,
			bookingForm: this.emptyBookingForm(),
			mdiCog,
			mdiPlus,
			mdiUpload,
			mdiPrinter,
			bookingAttachments: [],
			attachmentUploading: false,
			attachmentCountMap: {},
			// Kollaboration: Poll-Timer (Änderungsstand selbst kommt aus useSync)
			syncTimer: null,
			// Mobil-Layout (≤ 640px): schaltet Bottom-Nav, Kartenlisten etc.
			isMobile: false,
			// Kontoauswahl-Sheet (mobil): target = category|money|debit|credit|assign|splitline:<i>
			accountPicker: { open: false, target: null, title: '', tx: null },
			// Umsatz beim Zuordnen auf mehrere Gegenkonten aufteilen
			splitAssign: { open: false, tx: null, parts: [] },
			// Belege, die beim Anlegen gesammelt und nach dem Speichern hochgeladen werden
			pendingFiles: [],
			// Zuletzt im Auswahl-Sheet gewählte Konten (localStorage, max. 5)
			recentAccountIds: [],
			// Änderungsprotokoll (Berichte → Protokoll)
			auditEntries: [],
			auditLoading: false,
			auditEnd: false,
			storageUser: '',
			storagePath: '',
			costCenterMode: 'group',
			clubName: '',
			brandColor: '',
			hasLogo: false,
			storageSaving: false,
			// Überwachter Ordner für Kontoauszüge (leer = aus); der stündliche
			// Hintergrundjob liest daraus ein.
			statementWatchUser: '',
			statementWatchPath: '',
			// SEPA-Lastschrift (optionales Zusatzmodul, siehe SettingsSepaMandates.vue)
			sepaCreditorId: '',
			sepaDebtorAccountId: null,
			// Hilfe-Modal (HelpModal.vue): Kapitel folgt standardmäßig dem aktiven Tab,
			// kann aber gezielt überschrieben werden (z. B. Links aus Leerzuständen).
			showHelp: false,
			helpForcedTopic: null,
			// Einmaliger Willkommenshinweis für die Rolle „Revisor" (localStorage, dauerhaft ausblendbar)
			revisorIntroDismissed: true,
			// Geführter Setup-Assistent (SetupWizard.vue) beim allerersten Verwalter-Login
			showSetupWizard: false,
			// Beispieldaten (DemoDataService) aktiv – Banner mit Zurücksetzen-Hinweis
			demoActive: false,
			// Erste-Buchung-Tour (Feld-Hervorhebung im Einfach-Modus, Desktop, einmalig)
			bookingTour: { active: false, step: 0 },
			mdiHelpCircleOutline,
		}
	},
	computed: {
		// canRead/canWrite/isAdmin/closedYearSet/yearClosed kommen aus setup() (useAuth/useYears).
		// Bearbeiten-Dialog einer Buchung aus einem abgeschlossenen Jahr → nur ansehen
		bookingLocked() { return !!(this.bookingForm.id && this.isYearClosed(this.bookingForm.date)) },
		// exportBalancesUrl/exportReportUrl/exportBudgetUrl/exportMultiyearUrl/
		// kassenberichtUrl/attachmentsZipUrl sind jetzt Teil von ReportsTab.vue.
		// pruefleitfadenUrl bleibt hier (Revisor-Willkommenshinweis braucht sie
		// ausserhalb des Berichte-Tabs).
		pruefleitfadenUrl() { return api.pruefleitfadenUrl() },
		visibleTabs() {
			return this.allTabs.filter(t => {
				if (t.need === 'admin') return this.isAdmin
				if (t.need === 'write') return this.canWrite
				return this.canRead
			})
		},
		// Hilfe-Kapitel, das zum gerade aktiven Tab passt (HelpModal-Default)
		helpTopic() {
			if (this.helpForcedTopic) return this.helpForcedTopic
			const map = { dashboard: 'setup', bookings: 'bookings', accounts: 'accounts', reports: 'reports' }
			return map[this.activeTab] || 'setup'
		},
		showRevisorIntro() {
			return !!(this.me && this.me.role === 'revisor' && !this.revisorIntroDismissed)
		},
		// unassignedCount kommt aus setup() (useJournal).
		// currentTransactions/txByJournalId/filteredJournalRows/journalNumberIssues/
		// journalCardGroups/bookingFilterAccountOption sind jetzt Teil von
		// BookingsTab.vue.
		recentJournal() {
			return this.sortedJournalRows.slice(0, 5)
		},
		// currentTree/filteredVisibleTree/visibleTree/statementRows sind jetzt
		// Teil von AccountsTab.vue.
		// accountsById/accountsSorted/childrenOf kommen aus setup() (useAccounts).
		// parentOptions/accountParentOptions/accountParentOption sind jetzt Teil
		// von AccountDialog.vue (eigenes setup() mit useAccounts()).
		accountsByCategory() {
			const groups = {}
			for (const acc of this.accountsSorted) {
				if (!acc.active) continue
				const cat = acc.category || this.t('Sonstige')
				if (!groups[cat]) groups[cat] = []
				groups[cat].push(acc)
			}
			return groups
		},
		accountUsageCounts() {
			const counts = {}
			for (const item of this.journalData) {
				for (const l of (item.lines || [])) {
					counts[l.accountId] = (counts[l.accountId] || 0) + 1
				}
			}
			return counts
		},
		frequentAccounts() {
			const counts = this.accountUsageCounts
			return this.accounts
				.filter(a => a.active && counts[a.id])
				.sort((a, b) => counts[b.id] - counts[a.id])
				.slice(0, 5)
		},
		accountOptionsList() {
			const opts = []
			if (this.frequentAccounts.length >= 2) {
				opts.push({ id: null, label: this.t('★ Häufig verwendet'), $isDisabled: true })
				for (const acc of this.frequentAccounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			for (const [cat, accounts] of Object.entries(this.accountsByCategory)) {
				opts.push({ id: null, label: cat, $isDisabled: true })
				for (const acc of accounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			return opts
		},
		// Optionen für den Einfach-Modus des Buchungsdialogs
		simpleCategoryOptions() {
			const type = this.bookingForm.kind === 'income' ? 'income' : 'expense'
			const counts = this.accountUsageCounts
			return this.accounts
				.filter(a => a.active && a.type === type)
				.sort((a, b) => (counts[b.id] || 0) - (counts[a.id] || 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},
		moneyAccountOptions() {
			return this.accounts
				.filter(a => a.active && (a.isBank || a.type === 'asset'))
				.sort((a, b) => (b.isBank ? 1 : 0) - (a.isBank ? 1 : 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},
		defaultMoneyAccountId() {
			// Nur echte Geldkonten automatisch vorauswählen – sonst könnte z.B. ein
			// Durchlaufkonto unbemerkt zum Standard-Geldkonto werden.
			const bank = this.accounts.find(a => a.active && a.isBank)
			return bank ? bank.id : null
		},
		// bookingFormCategoryOption/MoneyOption/DebitOption/CreditOption und
		// bookingModeExpert sind jetzt Teil von BookingDialog.vue.
		// Kontoauswahl-Sheet (mobil): Optionen/Vorschlag/Auswahl je nach Ziel
		accountPickerOptions() {
			const t = this.accountPicker.target
			if (t === 'category') return this.simpleCategoryOptions
			if (t === 'money') return this.moneyAccountOptions
			// Zeile einer Aufteilung: im Buchungsdialog im Einfach-Modus dieselben
			// Kategorien wie sonst auch, sonst alle Konten (auch beim Zuordnen).
			if (t && t.startsWith('splitline:')) {
				return !this.splitAssign.open && this.bookingMode === 'simple'
					? this.simpleCategoryOptions
					: this.accountOptionsList
			}
			return this.accountOptionsList
		},
		accountPickerSuggestion() {
			const p = this.accountPicker
			return (p.target === 'assign' && p.tx && this.suggestionsById[p.tx.id]) || null
		},
		recentAccountOptions() {
			const out = []
			for (const id of this.recentAccountIds) {
				const a = this.accountsById[id]
				if (a && a.active) out.push({ id: a.id, label: `${a.number} ${a.name}`, number: a.number })
			}
			return out
		},
		accountPickerCurrentId() {
			const t = this.accountPicker.target
			const f = this.bookingForm
			if (t === 'category') return f.categoryId
			if (t === 'money') return f.moneyAccountId
			if (t === 'debit') return f.debitAccountId
			if (t === 'credit') return f.creditAccountId
			if (t === 'assign' && this.accountPicker.tx) return this.accountPicker.tx.contraAccountId
			if (t && t.startsWith('splitline:')) {
				const i = Number(t.slice('splitline:'.length))
				const lines = this.splitAssign.open ? this.splitAssign.parts : f.splitLines
				return lines[i] ? lines[i].accountId : null
			}
			return null
		},
		// Frühere Zuordnungen je Zahlungspartner (für Vorschläge)
		assignmentHistory() {
			const map = {}
			for (const tx of this.transactions) {
				if (tx.status === 'assigned' && tx.contraAccountId && tx.counterparty) {
					const key = tx.counterparty.trim().toLowerCase()
					if (!map[key]) map[key] = {}
					map[key][tx.contraAccountId] = (map[key][tx.contraAccountId] || 0) + 1
				}
			}
			return map
		},
		// Zuordnungs-Vorschlag je offener Bankbuchung (Regeln zuerst, dann Historie)
		suggestionsById() {
			const out = {}
			for (const tx of this.transactions) {
				if (tx.status === 'assigned') continue
				const s = this.computeSuggestion(tx)
				if (s) out[tx.id] = s
			}
			return out
		},
		// assignProgress ist jetzt Teil von BookingsTab.vue.
		// selectedAccount bleibt hier (wird auch von openNewAccount() gebraucht,
		// das ausserhalb von AccountsTab.vue liegt).
		selectedAccount() {
			return this.selectedAccountId ? this.accountsById[this.selectedAccountId] : null
		},
		primaryBank() {
			const list = this.balances && this.balances.bankReconciliation
			return list && list.length ? list[0] : null
		},
		// journalRows kommt aus setup() (useJournal).
		// visibleTree/statementRows sind jetzt Teil von AccountsTab.vue.
		selectedCC() {
			if (this.selectedCCCode === false || !this.reportData) return null
			return this.reportData.costCenters.find(c => c.code === this.selectedCCCode) || null
		},
		// selectedSphere/accountDepth/balanceRows/sortedBalances sind jetzt Teil
		// von ReportsTab.vue.
		sortedJournalRows() { return this.applySort(this.journalRows, this.sort.journal) },
	},
	watch: {
		activeTab(tab) {
			this.loadTab(tab)
			// Einblend-Animation der Sektion neu starten
			this.sectionFade = false
			this.$nextTick(() => requestAnimationFrame(() => { this.sectionFade = true }))
		},
		// journalData-Watcher fuer den Monatschart-Redraw ist jetzt Teil von
		// DashboardTab.vue (eigener Watcher auf sein eigenes journalData).
		// bookingSearch-Reset bei bookingView-Wechsel ist jetzt Teil von
		// BookingsTab.vue (eigener Watcher auf sein bookingView-Prop).
		bookingView(v) {
			if (v === 'journal') this.loadJournal()
		},
		reportView(v) {
			if (v === 'summary') this.loadBalances()
			else if (v === 'costcenters') this.loadReport()
			else if (v === 'spheres') this.loadSphereReport()
			else if (v === 'budget') this.loadBudget()
			else if (v === 'audit') this.loadAudit()
		},
		async selectedYear() {
			// Jahresbezogene Caches invalidieren
			this.ccBookings = {}
			this.ccExpanded = {}
			this.busy = true
			try {
				const jobs = [this.loadBalances(), this.loadJournal(), this.loadSphereReport()]
				const tab = this.activeTab
				if (tab === 'accounts') {
					jobs.push(this.loadAccounts())
					if (this.selectedAccountId) jobs.push(this.loadStatement(this.selectedAccountId))
				} else if (tab === 'reports') {
					if (this.reportView === 'costcenters') jobs.push(this.loadReport())
					else if (this.reportView === 'budget') jobs.push(this.loadBudget())
				}
				await Promise.all(jobs)
			} finally { this.busy = false }
		},
	},
	async mounted() {
		document.addEventListener('keydown', this.onGlobalKeydown)
		// Mobil-Layout reaktiv am selben Breakpoint wie das CSS (640px)
		this.vbhMql = window.matchMedia('(max-width: 640px)')
		this.isMobile = this.vbhMql.matches
		this.onMqChange = e => { this.isMobile = e.matches }
		if (this.vbhMql.addEventListener) this.vbhMql.addEventListener('change', this.onMqChange)
		else this.vbhMql.addListener(this.onMqChange)
		this.loadRecentAccounts()
		await this.loadMe()
		if (this.canRead) {
			await this.loadYears()
			await Promise.all([
				this.loadAccounts(),
				this.loadImports(),
				this.loadBalances(),
				this.loadJournal(),
				this.loadTransactions(),
				this.loadRules(),
				this.loadClosedYears(),
				this.loadSphereReport(),
				this.loadOpenItems(),
				this.loadCostCenters(),
			])
			// storage/demo-Status betrifft alle Leseberechtigten (Demo-Banner); Berechtigungsliste nur Verwalter (Backend-Gate)
			this.loadStorageSettings()
			if (this.isAdmin) {
				this.loadPermissions()
				// Setup-Assistent beim allerersten Login eines Verwalters (leerer Verein, noch nicht gesehen)
				if (this.accounts.length === 0 && !this.setupWizardSeen()) this.showSetupWizard = true
			}
			// Kollaboration: Änderungen anderer Personen per Polling mitbekommen
			this.checkRevision(true)
			this.syncTimer = setInterval(() => this.checkRevision(), 20000)
			window.addEventListener('focus', this.onWindowFocus)
		}
	},
	beforeDestroy() {
		document.removeEventListener('keydown', this.onGlobalKeydown)
		if (this.vbhMql) {
			if (this.vbhMql.removeEventListener) this.vbhMql.removeEventListener('change', this.onMqChange)
			else this.vbhMql.removeListener(this.onMqChange)
		}
		if (this.syncTimer) clearInterval(this.syncTimer)
		window.removeEventListener('focus', this.onWindowFocus)
	},
	methods: {
		// --- Tastaturkürzel: N = neue Buchung, / = Suche fokussieren ---
		onGlobalKeydown(e) {
			if (e.ctrlKey || e.metaKey || e.altKey) return
			const tag = (e.target.tagName || '').toLowerCase()
			if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return
			if (this.showBooking || this.showAccount || this.showImport || this.showSettings || this.confirm.open) return
			if ((e.key === 'n' || e.key === 'N') && this.canWrite) {
				e.preventDefault()
				this.openNewBooking()
			} else if (e.key === '/') {
				e.preventDefault()
				if (this.activeTab === 'accounts') {
					this.$el.querySelector('.vbh-treesearch input')?.focus()
				} else {
					this.activeTab = 'bookings'
					this.$nextTick(() => this.$el.querySelector('.vbh-filterbar input[type=search]')?.focus())
				}
			}
		},
		async loadTab(tab) {
			const jobs = []
			if (tab === 'bookings' && this.bookingView === 'journal') jobs.push(this.loadJournal())
			else if (tab === 'accounts') { jobs.push(this.loadAccounts(), this.loadBalances()) } else if (tab === 'reports') {
				if (this.reportView === 'summary') jobs.push(this.loadBalances())
				else if (this.reportView === 'costcenters') jobs.push(this.loadReport())
				else if (this.reportView === 'spheres') jobs.push(this.loadSphereReport())
				else if (this.reportView === 'budget') jobs.push(this.loadBudget())
				else if (this.reportView === 'audit') jobs.push(this.loadAudit())
			}
			if (!jobs.length) return
			this.busy = true
			try { await Promise.all(jobs) } finally { this.busy = false }
		},
		goToUnassigned() {
			this.activeTab = 'bookings'
			this.bookingView = 'unassigned'
		},
		goToOpenItems() {
			this.activeTab = 'bookings'
			this.bookingView = 'openitems'
		},
		// --- Kollaboration: Änderungen anderer Browser erkennen -------------
		onWindowFocus() { this.checkRevision() },
		async checkRevision(init = false) {
			if (!this.canRead) return
			if (!init && document.hidden) return
			const result = await this.checkRemoteRevision(init, this.busy)
			if (result !== 'changed') return
			// Nach eigener Schreibaktion still aktualisieren (die Handler haben schon
			// nachgeladen, aber eine zeitgleiche Fremdänderung darf nicht verloren gehen).
			const ownWrite = Date.now() - api.lastWriteAt() < 15000
			await this.refreshAfterRemoteChange()
			if (!ownWrite) showInfo(this.t('Die Buchhaltung wurde von einer anderen Person geändert – Ansicht aktualisiert.'))
		},
		async refreshAfterRemoteChange() {
			this.ccBookings = {}
			this.ccExpanded = {}
			const jobs = [this.loadYears(), this.loadClosedYears(), this.loadAccounts(), this.loadBalances(), this.loadJournal(), this.loadTransactions(), this.loadSphereReport(), this.loadOpenItems(), this.loadCostCenters()]
			if (this.activeTab === 'accounts' && this.selectedAccountId) jobs.push(this.loadStatement(this.selectedAccountId))
			if (this.activeTab === 'reports') {
				if (this.reportView === 'costcenters') jobs.push(this.loadReport())
				else if (this.reportView === 'budget') jobs.push(this.loadBudget())
			}
			try { await Promise.all(jobs) } catch (e) { /* nächster Poll versucht es erneut */ }
		},
		openSettings() {
			this.showSettings = true
			this.loadImports()
			if (this.isAdmin) {
				this.loadPermissions()
				this.loadStorageSettings()
			}
		},
		async loadStorageSettings() {
			try {
				const { data } = await api.getSettings()
				this.storageUser = data.storage_user || ''
				this.storagePath = data.storage_path || 'Vereinsbuchhaltung/Belege'
				this.costCenterMode = data.cost_center_mode || 'group'
				this.clubName = data.club_name || ''
				this.brandColor = data.brand_color || ''
				this.hasLogo = !!data.has_logo
				this.demoActive = !!data.demo_active
				this.statementWatchUser = data.statement_watch_user || ''
				this.statementWatchPath = data.statement_watch_path || ''
				this.sepaCreditorId = data.sepa_creditor_id || ''
				this.sepaDebtorAccountId = data.sepa_debtor_account_id || null
			} catch (e) { /* ignorieren */ }
		},
		async saveStorageSettings() {
			this.storageSaving = true
			try {
				await api.saveSettings({ storage_user: this.storageUser, storage_path: this.storagePath || 'Vereinsbuchhaltung/Belege', cost_center_mode: this.costCenterMode, club_name: this.clubName, brand_color: this.brandColor, statement_watch_user: this.statementWatchUser, statement_watch_path: this.statementWatchPath, sepa_creditor_id: this.sepaCreditorId, sepa_debtor_account_id: this.sepaDebtorAccountId || '' })
				showSuccess(this.t('Einstellungen gespeichert.'))
				this.reportData = null
			} catch (e) {
				const msg = (e?.response?.data?.message) || this.t('Speichern fehlgeschlagen (HTTP {status})', { status: e?.response?.status ?? this.t('Netzwerkfehler') })
				showError(msg)
			} finally { this.storageSaving = false }
		},
		// loadYears/loadClosedYears/isYearClosed kommen aus setup() (useYears).
		// closeYear/reopenYear sind jetzt Teil von SettingsYearClose.vue (eigenes
		// setup() mit useYears()).
		// --- Änderungsprotokoll ----------------------------------------------
		async loadAudit(more = false) {
			if (this.auditLoading) return
			this.auditLoading = true
			try {
				const offset = more ? this.auditEntries.length : 0
				const { data } = await api.auditLog(100, offset)
				this.auditEnd = data.length < 100
				this.auditEntries = more ? this.auditEntries.concat(data) : data
			} catch (e) {
				showError(this.errMsg(e, this.t('Protokoll konnte nicht geladen werden')))
			} finally { this.auditLoading = false }
		},
		// auditDetailText ist jetzt Teil von ReportsTab.vue.
		emptyBookingForm() {
			return {
				id: null,
				entryNo: null,
				date: new Date().toISOString().slice(0, 10),
				documentRef: '',
				amount: null,
				debitAccountId: null,
				creditAccountId: null,
				description: '',
				kind: 'expense',
				moneyAccountId: null,
				categoryId: null,
				updatedAt: null,
				// Splittbuchung: eine Seite bleibt einzeilig (Geldkonto bzw. im
				// Experten-Modus die gewaehlte Seite), die andere wird zur Liste.
				// splitSide nennt die aufgeteilte Seite; im Einfach-Modus ergibt
				// sie sich aus kind, siehe splitSideForForm().
				splitMode: false,
				splitSide: 'credit',
				splitLines: [],
			}
		},
		/** Die aufgeteilte Seite – Regel in ./lib/split.js. */
		splitSideForForm() {
			return splitSideOf(this.bookingForm, this.bookingMode)
		},
		/** Konto der festen (einzeiligen) Seite. */
		splitFixedAccountId() {
			const f = this.bookingForm
			if (this.bookingMode === 'simple') return f.moneyAccountId
			return this.splitSideForForm() === 'credit' ? f.debitAccountId : f.creditAccountId
		},
		/**
		 * Baut aus der festen Seite und der Aufteilung die Zeilen fuer die API
		 * (Betraege in Euro, wie beim einfachen Buchen auch).
		 */
		buildSplitPayloadLines() {
			const f = this.bookingForm
			const side = this.splitSideForForm()
			const total = Number(f.amount || 0)
			const fixed = side === 'credit'
				? { accountId: this.splitFixedAccountId(), debit: total, credit: 0 }
				: { accountId: this.splitFixedAccountId(), debit: 0, credit: total }
			const rest = f.splitLines.map(l => (side === 'credit'
				? { accountId: l.accountId, debit: 0, credit: Number(l.amount) }
				: { accountId: l.accountId, debit: Number(l.amount), credit: 0 }))
			return [fixed, ...rest]
		},
		// --- Einfach-Modus: Einnahme/Ausgabe <-> Soll/Haben ---
		deriveSimpleAccounts() {
			const f = this.bookingForm
			if (!f.categoryId || !f.moneyAccountId) return null
			// Einnahme: Soll Geldkonto / Haben Ertragskonto — Ausgabe: Soll Aufwandskonto / Haben Geldkonto
			return f.kind === 'income'
				? { debit: f.moneyAccountId, credit: f.categoryId }
				: { debit: f.categoryId, credit: f.moneyAccountId }
		},
		mapToSimple(debitId, creditId) {
			const d = this.accountsById[debitId]
			const c = this.accountsById[creditId]
			if (!d || !c) return null
			const isMoney = a => a.isBank || a.type === 'asset'
			if (isMoney(d) && c.type === 'income') return { kind: 'income', moneyAccountId: d.id, categoryId: c.id }
			if (d.type === 'expense' && isMoney(c)) return { kind: 'expense', moneyAccountId: c.id, categoryId: d.id }
			return null
		},
		setBookingKind(kind) {
			if (this.bookingForm.kind === kind) return
			this.bookingForm.kind = kind
			this.bookingForm.categoryId = null
			// Bei einer Aufteilung wechseln die Gegenkonten mit der Buchungsart
			// die Seite (siehe splitSideOf) - die bisher gewaehlten Kategorien
			// gehoeren dann zur falschen Richtung.
			if (this.bookingForm.splitMode) this.bookingForm.splitLines = this.bookingForm.splitLines.map(l => ({ ...l, accountId: null }))
		},
		setBookingMode(mode) {
			if (mode === this.bookingMode) return
			const f = this.bookingForm
			if (f.splitMode) {
				// Bei einer Aufteilung stehen Soll/Haben nicht komplett fest; es
				// wandert nur die feste Seite zwischen Geldkonto und Soll/Haben.
				const side = this.splitSideForForm()
				if (mode === 'expert') {
					f.splitSide = side
					if (side === 'credit') f.debitAccountId = f.moneyAccountId
					else f.creditAccountId = f.moneyAccountId
				} else {
					f.moneyAccountId = side === 'credit' ? f.debitAccountId : f.creditAccountId
					f.kind = side === 'credit' ? 'income' : 'expense'
				}
				this.bookingMode = mode
				return
			}
			if (mode === 'expert') {
				const d = this.deriveSimpleAccounts()
				if (d) { f.debitAccountId = d.debit; f.creditAccountId = d.credit }
			} else {
				const m = this.mapToSimple(f.debitAccountId, f.creditAccountId)
				if (m) Object.assign(f, m)
			}
			this.bookingMode = mode
		},
		// Formatier-/Label-Helfer aus ./lib/format.js (zustandslos, als Methoden
		// eingebunden, damit das Template sie unveraendert aufrufen kann).
		formatMoney,
		formatDate,
		formatDateTime,
		typeLabel,
		amountClass,
		budgetDiffClass,
		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},
		// accountOptionFor/accountFilterBy sind jetzt Teil von BookingsTab.vue
		// (nicht mehr anderswo in App.vue gebraucht).

		// --- Confirm-Dialog ---
		// askConfirm/closeConfirm kommen aus useConfirm(), Sortierung aus
		// useSort() - beide als gemeinsamer Zustand, damit sie nicht mehr als
		// Funktions-Props durch den Komponentenbaum gereicht werden muessen.

		// --- Baum: toggleExpand/expandAll/collapseAll sind jetzt Teil von
		// AccountsTab.vue. ---
		async selectAccount(node) {
			this.selectedAccountId = node.id
			this.statementIncludeChildren = true
			await this.loadStatement(node.id)
		},
		async reloadStatement() { if (this.selectedAccountId) await this.loadStatement(this.selectedAccountId) },
		/**
		 * Kontoauszug: eine falsch zugeordnete Buchung an Ort und Stelle auf ein
		 * anderes Konto umbuchen, ohne den Umweg über das Journal. Geändert wird
		 * nur die Kontozuordnung einer Seite – Betrag, Datum und Gegenseite
		 * bleiben, damit Soll und Haben nicht auseinanderlaufen koennen.
		 *
		 * @param {object} row Zeile des Kontoauszugs
		 * @param {number} fromAccountId Konto, das die Buchung verlaesst
		 * @param {number} toAccountId Zielkonto
		 * @return {Promise<boolean>} true, wenn umgebucht wurde
		 */
		async reassignBooking(row, fromAccountId, toAccountId) {
			if (!fromAccountId || !toAccountId || fromAccountId === toAccountId) return false
			try {
				await api.reassignBooking(row.journalId, fromAccountId, toAccountId, row.updatedAt)
				showSuccess(this.t('Buchung #{n} auf {account} umgebucht.', { n: row.entryNo, account: this.accountLabel(toAccountId) }))
				await Promise.all([this.reloadStatement(), this.loadBalances(), this.loadJournal(), this.loadSphereReport()])
				return true
			} catch (e) {
				if (e?.response?.status === 409) {
					showError(this.t('Diese Buchung wurde zwischenzeitlich von einer anderen Person geändert. Die Ansicht wurde aktualisiert – bitte erneut versuchen.'))
					await this.reloadStatement()
					return false
				}
				showError(this.errMsg(e, this.t('Umbuchen fehlgeschlagen')))
				return false
			}
		},
		async loadStatement(accountId) {
			try { const { data } = await api.accountJournal(accountId, this.statementIncludeChildren, this.selectedYear); this.statement = data } catch (e) { showError(this.errMsg(e, this.t('Kontoauszug konnte nicht geladen werden'))) }
		},

		// --- CSV-Import (ImportDialog.vue) ---
		openImport() { this.showImport = true },
		closeImport() { this.showImport = false },
		goAssignAfterImport() {
			this.closeImport()
			this.activeTab = 'bookings'
			this.bookingView = 'unassigned'
		},
		async onImported() { await this.loadImports(); await this.loadBalances(); await this.loadTransactions() },
		async loadImports() { try { const { data } = await api.listImports(); this.imports = data } catch (e) { /* still */ } },

		// SettingsXbucImport.vue meldet einen erfolgreichen Import; die Nachlade-
		// Orchestrierung über mehrere Composables + lokales imports bleibt hier.
		async onXbucImported() {
			await this.loadYears(); await this.loadAccounts(); await this.loadBalances(); await this.loadImports(); await this.loadJournal(); await this.loadTransactions(); await this.loadCostCenters()
		},
		async resetAll() {
			if (!await this.askConfirm(this.t('Alle Daten löschen'), this.t('Wirklich ALLE Konten, Buchungen und Importe löschen?'))) return
			this.busy = true
			try {
				await api.reset(); showSuccess(this.t('Alle Daten gelöscht.'))
				this.selectedAccountId = null; this.statement = null; this.journalData = []; this.transactions = []
				this.selectedYear = null
				this.demoActive = false
				await this.loadYears(); await this.loadAccounts(); await this.loadBalances(); await this.loadImports(); await this.loadCostCenters()
			} catch (e) { showError(this.errMsg(e, this.t('Zurücksetzen fehlgeschlagen'))) } finally { this.busy = false }
		},
		// --- Beispieldaten (Onboarding) ---
		async seedDemoData() {
			this.busy = true
			try {
				await api.seedDemo()
				this.demoActive = true
				await Promise.all([this.loadYears(), this.loadAccounts(), this.loadBalances(), this.loadJournal(), this.loadTransactions()])
				showSuccess(this.t('Beispielverein angelegt – schau dich gern um. Zum Starten mit echten Daten: Zurücksetzen.'))
			} catch (e) { showError(this.errMsg(e, this.t('Beispieldaten konnten nicht angelegt werden'))) } finally { this.busy = false }
		},
		setupWizardSeen() {
			try { return localStorage.getItem('vbh_setup_wizard_seen') === '1' } catch (e) { return false }
		},
		markSetupWizardSeen() {
			try { localStorage.setItem('vbh_setup_wizard_seen', '1') } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		closeSetupWizard() {
			this.showSetupWizard = false
			this.markSetupWizardSeen()
		},
		onWizardChoice(choice) {
			this.closeSetupWizard()
			if (choice === 'xbuc') this.openSettings()
			else if (choice === 'fresh') this.seedAccounts()
			else if (choice === 'demo') this.seedDemoData()
		},

		// --- Bankbuchungen ---
		// loadTransactions kommt aus setup() (useJournal).
		async loadRules() { try { const { data } = await api.listRules(); this.rules = data } catch (e) { /* Regeln optional */ } },
		async onSpheresChanged() { await this.loadAccounts(); await this.loadSphereReport() },
		// Kostenstellen: die Zuordnung haengt am Konto, deshalb muessen die
		// Konten mit nachgeladen werden; der Bericht nur, wenn er offen ist.
		async onCostCentersChanged() {
			await this.loadAccounts()
			if (this.activeTab === 'reports' && this.reportView === 'costcenters') await this.loadReport()
		},
		async onAssign(tx, value) {
			const prevContra = tx.contraAccountId
			try {
				if (value === '') {
					await api.unassignTransaction(tx.id)
					if (prevContra) {
						showUndo(this.t('Zuordnung entfernt'), async () => {
							try {
								await api.assignTransaction(tx.id, prevContra)
								await this.loadTransactions(); await this.loadBalances(); await this.loadJournal(); await this.loadSphereReport()
							} catch (e) { showError(this.errMsg(e, this.t('Wiederherstellen fehlgeschlagen'))) }
						})
					}
				} else {
					await api.assignTransaction(tx.id, Number(value))
				}
				await this.loadTransactions(); await this.loadBalances(); await this.loadJournal(); await this.loadSphereReport()
			} catch (e) { showError(this.errMsg(e, this.t('Zuordnung fehlgeschlagen'))) }
		},
		async removeTransaction(tx) {
			if (!await this.askConfirm(this.t('Umsatz löschen'), this.t('Umsatz über {amount} von/an „{counterparty}" endgültig löschen?', { amount: formatMoney(tx.amount), counterparty: tx.counterparty || '' }))) return
			try {
				await api.deleteTransaction(tx.id)
				await this.loadTransactions(); await this.loadBalances()
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
		// --- Umsatz aufteilen (Zuordnen) ------------------------------------
		openSplitAssign(tx) {
			// Der Vorschlag (Regel/Verlauf) landet in der ersten Zeile: bei einer
			// Aufteilung stimmt er meist fuer den groessten Teil und muss dann nur
			// noch beziffert werden.
			const suggestion = this.suggestionsById[tx.id]
			this.splitAssign = {
				open: true,
				tx,
				parts: [
					{ accountId: suggestion ? suggestion.accountId : null, amount: null },
					{ accountId: null, amount: null },
				],
			}
		},
		closeSplitAssign() {
			this.splitAssign = { open: false, tx: null, parts: [] }
		},
		async saveSplitAssign() {
			const { tx, parts } = this.splitAssign
			if (!tx) return
			const rows = parts.filter(p => p.accountId || p.amount)
			if (rows.length < 2) { showError(this.t('Eine Aufteilung braucht mindestens zwei Zeilen.')); return }
			if (rows.some(p => !p.accountId)) { showError(this.t('Jeder Zeile der Aufteilung fehlt noch ein Konto.')); return }
			if (rows.some(p => !(Number(p.amount) > 0))) { showError(this.t('Jede Zeile der Aufteilung braucht einen Betrag größer als 0.')); return }
			const total = Math.abs(tx.amountCents || 0) / 100
			if (!splitBalanced(total, rows)) {
				const rest = splitRemainder(total, rows)
				showError(rest > 0
					? this.t('Die Aufteilung geht nicht auf – es fehlen noch {amount}.', { amount: formatMoney(rest) })
					: this.t('Die Aufteilung übersteigt den Umsatz um {amount}.', { amount: formatMoney(-rest) }))
				return
			}
			try {
				await api.assignTransactionParts(tx.id, rows.map(p => ({ accountId: p.accountId, amount: Number(p.amount) })))
				showSuccess(this.t('Umsatz aufgeteilt zugeordnet.'))
				this.closeSplitAssign()
				await this.loadTransactions(); await this.loadBalances(); await this.loadJournal(); await this.loadSphereReport()
			} catch (e) { showError(this.errMsg(e, this.t('Zuordnung fehlgeschlagen'))) }
		},
		// Vorschlag: passende Regel, sonst häufigste frühere Zuordnung desselben Zahlungspartners
		computeSuggestion(tx) {
			for (const rule of this.rules) {
				const haystack = { counterparty: tx.counterparty, purpose: tx.purpose, iban: tx.iban }[rule.matchField]
				if (haystack && rule.matchValue && haystack.toLowerCase().includes(rule.matchValue.toLowerCase())) {
					const acc = this.accountsById[rule.contraAccountId]
					if (acc && acc.active) return { accountId: acc.id, label: `${acc.number} ${acc.name}` }
				}
			}
			if (tx.counterparty) {
				const hist = this.assignmentHistory[tx.counterparty.trim().toLowerCase()]
				if (hist) {
					const best = Object.entries(hist).sort((a, b) => b[1] - a[1])[0]
					const acc = this.accountsById[Number(best[0])]
					if (acc && acc.active) return { accountId: acc.id, label: `${acc.number} ${acc.name}` }
				}
			}
			return null
		},
		applySuggestion(tx) {
			const s = this.suggestionsById[tx.id]
			if (s) this.onAssign(tx, s.accountId)
		},
		async createRuleFromTx(tx) {
			if (!tx.counterparty || !tx.contraAccountId) return
			const value = tx.counterparty.trim()
			const exists = this.rules.some(r => r.matchField === 'counterparty' && r.matchValue.toLowerCase() === value.toLowerCase())
			if (exists) { showSuccess(this.t('Für diesen Zahlungspartner existiert bereits eine Regel.')); return }
			try {
				await api.createRule({ matchField: 'counterparty', matchValue: value, contraAccountId: tx.contraAccountId })
				await this.loadRules()
				showSuccess(this.t('Regel angelegt: „{value}" wird künftig automatisch {account} zugeordnet.', { value, account: this.accountLabel(tx.contraAccountId) }))
			} catch (e) { showError(this.errMsg(e, this.t('Regel konnte nicht angelegt werden'))) }
		},

		// --- Journal ---
		// Eigener Wrapper um useJournal.loadJournal (journalLoad), da hier
		// zusätzlich die Beleg-Zähler nachgeladen werden.
		async loadJournal() {
			await this.journalLoad()
			this.loadAttachmentCounts()
		},
		async loadAttachmentCounts() {
			try { const { data } = await api.attachmentCounts(); this.attachmentCountMap = data } catch (e) { /* ignorieren */ }
		},
		async loadAttachments(journalId) {
			if (!journalId) { this.bookingAttachments = []; return }
			try { const { data } = await api.listAttachments(journalId); this.bookingAttachments = data } catch (e) { this.bookingAttachments = [] }
		},
		async uploadAttachment(event) {
			const files = event.target.files
			if (!files || !files.length || !this.bookingForm.id) return
			this.attachmentUploading = true
			try {
				for (const file of Array.from(files)) {
					const fd = new FormData()
					fd.append('file', file)
					await api.uploadAttachment(this.bookingForm.id, fd)
				}
				await this.loadAttachments(this.bookingForm.id)
				this.loadAttachmentCounts()
			} catch (e) { showError(this.errMsg(e, this.t('Upload fehlgeschlagen'))) } finally { this.attachmentUploading = false; event.target.value = '' }
		},
		async deleteAttachment(id) {
			if (!await this.askConfirm(this.t('Beleg löschen'), this.t('Diesen Beleg wirklich unwiderruflich löschen?'))) return
			try {
				await api.deleteAttachment(id)
				await this.loadAttachments(this.bookingForm.id)
				this.loadAttachmentCounts()
			} catch (e) { showError(this.errMsg(e, this.t('Beleg konnte nicht gelöscht werden'))) }
		},
		attachmentDownloadUrl(id) { return api.attachmentDownloadUrl(id) },
		openViewer(attachment) {
			if (attachment.ncPath && window.OCA?.Viewer) {
				OCA.Viewer.open({ path: attachment.ncPath })
			} else {
				window.open(api.attachmentViewUrl(attachment.id), '_blank')
			}
		},
		clickPaperclip(r) {
			// Buchungen ohne Bearbeiten-Modal (N:M-Splitt aus Fremddaten) →
			// Beleg direkt öffnen; ebenso, wenn es ohnehin nur einen gibt.
			if ((r.isSplit && !r.splitSide) || this.attachmentCountMap[r.id]?.count === 1) this.openQuickViewer(r)
			else this.editBooking(r)
		},
		async openQuickViewer(r) {
			try {
				const { data } = await api.listAttachments(r.id)
				if (data.length) this.openViewer(data[0])
			} catch (e) { this.editBooking(r) }
		},
		formatFileSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
		},
		openNewBooking() {
			this.bookingAttachments = []
			this.pendingFiles = []
			this.bookingForm = this.emptyBookingForm()
			this.bookingForm.moneyAccountId = this.defaultMoneyAccountId
			this.showBooking = true
			this.startBookingTour()
		},
		// --- Erste-Buchung-Tour: einmalige Feld-Hervorhebung im Einfach-Modus (Desktop) ---
		startBookingTour() {
			if (this.isMobile || this.bookingMode !== 'simple') return
			try { if (localStorage.getItem('vbh_booking_tour_seen') === '1') return } catch (e) { return }
			this.bookingTour = { active: true, step: 0 }
			try { localStorage.setItem('vbh_booking_tour_seen', '1') } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		nextTourStep() {
			if (this.bookingTour.step >= 2) { this.endTour(); return }
			this.bookingTour.step++
		},
		endTour() { this.bookingTour = { active: false, step: 0 } },
		// rowFlow ist jetzt Teil von DashboardTab.vue/BookingsTab.vue.
		// --- Mobil: Kontoauswahl-Sheet -------------------------------------
		openAccountPicker(target, tx = null) {
			const titles = {
				category: this.t('Kategorie wählen'),
				money: this.t('Geldkonto wählen'),
				debit: this.t('Sollkonto wählen'),
				credit: this.t('Habenkonto wählen'),
				assign: this.t('Konto / Kategorie zuordnen'),
			}
			// splitline:<index> waehlt das Konto einer Zeile der Aufteilung.
			const title = target.startsWith('splitline:') ? this.t('Konto der Aufteilung wählen') : (titles[target] || this.t('Konto wählen'))
			this.accountPicker = { open: true, target, title, tx }
		},
		closeAccountPicker() {
			this.accountPicker = { open: false, target: null, title: '', tx: null }
		},
		onAccountPicked(opt) {
			const p = this.accountPicker
			if (p.target === 'category') this.bookingForm.categoryId = opt.id
			else if (p.target === 'money') this.bookingForm.moneyAccountId = opt.id
			else if (p.target === 'debit') this.bookingForm.debitAccountId = opt.id
			else if (p.target === 'credit') this.bookingForm.creditAccountId = opt.id
			else if (p.target === 'assign' && p.tx) this.onAssign(p.tx, opt.id)
			else if (p.target && p.target.startsWith('splitline:')) {
				const i = Number(p.target.slice('splitline:'.length))
				const lines = this.splitAssign.open ? this.splitAssign.parts : this.bookingForm.splitLines
				if (lines[i]) lines[i].accountId = opt.id
			}
			this.pushRecentAccount(opt.id)
			this.closeAccountPicker()
		},
		onAccountPickerSuggest() {
			const p = this.accountPicker
			if (p.target === 'assign' && p.tx) {
				const s = this.suggestionsById[p.tx.id]
				if (s) this.pushRecentAccount(s.id)
				this.applySuggestion(p.tx)
			}
			this.closeAccountPicker()
		},
		loadRecentAccounts() {
			try {
				const list = JSON.parse(localStorage.getItem('vbh_recent_accounts') || '[]')
				this.recentAccountIds = Array.isArray(list) ? list : []
			} catch (e) { this.recentAccountIds = [] }
		},
		pushRecentAccount(id) {
			if (!id) return
			this.recentAccountIds = [id, ...this.recentAccountIds.filter(x => x !== id)].slice(0, 5)
			try { localStorage.setItem('vbh_recent_accounts', JSON.stringify(this.recentAccountIds)) } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		// statementRowNet ist jetzt Teil von AccountsTab.vue.
		// Mobil: Konten-Drilldown zurück zur Liste
		closeAccountDetail() {
			this.selectedAccountId = null
			this.statement = null
		},
		// Mobil: Belege beim Anlegen sammeln, Upload folgt nach dem Speichern
		addPendingFiles(event) {
			const files = event.target.files
			if (files && files.length) this.pendingFiles.push(...Array.from(files))
			event.target.value = ''
		},
		async uploadPendingFiles(journalId) {
			const files = this.pendingFiles
			this.pendingFiles = []
			if (!journalId || !files.length) return
			try {
				for (const file of files) {
					const fd = new FormData()
					fd.append('file', file)
					await api.uploadAttachment(journalId, fd)
				}
				this.loadAttachmentCounts()
			} catch (e) { showError(this.errMsg(e, this.t('Buchung gespeichert, aber der Beleg-Upload ist fehlgeschlagen'))) }
		},
		// Mobil: Tippen auf eine Buchungskarte
		openBookingCard(r) {
			if (this.canWrite) {
				this.editBooking(r)
				return
			}
			if (this.attachmentCountMap[r.id]) this.openQuickViewer(r)
		},
		// Mobil: Löschen aus dem Bearbeiten-Dialog (die Karten haben keinen
		// eigenen Löschen-Knopf; am Desktop bleibt der Knopf in der Zeile).
		async deleteBookingFromDialog() {
			const id = this.bookingForm.id
			const entryNo = this.bookingForm.entryNo
			if (!id) return
			if (!await this.askConfirm(this.t('Buchung löschen'), this.t('Buchung #{n} löschen?', { n: entryNo }))) return
			try {
				await api.deleteBooking(id)
				this.closeBooking()
				// Umsätze mitladen – siehe removeBooking().
				await this.loadJournal(); await this.loadTransactions(); await this.loadBalances()
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
		editBooking(r) {
			if (r.isSplit && !r.splitSide) {
				// Beide Seiten mehrzeilig: diese Form erzeugt die App nirgends
				// (siehe useJournal.js). Der Dialog bildet sie nicht ab und
				// wuerde sie beim Speichern verstuemmeln.
				showError(this.t('Diese Buchung hat auf beiden Seiten mehrere Konten – so eine Buchung kann die App nicht bearbeiten.'))
				return
			}
			this.bookingForm = { ...this.emptyBookingForm(), id: r.id, entryNo: r.entryNo, date: r.date, documentRef: r.documentRef || '', amount: r.amount, debitAccountId: r.debitAccountId, creditAccountId: r.creditAccountId, description: r.description || '', updatedAt: r.updatedAt || null }
			if (r.isSplit) {
				this.loadSplitIntoForm(r)
			} else {
				const m = this.mapToSimple(r.debitAccountId, r.creditAccountId)
				if (m) {
					Object.assign(this.bookingForm, m)
					this.bookingMode = 'simple'
				} else {
					this.bookingMode = 'expert'
				}
			}
			this.loadAttachments(r.id)
			this.showBooking = true
		},
		/**
		 * Uebernimmt eine bestehende Splittbuchung ins Formular: die einzelne
		 * Zeile wird zur festen Seite, die mehrzeilige zur Aufteilung.
		 */
		loadSplitIntoForm(r) {
			const side = r.splitSide // 'credit' = Habenseite ist aufgeteilt
			const fixed = (r.lines || []).find(l => (side === 'credit' ? l.debitCents : l.creditCents) > 0)
			const parts = (r.lines || []).filter(l => (side === 'credit' ? l.creditCents : l.debitCents) > 0)
			const f = this.bookingForm
			f.splitMode = true
			f.splitSide = side
			f.splitLines = parts.map(l => ({
				accountId: l.accountId,
				amount: (side === 'credit' ? l.creditCents : l.debitCents) / 100,
			}))
			// Der Einfach-Modus passt, wenn die feste Seite ein Geldkonto ist und
			// auf der Seite steht, die zur Buchungsart gehoert - sonst Experte.
			const fixedAccount = fixed ? this.accountsById[fixed.accountId] : null
			const isMoney = fixedAccount && (fixedAccount.isBank || fixedAccount.type === 'asset')
			if (isMoney) {
				this.bookingMode = 'simple'
				f.kind = side === 'credit' ? 'income' : 'expense'
				f.moneyAccountId = fixed.accountId
			} else {
				this.bookingMode = 'expert'
				if (side === 'credit') f.debitAccountId = fixed ? fixed.accountId : null
				else f.creditAccountId = fixed ? fixed.accountId : null
			}
		},
		closeBooking() { this.showBooking = false; this.bookingForm = this.emptyBookingForm(); this.bookingAttachments = []; this.pendingFiles = []; this.endTour() },
		/**
		 * Prueft das Formular und baut die Nutzlast der zweizeiligen Buchung.
		 * @return {object|null} null, wenn eine Meldung gezeigt wurde
		 */
		buildSimplePayload() {
			const f = this.bookingForm
			if (this.bookingMode === 'simple') {
				if (!f.date || !f.amount || !f.categoryId || !f.moneyAccountId) { showError(this.t('Datum, Betrag, Kategorie und Geldkonto sind Pflicht.')); return null }
				if (f.categoryId === f.moneyAccountId) { showError(this.t('Kategorie und Geldkonto müssen unterschiedlich sein.')); return null }
				const d = this.deriveSimpleAccounts()
				f.debitAccountId = d.debit
				f.creditAccountId = d.credit
			}
			if (!f.date || !f.debitAccountId || !f.creditAccountId || !f.amount) { showError(this.t('Datum, Soll, Haben und Betrag sind Pflicht.')); return null }
			if (f.debitAccountId === f.creditAccountId) { showError(this.t('Soll- und Habenkonto müssen unterschiedlich sein.')); return null }
			return { date: f.date, description: f.description, documentRef: f.documentRef || null, debitAccountId: f.debitAccountId, creditAccountId: f.creditAccountId, amount: Number(f.amount) }
		},
		/**
		 * Dasselbe fuer die Splittbuchung. Die Pruefungen hier sind die
		 * bedienfreundliche Vorstufe; verbindlich prueft
		 * JournalService::validateLines() im Backend.
		 * @return {object|null} null, wenn eine Meldung gezeigt wurde
		 */
		buildSplitPayload() {
			const f = this.bookingForm
			if (!f.date || !f.amount) { showError(this.t('Datum und Gesamtbetrag sind Pflicht.')); return null }
			if (!this.splitFixedAccountId()) {
				showError(this.bookingMode === 'simple' ? this.t('Das Geldkonto fehlt.') : this.t('Das Konto der festen Seite fehlt.'))
				return null
			}
			const rows = (f.splitLines || []).filter(l => l.accountId || l.amount)
			if (rows.length < 2) { showError(this.t('Eine Aufteilung braucht mindestens zwei Zeilen.')); return null }
			if (rows.some(l => !l.accountId)) { showError(this.t('Jeder Zeile der Aufteilung fehlt noch ein Konto.')); return null }
			if (rows.some(l => !(Number(l.amount) > 0))) { showError(this.t('Jede Zeile der Aufteilung braucht einen Betrag größer als 0.')); return null }
			const rest = splitRemainder(f.amount, rows)
			if (!splitBalanced(f.amount, rows)) {
				showError(rest > 0
					? this.t('Die Aufteilung geht nicht auf – es fehlen noch {amount}.', { amount: formatMoney(rest) })
					: this.t('Die Aufteilung übersteigt den Gesamtbetrag um {amount}.', { amount: formatMoney(-rest) }))
				return null
			}
			f.splitLines = rows
			return {
				date: f.date,
				description: f.description,
				documentRef: f.documentRef || null,
				lines: this.buildSplitPayloadLines(),
			}
		},
		async saveBooking() {
			const f = this.bookingForm
			const payload = f.splitMode ? this.buildSplitPayload() : this.buildSimplePayload()
			if (!payload) return
			try {
				if (f.id) {
					await api.updateBooking(f.id, { ...payload, updatedAt: f.updatedAt || null })
				} else {
					const { data } = await api.createBooking(payload)
					await this.uploadPendingFiles(data && data.id)
				}
				showSuccess(this.t('Buchung gespeichert.'))
				this.closeBooking()
				await this.loadJournal(); await this.loadBalances(); await this.loadYears(); await this.loadSphereReport()
			} catch (e) {
				if (e?.response?.status === 409) {
					showError(this.t('Diese Buchung wurde zwischenzeitlich von einer anderen Person geändert. Die Ansicht wurde aktualisiert – bitte erneut bearbeiten.'))
					this.closeBooking()
					await this.loadJournal(); await this.loadBalances()
					return
				}
				showError(this.errMsg(e, this.t('Buchung konnte nicht gespeichert werden')))
			}
		},
		async removeBooking(r) {
			if (!await this.askConfirm(this.t('Buchung löschen'), this.t('Buchung #{n} löschen?', { n: r.entryNo }))) return
			// loadTransactions() muss mit: stammte die Buchung aus einem Bankumsatz,
			// steht dieser jetzt wieder unter „Zuzuordnen" (siehe
			// JournalService::releaseBankTransaction()). Ohne das Nachladen bliebe
			// die Liste samt Zähler bis zum nächsten Neuladen veraltet.
			try { await api.deleteBooking(r.id); await this.loadJournal(); await this.loadTransactions(); await this.loadBalances(); await this.loadSphereReport() } catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},

		// --- Konten ---
		// Eigene Wrapper um useAccounts (accountsLoad/accountsSeedDefaults), da hier
		// zusätzlich das lokale openingForm nachgezogen bzw. eine Erfolgsmeldung gezeigt wird.
		async loadAccounts() {
			const data = await this.accountsLoad()
			if (!data) return
			const form = {}
			for (const acc of data) form[acc.id] = { amount: acc.openingBalance || 0, date: acc.openingDate || '' }
			this.openingForm = form
		},
		async seedAccounts() {
			try {
				await this.accountsSeedDefaults()
				await this.loadAccounts()
				showSuccess(this.t('Standard-Kontenrahmen angelegt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Anlegen fehlgeschlagen'))) }
		},
		openNewAccount() {
			this.accountEditId = null
			const parent = this.selectedAccount
			this.newAccount = {
				number: '',
				name: '',
				type: parent ? parent.type : 'income',
				category: parent ? (parent.category || '') : '',
				isBank: false,
				parentId: this.selectedAccountId || null,
				sphere: parent ? (parent.sphere || '') : '',
				reserveKind: parent ? (parent.reserveKind || '') : '',
				// IBAN wird bewusst NICHT vom Ueberkonto uebernommen: sie
				// identifiziert genau ein Bankkonto und darf nicht an zwei
				// Konten haengen.
				iban: '',
				// Kostenstelle dagegen schon: ein Unterkonto gehoert in aller
				// Regel zum selben Projekt wie sein Ueberkonto.
				costCenterId: parent ? (parent.costCenterId || null) : null,
			}
			this.showAccount = true
		},
		openEditAccount(acc) {
			this.accountEditId = acc.id
			this.newAccount = {
				number: acc.number,
				name: acc.name,
				type: acc.type,
				category: acc.category || '',
				isBank: !!acc.isBank,
				parentId: acc.parentId || null,
				sphere: acc.sphere || '',
				reserveKind: acc.reserveKind || '',
				iban: acc.iban || '',
				costCenterId: acc.costCenterId || null,
				// Altbestand ohne gesetztes Feld gilt als aktiv.
				active: acc.active !== false,
			}
			this.showAccount = true
		},
		closeAccount() { this.showAccount = false; this.accountEditId = null },
		// f kommt jetzt als @save-Payload von AccountDialog.vue (eigene lokale
		// Formularkopie dort, kein direktes Mutieren von this.newAccount mehr).
		async saveAccount(f) {
			if (!f.number || !f.name) { showError(this.t('Nummer und Bezeichnung sind Pflicht.')); return }
			try {
				if (this.accountEditId) {
					await api.updateAccount(this.accountEditId, {
						number: f.number,
						name: f.name,
						type: f.type,
						category: f.category || null,
						isBank: f.isBank,
						parentId: f.parentId || 0,
						sphere: f.sphere || '',
						reserveKind: f.reserveKind || '',
						// Leerstring statt null: der Controller verwirft null-Werte,
						// eine geleerte IBAN wuerde sonst nicht geloescht.
						iban: f.iban || '',
						// 0 statt null aus demselben Grund: null hiesse
						// "unveraendert", 0 loest die Zuordnung.
						costCenterId: f.costCenterId || 0,
						active: !!f.active,
					})
				} else {
					// active bleibt hier aussen vor: ein neu angelegtes Konto ist
					// immer aktiv, der Schalter erscheint erst beim Bearbeiten.
					const { active, ...rest } = f
					await api.createAccount({ ...rest, parentId: f.parentId || null, sphere: f.sphere || null, reserveKind: f.reserveKind || null, iban: f.iban || null, costCenterId: f.costCenterId || null })
				}
				this.showAccount = false
				this.accountEditId = null
				this.newAccount = { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null, sphere: '', reserveKind: '', iban: '', costCenterId: null }
				await this.loadAccounts(); await this.loadBalances(); await this.loadSphereReport()
				showSuccess(this.t('Konto gespeichert.'))
			} catch (e) { showError(this.errMsg(e, this.t('Konto konnte nicht gespeichert werden'))) }
		},
		async deleteAccount(acc) {
			if (!await this.askConfirm(this.t('Konto löschen'), this.t('Konto "{number} {name}" löschen?', { number: acc.number, name: acc.name }))) return
			try {
				await api.deleteAccount(acc.id)
				if (this.selectedAccountId === acc.id) { this.selectedAccountId = null; this.statement = null }
				await this.loadAccounts(); await this.loadBalances(); await this.loadSphereReport()
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
		async saveOpening(acc) {
			const form = this.openingForm[acc.id] || { amount: 0, date: '' }
			try {
				await api.setOpening(acc.id, Number(form.amount) || 0, form.date || null)
				await this.loadAccounts(); await this.loadBalances(); await this.loadSphereReport()
				if (this.selectedAccountId === acc.id) await this.loadStatement(acc.id)
				showSuccess(this.t('Eröffnungssaldo für {name} gespeichert.', { name: acc.name }))
			} catch (e) { showError(this.errMsg(e, this.t('Eröffnungssaldo konnte nicht gespeichert werden'))) }
		},

		// --- Auswertung ---
		// loadBalances kommt aus setup() (useBalances).

		// --- Berichte / Kostenstellen ---
		async loadReport() {
			try {
				const { data } = await api.costCenterReport(this.selectedYear)
				this.reportData = data
				if (this.selectedCCCode !== false && !data.costCenters.some(c => c.code === this.selectedCCCode)) this.selectedCCCode = false
			} catch (e) { showError(this.errMsg(e, this.t('Bericht konnte nicht geladen werden'))) }
		},
		selectCC(cc) { this.selectedCCCode = cc.code; this.renameName = cc.name; this.ccExpanded = {} },
		isCCSelected(cc) { return this.selectedCCCode !== false && cc.code === this.selectedCCCode },
		// Eigener Wrapper um useBalances.loadSphereReport (balancesLoadSphereReport),
		// da hier zusätzlich die lokale Sphären-Auswahl (Reports-Tab) zurückgesetzt wird.
		async loadSphereReport() {
			const data = await this.balancesLoadSphereReport()
			if (data && this.selectedSphereCode !== false && !data.spheres.some(s => s.code === this.selectedSphereCode)) this.selectedSphereCode = false
		},
		selectSphere(s) { this.selectedSphereCode = s.code },
		isSphereSelected(s) { return this.selectedSphereCode !== false && s.code === this.selectedSphereCode },
		async toggleCCAccount(accountId) {
			if (!accountId) return
			const open = !this.ccExpanded[accountId]
			this.$set(this.ccExpanded, accountId, open)
			if (open && !this.ccBookings[accountId]) {
				try { const { data } = await api.accountJournal(accountId, false, this.selectedYear); this.$set(this.ccBookings, accountId, data.rows) } catch (e) { showError(this.errMsg(e, this.t('Buchungen konnten nicht geladen werden'))) }
			}
		},
		async saveRename() {
			const cc = this.selectedCC
			if (!cc || !cc.code) return
			try { await api.renameCostCenter(cc.code, this.renameName); await this.loadReport(); showSuccess(this.t('Kostenstelle umbenannt.')) } catch (e) { showError(this.errMsg(e, this.t('Umbenennen fehlgeschlagen'))) }
		},

		// --- Finanzplan / Budget ---
		async loadBudget() {
			try {
				const { data } = await api.budget(this.selectedYear)
				this.budgetData = data
				await this.loadBudgetSnapshots()
			} catch (e) { showError(this.errMsg(e, this.t('Finanzplan konnte nicht geladen werden'))) }
		},
		// saveBudget/toggleBudgetNote/saveBudgetSnapshot/deleteBudgetSnapshot/
		// addBudgetYear sind jetzt Teil von ReportsTab.vue.

		// --- Finanzplan-Stände (Snapshots) ---
		async loadBudgetSnapshots() {
			try {
				const { data } = await api.budgetSnapshots(this.selectedYear)
				this.budgetSnapshots = data
			} catch (e) { showError(this.errMsg(e, this.t('Plan-Stände konnten nicht geladen werden'))) }
		},
		async openSnapshot(snap) {
			try {
				const { data } = await api.budgetSnapshot(snap.id)
				this.snapshotView = { open: true, data }
			} catch (e) { showError(this.errMsg(e, this.t('Plan-Stand konnte nicht geladen werden'))) }
		},
		closeSnapshot() { this.snapshotView = { open: false, data: null } },
		/**
		 * Planwert eines Kontos im aktuellen Plan (für Vergleich im Stand-Detail).
		 * @param accountId
		 */
		currentPlanForAccount(accountId) {
			const row = this.budgetData && this.budgetData.rows.find(r => r.accountId === accountId)
			return row ? row.plan : 0
		},

		// --- Berechtigungen ---
		async loadMe() {
			const data = await this.authLoadMe()
			if (!this.visibleTabs.some(t => t.id === this.activeTab)) {
				this.activeTab = this.visibleTabs.length ? this.visibleTabs[0].id : 'dashboard'
			}
			if (data.role === 'revisor') {
				try { this.revisorIntroDismissed = localStorage.getItem('vbh_revisor_intro_dismissed') === '1' } catch (e) { this.revisorIntroDismissed = false }
			}
		},
		dismissRevisorIntro() {
			this.revisorIntroDismissed = true
			try { localStorage.setItem('vbh_revisor_intro_dismissed', '1') } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		// --- Hilfe-Modal --------------------------------------------------
		openHelp(topic = null) {
			this.helpForcedTopic = topic
			this.showHelp = true
		},
		closeHelp() {
			this.showHelp = false
			this.helpForcedTopic = null
		},
		// --- Setup-Checkliste: Sprung zur jeweiligen Aktion ----------------
		onSetupNavigate(action) {
			if (action === 'accounts') this.activeTab = 'accounts'
			else if (action === 'settings') this.openSettings()
			else if (action === 'booking') this.openNewBooking()
		},
		errMsg,
		// Chart-Rendering (Monatschart) ist jetzt Teil von DashboardTab.vue.
	},
}
</script>

<style scoped>
/* Nur noch Regeln, die per ::v-deep in NcButton-Internas eingreifen und daher
   scoped bleiben MUESSEN, damit sie nicht in Nextclouds eigene .button-vue
   (Header/Sidebar) lecken. Alle .vbh-*-Utilities liegen global in styles.css. */
::v-deep .button-vue { display: inline-flex !important; }
::v-deep .button-vue__icon { display: flex !important; align-items: center; justify-content: center; }
::v-deep .button-vue__icon svg { display: block !important; }
</style>
