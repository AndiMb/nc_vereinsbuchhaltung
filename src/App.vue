<template>
	<div class="vbh">
		<header class="vbh-header">
			<div class="vbh-titlebar">
				<h2>Vereinsbuchhaltung</h2>
				<div
					v-if="cashTotal && !isMobile"
					class="vbh-bankchip"
					:class="{ warn: Math.abs(cashTotal.open) > 0.005 }"
					:title="cashTotalTitle">
					<span class="vbh-bankchip-label">{{ cashTotal.label }}</span>
					<span class="vbh-bankchip-value">{{ formatMoney(cashTotal.balance) }}</span>
					<span v-if="Math.abs(cashTotal.open) > 0.005" class="vbh-bankchip-hint">{{ t('{amount} offen', { amount: formatMoney(cashTotal.open) }) }}</span>
				</div>
				<NcLoadingIcon v-if="busy" :size="24" :name="t('Wird geladen…')" />
			</div>
			<div v-if="canRead" class="vbh-navbar" :class="{ 'vbh-navbar--mobile': isMobile }">
				<nav v-if="!isMobile" class="vbh-tabs">
					<button
						v-for="tab in visibleTabs"
						:key="tab.id"
						:class="{ active: activeTab === tab.id }"
						@click="activeTab = tab.id">
						<NcIconSvgWrapper :path="tab.icon" :size="18" inline />
						{{ tab.label }}
						<span v-if="tab.id === 'bookings' && unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
						<span v-if="tab.id === 'contributions' && overdueMembershipCount > 0" class="vbh-badge vbh-badge--alert">{{ overdueMembershipCount }}</span>
					</button>
				</nav>
				<div class="vbh-navright">
					<NcButton
						v-if="canWrite && !isMobile"
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
					<NcButton
						variant="tertiary"
						:aria-label="t('Hilfe')"
						:title="t('Hilfe')"
						@click="openHelp()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiHelpCircleOutline" :size="20" />
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
				<a
					:href="pruefleitfadenUrl"
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
				<DashboardTab
					:isActive="activeTab === 'dashboard'"
					:isMobile="isMobile"
					:busy="busy"
					:clubName="clubName"
					:attachmentCountMap="attachmentCountMap"
					:recentJournal="recentJournal"
					:clickPaperclip="clickPaperclip"
					:openBookingCard="openBookingCard"
					@navigate="onSetupNavigate"
					@openWizard="showSetupWizard = true"
					@help="openHelp"
					@goUnassigned="goToUnassigned"
					@goOpenItems="goToOpenItems"
					@showAllBookings="activeTab = 'bookings'" />
			</section>

			<!-- ============ BUCHUNGEN (JOURNAL + TRANSAKTIONEN) ============ -->
			<section v-show="activeTab === 'bookings'" class="vbh-section vbh-flex-col" :class="{ 'vbh-fadein': sectionFade }">
				<BookingsTab
					:isMobile="isMobile"
					:bookingView="bookingView"
					:attachmentCountMap="attachmentCountMap"
					:suggestionsById="suggestionsById"
					:openImport="openImport"
					:clickPaperclip="clickPaperclip"
					:openBookingCard="openBookingCard"
					:editBooking="editBooking"
					:createRuleFromTx="createRuleFromTx"
					:removeBooking="removeBooking"
					:removeTransaction="removeTransaction"
					:openAccountPicker="openAccountPicker"
					:onAssign="onAssign"
					:openSplitAssign="openSplitAssign"
					:applySuggestion="applySuggestion"
					@update:bookingView="bookingView = $event"
					@help="openHelp('bookings')" />
			</section>

			<!-- ============ KONTEN ============ -->
			<section v-show="activeTab === 'accounts'" class="vbh-section split" :class="{ 'vbh-fadein': sectionFade, 'vbh-drill': isMobile }">
				<AccountsTab
					v-model:statementIncludeChildren="statementIncludeChildren"
					v-model:openingForm="openingForm"
					:isMobile="isMobile"
					:selectedAccountId="selectedAccountId"
					:statement="statement"
					:selectAccount="selectAccount"
					:closeAccountDetail="closeAccountDetail"
					:reloadStatement="reloadStatement"
					:reassignBooking="reassignBooking"
					:openNewAccount="openNewAccount"
					:openEditAccount="openEditAccount"
					:deleteAccount="deleteAccount"
					:saveOpening="saveOpening"
					:seedAccounts="seedAccounts"
					@help="openHelp('accounts')" />
			</section>

			<!-- ============ BERICHTE (AUSWERTUNG + KOSTENSTELLEN + FINANZPLAN) ============ -->
			<section v-show="activeTab === 'reports'" class="vbh-section vbh-flex-col" :class="{ 'vbh-fadein': sectionFade }">
				<ReportsTab
					:isActive="activeTab === 'reports'"
					:isMobile="isMobile"
					:reportView="reportView"
					:reportData="reportData"
					:budgetData="budgetData"
					:budgetSnapshots="budgetSnapshots"
					:auditEntries="auditEntries"
					:auditLoading="auditLoading"
					:auditEnd="auditEnd"
					:selectedCCCode="selectedCCCode"
					:selectedSphereCode="selectedSphereCode"
					:ccExpanded="ccExpanded"
					:ccBookings="ccBookings"
					:renameName="renameName"
					:selectCC="selectCC"
					:isCCSelected="isCCSelected"
					:toggleCCAccount="toggleCCAccount"
					:saveRename="saveRename"
					:selectSphere="selectSphere"
					:isSphereSelected="isSphereSelected"
					:loadAudit="loadAudit"
					:openSnapshot="openSnapshot"
					:costCenterMode="costCenterMode"
					:saveCostCenterMode="saveCostCenterMode"
					@update:reportView="reportView = $event"
					@update:selectedCCCode="selectedCCCode = $event"
					@update:selectedSphereCode="selectedSphereCode = $event"
					@update:renameName="renameName = $event"
					@update:costCenterMode="costCenterMode = $event"
					@costCentersChanged="onCostCentersChanged"
					@spheresChanged="onSpheresChanged"
					@help="openHelp"
					@budgetChanged="loadBudget"
					@snapshotsChanged="loadBudgetSnapshots" />
			</section>

			<!-- ============ BEITRÄGE (MITGLIEDER + SEPA-SAMMELEINZUG) ============ -->
			<section
				v-if="canWrite && membershipActive"
				v-show="activeTab === 'contributions'"
				class="vbh-section vbh-flex-col"
				:class="{ 'vbh-fadein': sectionFade }">
				<ContributionsTab
					:contribView="contribView"
					:isMobile="isMobile"
					:defaultFeeAmount="defaultFeeAmount"
					:defaultFeeFrequency="defaultFeeFrequency"
					@update:contribView="contribView = $event" />
			</section>
		</main>

		<MobileNav
			v-if="canRead && isMobile"
			:tabs="visibleTabs"
			:activeTab="activeTab"
			:unassignedCount="unassignedCount"
			:overdueMembershipCount="overdueMembershipCount"
			:canWrite="canWrite"
			@select="id => { activeTab = id }"
			@newBooking="openNewBooking" />

		<!-- ============ IMPORT-DIALOG (CSV-CAMT) ============ -->
		<ImportDialog
			v-model:busy="busy"
			:show="showImport"
			@update:show="showImport = $event"
			@close="closeImport"
			@goAssign="goAssignAfterImport"
			@imported="onImported" />

		<!-- ============ BUCHUNGS-DIALOG ============ -->
		<BookingDialog
			v-model:bookingForm="bookingForm"
			v-model:pendingFiles="pendingFiles"
			:show="showBooking"
			:bookingMode="bookingMode"
			:bookingLocked="bookingLocked"
			:bookingSaving="bookingSaving"
			:bookingTour="bookingTour"
			:isMobile="isMobile"
			:canWrite="canWrite"
			:bookingAttachments="bookingAttachments"
			:attachmentUploading="attachmentUploading"
			:setBookingKind="setBookingKind"
			:setBookingMode="setBookingMode"
			:openAccountPicker="openAccountPicker"
			:addPendingFiles="addPendingFiles"
			:retryPendingFiles="retryPendingFiles"
			:uploadAttachment="uploadAttachment"
			:deleteAttachment="deleteAttachment"
			:openViewer="openViewer"
			:attachmentDownloadUrl="attachmentDownloadUrl"
			:nextTourStep="nextTourStep"
			:endTour="endTour"
			@update:show="showBooking = $event"
			@close="closeBooking"
			@save="saveBooking"
			@delete="deleteBookingFromDialog" />

		<!-- ============ UMSATZ AUFTEILEN (ZUORDNEN) ============ -->
		<SplitAssignDialog
			:show="splitAssign.open"
			:tx="splitAssign.tx"
			:parts="splitAssign.parts"
			:isMobile="isMobile"
			:openAccountPicker="openAccountPicker"
			@update:show="splitAssign.open = $event"
			@update:parts="splitAssign.parts = $event"
			@close="closeSplitAssign"
			@save="saveSplitAssign" />

		<!-- ============ KONTO-DIALOG ============ -->
		<AccountDialog
			:show="showAccount"
			:accountEditId="accountEditId"
			:initialForm="newAccount"
			:costCenterMode="costCenterMode"
			@update:show="showAccount = $event"
			@close="closeAccount"
			@save="saveAccount"
			@help="openHelp('spheres')" />

		<!-- ============ PLAN-STAND DETAIL ============ -->
		<BudgetSnapshotModal
			:show="snapshotView.open"
			:snapshot="snapshotView.data"
			:currentPlanForAccount="currentPlanForAccount"
			@update:show="snapshotView.open = $event"
			@close="closeSnapshot" />

		<!-- ============ BESTÄTIGUNGS-DIALOG ============ -->
		<!-- ============ KONTOAUSWAHL-SHEET (mobil) ============ -->
		<AccountPickerSheet
			:open="accountPicker.open"
			:title="accountPicker.title"
			:options="accountPickerOptions"
			:recent="recentAccountOptions"
			:suggestion="accountPickerSuggestion"
			:currentId="accountPickerCurrentId"
			@close="closeAccountPicker"
			@pick="onAccountPicked"
			@suggest="onAccountPickerSuggest" />

		<!-- ============ HILFE ============ -->
		<HelpModal
			:show="showHelp"
			:topic="helpTopic"
			:currentVersion="whatsNewCurrentVersion"
			@close="closeHelp"
			@update:show="showHelp = $event"
			@openWhatsNew="openWhatsNewUnfiltered" />

		<!-- ============ SETUP-ASSISTENT (erster Verwalter-Login) ============ -->
		<SetupWizard
			:show="showSetupWizard"
			@close="closeSetupWizard"
			@update:show="showSetupWizard = $event"
			@choose="onWizardChoice" />

		<!-- ============ WAS IST NEU (Splash-Screen nach Updates) ============ -->
		<WhatsNewDialog
			:show="whatsNewShow"
			:entries="whatsNewEntries"
			@close="whatsNewShow = false"
			@update:show="whatsNewShow = $event"
			@dismiss="dismissWhatsNew" />

		<!-- Die Rueckfrage vor nicht umkehrbaren Aktionen. Sie steht hier, weil
			es genau eine geben soll; ausgeloest wird sie ueber useConfirm() aus
			jeder Komponente heraus. -->
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
import { mdiAccountCashOutline, mdiChartBar, mdiFileTreeOutline, mdiHelpCircleOutline, mdiPlus, mdiPrinter, mdiSwapHorizontal, mdiViewDashboardOutline } from '@mdi/js'
import { showError, showInfo, showSuccess, showUndo } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcIconSvgWrapper,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { toRefs } from 'vue'
import AccountDialog from './components/AccountDialog.vue'
import AccountPickerSheet from './components/AccountPickerSheet.vue'
import AccountsTab from './components/AccountsTab.vue'
import BookingDialog from './components/BookingDialog.vue'
import BookingsTab from './components/BookingsTab.vue'
import BudgetSnapshotModal from './components/BudgetSnapshotModal.vue'
import ContributionsTab from './components/ContributionsTab.vue'
import DashboardTab from './components/DashboardTab.vue'
import HelpModal from './components/HelpModal.vue'
import ImportDialog from './components/ImportDialog.vue'
import MobileNav from './components/MobileNav.vue'
import ReportsTab from './components/ReportsTab.vue'
import SetupWizard from './components/SetupWizard.vue'
import SplitAssignDialog from './components/SplitAssignDialog.vue'
import WhatsNewDialog from './components/WhatsNewDialog.vue'
import api from './api.js'
import { useAccounts } from './composables/useAccounts.js'
import { useAuth } from './composables/useAuth.js'
import { useBalances } from './composables/useBalances.js'
import { useConfirm } from './composables/useConfirm.js'
import { useCostCenters } from './composables/useCostCenters.js'
import { useJournal } from './composables/useJournal.js'
import { useMembershipFees } from './composables/useMembershipFees.js'
import { useOpenItems } from './composables/useOpenItems.js'
import { usePermissions } from './composables/usePermissions.js'
import { useRules } from './composables/useRules.js'
import { useSepaBatches } from './composables/useSepaBatches.js'
import { useSepaMandates } from './composables/useSepaMandates.js'
import { useSort } from './composables/useSort.js'
import { useSync } from './composables/useSync.js'
import { useYears } from './composables/useYears.js'
import { buildWhatsNewEntries, filterWhatsNewEntries } from './data/whatsNew.js'
import { amountClass, budgetDiffClass, errMsg, formatDate, formatDateTime, formatMoney, typeLabel } from './lib/format.js'
import { splitBalanced, splitRemainder, splitSideOf } from './lib/split.js'

// Abstand zwischen zwei Abgleichen mit dem Server. Die Frist, in der eine
// erkannte Änderung noch als *eigene* gilt, haengt daran (siehe checkRevision):
// war sie kuerzer als dieser Abstand, meldete die App die eigene Buchung als
// "von einer anderen Person geaendert" - je nachdem, wann der Abgleich fiel.
const SYNC_INTERVALL = 20000

// Belege: Grenzen aus AttachmentController (MAX_SIZE, ALLOWED_MIMES). Die
// Oberflaeche prueft sie schon bei der Auswahl - beim Anlegen einer Buchung
// laedt der Beleg erst nach dem Speichern hoch, ein Server-Nein kaeme also
// erst, wenn die Buchung bereits steht.
const BELEG_MAX_BYTES = 20 * 1024 * 1024
const BELEG_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']

/** Erkennungsmerkmal einer Datei, um dieselbe Auswahl nicht doppelt zu sammeln. */
const dateiSchluessel = (f) => `${f.name}|${f.size}|${f.lastModified}`

export default {
	name: 'App',
	components: {
		NcButton,
		NcDialog,
		NcIconSvgWrapper,
		NcLoadingIcon,
		ImportDialog,
		AccountDialog,
		BookingDialog,
		SplitAssignDialog,
		BudgetSnapshotModal,
		DashboardTab,
		AccountsTab,
		BookingsTab,
		ReportsTab,
		ContributionsTab,
		MobileNav,
		AccountPickerSheet,
		HelpModal,
		SetupWizard,
		WhatsNewDialog,
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
		const rulesC = useRules()
		const membershipFees = useMembershipFees()
		const sepaMandates = useSepaMandates()
		const sepaBatches = useSepaBatches()
		return {
			loadOpenItems: openItems.loadOpenItems,
			loadCostCenters: costCenters.loadCostCenters,
			// Regeln: geteilter Zustand (useRules.js), seit dem Umzug der Pflege
			// nach RulesPanel.vue (Unterreiter „Regeln" von BookingsTab.vue) hier
			// nur noch fuer computeSuggestion()/createRuleFromTx() gebraucht.
			...toRefs(rulesC.state),
			loadRules: rulesC.loadRules,
			// Reiter „Beiträge" (ContributionsTab.vue): MembersList.vue/
			// SepaBatchPanel.vue laden ihre Daten selbst beim eigenen mounted(),
			// hier nur die Kennzahl fuer den Reiter-Badge und die Nachlade-
			// Funktionen fuer refreshAfterRemoteChange() (siehe dort).
			overdueMembershipCount: membershipFees.overdueCount,
			loadMembershipFees: membershipFees.loadMembershipFees,
			loadSepaMandates: sepaMandates.loadSepaMandates,
			loadSepaBatches: sepaBatches.loadSepaBatches,
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
				// Nur sichtbar, wenn das Beitragsmodul genutzt wird (visibleTabs
				// unten) - fuer Verwalter und Buchhalter (siehe ContributionsTab.vue).
				{ id: 'contributions', label: this.t('Beiträge'), need: 'write', icon: mdiAccountCashOutline },
			],

			bookingView: 'journal',
			reportView: 'summary',
			contribView: 'members',
			budgetData: null,
			budgetSnapshots: [],
			snapshotView: { open: false, data: null },
			busy: false,
			reportData: null,
			selectedCCCode: false,
			selectedSphereCode: false,
			renameName: '',
			ccExpanded: {},
			ccBookings: {},
			newAccount: { number: '', name: '', type: 'income', category: '', isBank: false, countInTotal: true, parentId: null, sphere: '' },
			accountEditId: null,
			openingForm: {},
			selectedAccountId: null,
			statement: null,
			statementIncludeChildren: true,
			showBooking: false,
			// Laeuft ein Speichern (inkl. Beleg-Upload danach), bleibt der Dialog
			// gesperrt: ein zweiter Klick auf "Buchen" wuerde sonst eine zweite
			// Buchung anlegen, weil bookingForm.id noch leer ist.
			bookingSaving: false,
			showAccount: false,
			bookingMode: 'simple',
			showImport: false,
			sectionFade: true,
			bookingForm: this.emptyBookingForm(),
			mdiPlus,
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
			costCenterMode: 'group',
			clubName: '',
			// Vorbelegung fuer "Mitglied aufnehmen" (MemberDialog.vue), wenn fast
			// alle Mitglieder denselben Beitrag zahlen - leerer String heisst
			// "kein Standardbeitrag hinterlegt".
			defaultFeeAmount: '',
			defaultFeeFrequency: 'yearly',
			// membershipActive kommt vom Backend (Schalter in den Nextcloud-
			// Einstellungen ODER bereits vorhandene Mandate/Beitraege, siehe
			// SettingsController::index()) und entscheidet, ob der Reiter
			// „Beiträge" ueberhaupt erscheint.
			membershipActive: false,
			// Hilfe-Modal (HelpModal.vue): Kapitel folgt standardmäßig dem aktiven Tab,
			// kann aber gezielt überschrieben werden (z. B. Links aus Leerzuständen).
			showHelp: false,
			helpForcedTopic: null,
			// Einmaliger Willkommenshinweis für die Rolle „Revisor" (localStorage, dauerhaft ausblendbar)
			revisorIntroDismissed: true,
			// Geführter Setup-Assistent (SetupWizard.vue) beim allerersten Verwalter-Login
			showSetupWizard: false,
			// "Was ist neu"-Splash (WhatsNewDialog.vue): einmalig nach einem Update,
			// oder jederzeit ungefiltert über den Hilfe-Link erneut aufrufbar.
			whatsNewShow: false,
			whatsNewUnfiltered: false,
			whatsNewCurrentVersion: '',
			whatsNewLastSeenVersion: '',
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
		// Die Eintraege des "Was ist neu"-Splash. Bewusst hier statt im Dialog:
		// dieselbe Liste beantwortet die Frage "ueberhaupt etwas Neues?" (Gate
		// in loadWhatsNew) und "was steht drin?" (Anzeige). Zwei Aufrufe mit
		// denselben Eingaben koennten auseinanderlaufen, einer nicht.
		whatsNewEntries() {
			return filterWhatsNewEntries(
				buildWhatsNewEntries(),
				this.me && this.me.role,
				// Ueber den Hilfe-Link ungefiltert: alle Eintraege der Rolle,
				// unabhaengig vom zuletzt gesehenen Stand.
				this.whatsNewUnfiltered ? '' : this.whatsNewLastSeenVersion,
				this.whatsNewCurrentVersion,
			)
		},

		visibleTabs() {
			return this.allTabs.filter((t) => {
				// Eigenes Zusatzmodul: ohne genutzte Beitragsverwaltung kein fuenfter
				// Reiter, siehe NAVIGATION-KONZEPT.md Abschnitt 4.
				if (t.id === 'contributions' && !this.membershipActive) { return false }
				if (t.need === 'admin') { return this.isAdmin }
				if (t.need === 'write') { return this.canWrite }
				return this.canRead
			})
		},

		// Hilfe-Kapitel, das zum gerade aktiven Tab passt (HelpModal-Default)
		helpTopic() {
			if (this.helpForcedTopic) { return this.helpForcedTopic }
			const map = { dashboard: 'setup', bookings: 'bookings', accounts: 'accounts', reports: 'reports', contributions: 'sepa' }
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
				if (!acc.active) { continue }
				const cat = acc.category || this.t('Sonstige')
				if (!groups[cat]) { groups[cat] = [] }
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
				.filter((a) => a.active && counts[a.id])
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
				.filter((a) => a.active && a.type === type)
				.sort((a, b) => (counts[b.id] || 0) - (counts[a.id] || 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map((a) => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},

		moneyAccountOptions() {
			return this.accounts
				.filter((a) => a.active && (a.isBank || a.type === 'asset'))
				.sort((a, b) => (b.isBank ? 1 : 0) - (a.isBank ? 1 : 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map((a) => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},

		defaultMoneyAccountId() {
			// Nur echte Geldkonten automatisch vorauswählen – sonst könnte z.B. ein
			// Durchlaufkonto unbemerkt zum Standard-Geldkonto werden.
			const bank = this.accounts.find((a) => a.active && a.isBank)
			return bank ? bank.id : null
		},

		// bookingFormCategoryOption/MoneyOption/DebitOption/CreditOption und
		// bookingModeExpert sind jetzt Teil von BookingDialog.vue.
		// Kontoauswahl-Sheet (mobil): Optionen/Vorschlag/Auswahl je nach Ziel
		accountPickerOptions() {
			const t = this.accountPicker.target
			if (t === 'category') { return this.simpleCategoryOptions }
			if (t === 'money') { return this.moneyAccountOptions }
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
				if (a && a.active) { out.push({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }) }
			}
			return out
		},

		accountPickerCurrentId() {
			const t = this.accountPicker.target
			const f = this.bookingForm
			if (t === 'category') { return f.categoryId }
			if (t === 'money') { return f.moneyAccountId }
			if (t === 'debit') { return f.debitAccountId }
			if (t === 'credit') { return f.creditAccountId }
			if (t === 'assign' && this.accountPicker.tx) { return this.accountPicker.tx.contraAccountId }
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
					if (!map[key]) { map[key] = {} }
					map[key][tx.contraAccountId] = (map[key][tx.contraAccountId] || 0) + 1
				}
			}
			return map
		},

		// Zuordnungs-Vorschlag je offener Bankbuchung (Regeln zuerst, dann Historie)
		suggestionsById() {
			const out = {}
			for (const tx of this.transactions) {
				if (tx.status === 'assigned') { continue }
				const s = this.computeSuggestion(tx)
				if (s) { out[tx.id] = s }
			}
			return out
		},

		// assignProgress ist jetzt Teil von BookingsTab.vue.
		// selectedAccount bleibt hier (wird auch von openNewAccount() gebraucht,
		// das ausserhalb von AccountsTab.vue liegt).
		selectedAccount() {
			return this.selectedAccountId ? this.accountsById[this.selectedAccountId] : null
		},

		// Geldbestand fuer die Kopfzeile: eine Zahl ueber alle Geldkonten, die
		// dafuer angehakt sind. Bis 0.30.0 stand hier nur das erste Geldkonto
		// nach Kontonummer - bei Kasse (1000) und Bankkonto (1200) also
		// ausgerechnet die Barkasse, waehrend das Bankkonto unsichtbar blieb
		// (Issue #31). Gerechnet wird im Backend (LedgerAggregator::cashTotal),
		// damit Dashboard und Auswertung dieselbe Zahl zeigen.
		cashTotal() {
			const total = this.balances && this.balances.bankTotal
			// Kein einziges mitzaehlendes Geldkonto: lieber gar kein Chip als
			// eine 0,00 EUR, die es so nicht gibt.
			if (!total || !total.count) { return null }
			const counted = (this.balances.bankReconciliation || []).filter((b) => b.countInTotal !== false)
			return {
				// Bei genau einem Konto ist sein Name die genauere Auskunft als
				// das Wort "Geldbestand" - fuer Vereine mit nur einem Geldkonto
				// sieht die Kopfzeile damit aus wie bisher.
				label: counted.length === 1 ? counted[0].name : this.t('Geldbestand'),
				balance: total.balance,
				// Die gesamte noch nicht zugeordnete Summe, nicht der Anteil
				// eines einzelnen Kontos.
				open: total.open,
				breakdown: counted,
				excluded: Math.max(0, (total.allCount || 0) - total.count),
				allBalance: total.allBalance,
			}
		},

		// Aufschluesselung als Tooltip: ohne sie waere die eine Zahl in der
		// Kopfzeile nicht nachvollziehbar - erst recht nicht, wenn ein Konto
		// bewusst fehlt.
		cashTotalTitle() {
			const c = this.cashTotal
			if (!c) { return '' }
			const lines = c.breakdown.map((b) => `${b.number} ${b.name}: ${formatMoney(b.balance)}`)
			if (c.excluded > 0) {
				lines.push(this.n(
					'%n Geldkonto zählt hier nicht mit – alle zusammen: {amount}',
					'%n Geldkonten zählen hier nicht mit – alle zusammen: {amount}',
					c.excluded,
					{ amount: formatMoney(c.allBalance) },
				))
			}
			if (Math.abs(c.open) > 0.005) {
				lines.push(this.t('{amount} noch nicht zugeordnet', { amount: formatMoney(c.open) }))
			}
			return lines.join('\n')
		},

		// journalRows kommt aus setup() (useJournal).
		// visibleTree/statementRows sind jetzt Teil von AccountsTab.vue.
		selectedCC() {
			if (this.selectedCCCode === false || !this.reportData) { return null }
			return this.reportData.costCenters.find((c) => c.code === this.selectedCCCode) || null
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
			if (v === 'journal') { this.loadJournal() }
		},

		reportView(v) {
			if (v === 'summary') { this.loadBalances() } else if (v === 'costcenters') { this.loadReport() } else if (v === 'spheres') { this.loadSphereReport() } else if (v === 'budget') { this.loadBudget() } else if (v === 'audit') { this.loadAudit() }
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
					if (this.selectedAccountId) { jobs.push(this.loadStatement(this.selectedAccountId)) }
				} else if (tab === 'reports') {
					if (this.reportView === 'costcenters') { jobs.push(this.loadReport()) } else if (this.reportView === 'budget') { jobs.push(this.loadBudget()) }
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
		this.onMqChange = (e) => { this.isMobile = e.matches }
		if (this.vbhMql.addEventListener) { this.vbhMql.addEventListener('change', this.onMqChange) } else { this.vbhMql.addListener(this.onMqChange) }
		this.loadRecentAccounts()
		await this.loadMe()
		if (this.canRead) {
			await this.loadYears()
			await Promise.all([
				this.loadAccounts(),
				this.loadBalances(),
				this.loadJournal(),
				this.loadTransactions(),
				this.loadRules(),
				this.loadClosedYears(),
				this.loadSphereReport(),
				this.loadOpenItems(),
				this.loadCostCenters(),
				// storage/demo-Status betrifft alle Leseberechtigten (Demo-Banner);
				// hier mit im ersten Schwung, damit membershipActive schon steht,
				// wenn visibleTabs zum ersten Mal berechnet wird - sonst blitzt der
				// Beitraege-Reiter erst nachtraeglich in der Navigation auf.
				this.loadStorageSettings(),
			])
			if (this.isAdmin) {
				this.loadPermissions()
				// Setup-Assistent beim allerersten Login eines Verwalters (leerer Verein, noch nicht gesehen)
				if (this.accounts.length === 0 && !this.setupWizardSeen()) { this.showSetupWizard = true }
			}
			// "Was ist neu" erst NACH dem Setup-Assistenten prüfen (this.showSetupWizard
			// steht an dieser Stelle bereits synchron fest) - beide Modals dürfen sich
			// nie überschneiden, der Setup-Assistent hat für echte Erstläufer Vorrang.
			await this.loadWhatsNew()
			// Kollaboration: Änderungen anderer Personen per Polling mitbekommen
			this.checkRevision(true)
			this.syncTimer = setInterval(() => this.checkRevision(), SYNC_INTERVALL)
			window.addEventListener('focus', this.onWindowFocus)
		}
	},

	beforeUnmount() {
		document.removeEventListener('keydown', this.onGlobalKeydown)
		if (this.vbhMql) {
			if (this.vbhMql.removeEventListener) { this.vbhMql.removeEventListener('change', this.onMqChange) } else { this.vbhMql.removeListener(this.onMqChange) }
		}
		if (this.syncTimer) { clearInterval(this.syncTimer) }
		window.removeEventListener('focus', this.onWindowFocus)
	},

	methods: {
		// --- Tastaturkürzel: N = neue Buchung, / = Suche fokussieren ---
		onGlobalKeydown(e) {
			if (e.ctrlKey || e.metaKey || e.altKey) { return }
			const tag = (e.target.tagName || '').toLowerCase()
			if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) { return }
			if (this.showBooking || this.showAccount || this.showImport || this.confirm.open) { return }
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
			if (tab === 'bookings' && this.bookingView === 'journal') { jobs.push(this.loadJournal()) } else if (tab === 'accounts') { jobs.push(this.loadAccounts(), this.loadBalances()) } else if (tab === 'reports') {
				if (this.reportView === 'summary') { jobs.push(this.loadBalances()) } else if (this.reportView === 'costcenters') { jobs.push(this.loadReport()) } else if (this.reportView === 'spheres') { jobs.push(this.loadSphereReport()) } else if (this.reportView === 'budget') { jobs.push(this.loadBudget()) } else if (this.reportView === 'audit') { jobs.push(this.loadAudit()) }
			}
			if (!jobs.length) { return }
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
			if (!this.canRead) { return }
			if (!init && document.hidden) { return }
			const result = await this.checkRemoteRevision(init, this.busy)
			if (result !== 'changed') { return }
			// Eigene Schreibaktion? Dann still aktualisieren (die Handler haben schon
			// nachgeladen, aber eine zeitgleiche Fremdänderung darf nicht verloren
			// gehen). Nicht über eine feste Frist: der Abgleich läuft nur alle
			// SYNC_INTERVALL, und solange die App mit dem eigenen Schreiben beschäftigt
			// ist, verschiebt er sich weiter. Eine 15-Sekunden-Frist meldete deshalb den
			// eigenen Import als „von einer anderen Person geändert". Maßstab ist
			// stattdessen der Zeitpunkt, zu dem der eigene Stand zuletzt nachweislich
			// mit dem Server übereinstimmte: alles danach Geschriebene kann die
			// Abweichung erklären.
			const ownWrite = api.lastWriteAt() > 0 && api.lastWriteAt() >= this.syncChangedSince
			await this.refreshAfterRemoteChange()
			if (!ownWrite) { showInfo(this.t('Die Buchhaltung wurde von einer anderen Person geändert – Ansicht aktualisiert.')) }
		},

		async refreshAfterRemoteChange() {
			this.ccBookings = {}
			this.ccExpanded = {}
			const jobs = [this.loadYears(), this.loadClosedYears(), this.loadAccounts(), this.loadBalances(), this.loadJournal(), this.loadTransactions(), this.loadSphereReport(), this.loadOpenItems(), this.loadCostCenters()]
			// Beitraege/Mandate/Einzuege: eigenes Zusatzmodul, ab Rolle Buchhalter
			// (Backend-Gate) - siehe ContributionsTab.vue.
			if (this.canWrite) { jobs.push(this.loadMembershipFees(), this.loadSepaMandates(), this.loadSepaBatches()) }
			if (this.activeTab === 'accounts' && this.selectedAccountId) { jobs.push(this.loadStatement(this.selectedAccountId)) }
			if (this.activeTab === 'reports') {
				if (this.reportView === 'costcenters') { jobs.push(this.loadReport()) } else if (this.reportView === 'budget') { jobs.push(this.loadBudget()) }
			}
			try { await Promise.all(jobs) } catch { /* nächster Poll versucht es erneut */ }
		},

		/**
		 * Ziel-URL der Nextcloud-Einstellungsseite dieser App, optional mit
		 * Anker auf einen bestimmten Abschnitt (id="settings-section_<id>",
		 * siehe SettingsApp.vue). Verwaltung fuer Nextcloud-Admins, Persoenlich
		 * fuer App-Verwalter ohne Nextcloud-Adminrechte - dieselbe Unterscheidung
		 * wie in Settings\PersonalSettings::getSection().
		 */
		settingsUrl(section = null) {
			const area = this.me?.isServerAdmin ? 'admin' : 'user'
			const url = generateUrl('/settings/' + area + '/vereinsbuchhaltung')
			return section ? url + '#settings-section_' + section : url
		},

		// GET /api/settings ist ab Revisor erlaubt; App.vue braucht davon nur
		// noch, was ausserhalb der (jetzt in Nextcloud-Einstellungen
		// ausgelagerten) Einstellungsseite gebraucht wird: den Kostenstellen-
		// Modus (ReportsTab, AccountDialog), den Vereinsnamen (SetupChecklist),
		// den Beitrags-Standardwert (ContributionsTab → MemberDialog,
		// MemberImportDialog) sowie demoActive/membershipActive.
		async loadStorageSettings() {
			try {
				const { data } = await api.getSettings()
				this.costCenterMode = data.cost_center_mode || 'group'
				this.clubName = data.club_name || ''
				this.demoActive = !!data.demo_active
				this.defaultFeeAmount = data.default_fee_amount ?? ''
				this.defaultFeeFrequency = data.default_fee_frequency || 'yearly'
				this.membershipActive = !!data.membership_active
			} catch { /* ignorieren */ }
		},

		// Schreibt nur den Kostenstellen-Modus - die uebrigen elf Felder
		// gehoeren seit dem Umzug in die Nextcloud-Einstellungen nicht mehr zu
		// App.vue, siehe SettingsController::update() (teilweise Speicherung).
		async saveCostCenterMode() {
			try {
				await api.saveSettings({ cost_center_mode: this.costCenterMode })
				showSuccess(this.t('Einstellungen gespeichert.'))
				this.reportData = null
			} catch (e) {
				const msg = (e?.response?.data?.message) || this.t('Speichern fehlgeschlagen (HTTP {status})', { status: e?.response?.status ?? this.t('Netzwerkfehler') })
				showError(msg)
			}
		},

		// loadYears/loadClosedYears/isYearClosed kommen aus setup() (useYears).
		// closeYear/reopenYear sind jetzt Teil von SettingsYearClose.vue (eigenes
		// setup() mit useYears()).
		// --- Änderungsprotokoll ----------------------------------------------
		async loadAudit(more = false) {
			if (this.auditLoading) { return }
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
			if (this.bookingMode === 'simple') { return f.moneyAccountId }
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
			const rest = f.splitLines.map((l) => (side === 'credit'
				? { accountId: l.accountId, debit: 0, credit: Number(l.amount) }
				: { accountId: l.accountId, debit: Number(l.amount), credit: 0 }))
			return [fixed, ...rest]
		},

		// --- Einfach-Modus: Einnahme/Ausgabe <-> Soll/Haben ---
		deriveSimpleAccounts() {
			const f = this.bookingForm
			if (!f.categoryId || !f.moneyAccountId) { return null }
			// Einnahme: Soll Geldkonto / Haben Ertragskonto — Ausgabe: Soll Aufwandskonto / Haben Geldkonto
			return f.kind === 'income'
				? { debit: f.moneyAccountId, credit: f.categoryId }
				: { debit: f.categoryId, credit: f.moneyAccountId }
		},

		mapToSimple(debitId, creditId) {
			const d = this.accountsById[debitId]
			const c = this.accountsById[creditId]
			if (!d || !c) { return null }
			const isMoney = (a) => a.isBank || a.type === 'asset'
			if (isMoney(d) && c.type === 'income') { return { kind: 'income', moneyAccountId: d.id, categoryId: c.id } }
			if (d.type === 'expense' && isMoney(c)) { return { kind: 'expense', moneyAccountId: c.id, categoryId: d.id } }
			return null
		},

		setBookingKind(kind) {
			if (this.bookingForm.kind === kind) { return }
			this.bookingForm.kind = kind
			this.bookingForm.categoryId = null
			// Bei einer Aufteilung wechseln die Gegenkonten mit der Buchungsart
			// die Seite (siehe splitSideOf) - die bisher gewaehlten Kategorien
			// gehoeren dann zur falschen Richtung.
			if (this.bookingForm.splitMode) { this.bookingForm.splitLines = this.bookingForm.splitLines.map((l) => ({ ...l, accountId: null })) }
		},

		setBookingMode(mode) {
			if (mode === this.bookingMode) { return }
			const f = this.bookingForm
			if (f.splitMode) {
				// Bei einer Aufteilung stehen Soll/Haben nicht komplett fest; es
				// wandert nur die feste Seite zwischen Geldkonto und Soll/Haben.
				const side = this.splitSideForForm()
				if (mode === 'expert') {
					f.splitSide = side
					if (side === 'credit') { f.debitAccountId = f.moneyAccountId } else { f.creditAccountId = f.moneyAccountId }
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
				if (m) { Object.assign(f, m) }
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

		async reloadStatement() { if (this.selectedAccountId) { await this.loadStatement(this.selectedAccountId) } },
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
			if (!fromAccountId || !toAccountId || fromAccountId === toAccountId) { return false }
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

		async onImported() { await this.loadBalances(); await this.loadTransactions() },

		async resetAll() {
			if (!await this.askConfirm(this.t('Alle Daten löschen'), this.t('Wirklich ALLE Konten, Buchungen und Importe löschen?'))) { return }
			this.busy = true
			try {
				await api.reset(); showSuccess(this.t('Alle Daten gelöscht.'))
				this.selectedAccountId = null; this.statement = null; this.journalData = []; this.transactions = []
				this.selectedYear = null
				this.demoActive = false
				await this.loadYears(); await this.loadAccounts(); await this.loadBalances(); await this.loadCostCenters()
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
			try { return localStorage.getItem('vbh_setup_wizard_seen') === '1' } catch { return false }
		},

		markSetupWizardSeen() {
			try { localStorage.setItem('vbh_setup_wizard_seen', '1') } catch { /* voll/gesperrt – dann eben ohne */ }
		},

		closeSetupWizard() {
			this.showSetupWizard = false
			this.markSetupWizardSeen()
		},

		onWizardChoice(choice) {
			this.closeSetupWizard()
			if (choice === 'xbuc') { window.location.href = this.settingsUrl('daten') } else if (choice === 'fresh') { this.seedAccounts() } else if (choice === 'demo') { this.seedDemoData() }
		},

		// --- "Was ist neu" (WhatsNewDialog.vue) ---------------------------
		async loadWhatsNew() {
			try {
				const { data } = await api.whatsNew()
				this.whatsNewCurrentVersion = data.currentVersion
				this.whatsNewLastSeenVersion = data.lastSeenVersion
				if (data.lastSeenVersion === '') {
					// Echter Erstlogin (oder Instanz ohne Historie): still auf den
					// aktuellen Stand markieren, kein Popup - eine Wand historischer
					// Aenderungen waere fuer neue Vereinskonten nur Ballast. Der
					// Setup-Assistent uebernimmt bereits den Erstlauf-Flow.
					this.whatsNewLastSeenVersion = data.currentVersion
					await api.dismissWhatsNew(data.currentVersion)
					return
				}
				if (!this.showSetupWizard && this.whatsNewEntries.length) {
					this.whatsNewShow = true
				}
			} catch { /* kein Blocker, still weiterarbeiten */ }
		},

		dismissWhatsNew() {
			this.whatsNewShow = false
			this.whatsNewUnfiltered = false
			if (!this.whatsNewCurrentVersion) { return }
			this.whatsNewLastSeenVersion = this.whatsNewCurrentVersion
			api.dismissWhatsNew(this.whatsNewCurrentVersion).catch(() => { /* naechster Login versucht es erneut */ })
		},

		openWhatsNewUnfiltered() {
			// Hilfe-Modal schliessen, sonst stapeln sich zwei NcModal uebereinander
			// (am 22.08.2026 per Browser-Test aufgefallen: der Link liegt im
			// Hilfe-Modal, das dabei offen bleibt).
			this.closeHelp()
			this.whatsNewUnfiltered = true
			this.whatsNewShow = true
		},

		// --- Bankbuchungen ---
		// loadTransactions kommt aus setup() (useJournal).
		// loadRules kommt aus setup() (useRules); die Pflege der Regeln selbst
		// steht in RulesPanel.vue (Unterreiter „Regeln" von BookingsTab.vue).
		async onSpheresChanged() { await this.loadAccounts(); await this.loadSphereReport() },
		// Kostenstellen: die Zuordnung haengt am Konto, deshalb muessen die
		// Konten mit nachgeladen werden; der Bericht nur, wenn er offen ist.
		async onCostCentersChanged() {
			await this.loadAccounts()
			if (this.activeTab === 'reports' && this.reportView === 'costcenters') { await this.loadReport() }
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
			if (!await this.askConfirm(this.t('Umsatz löschen'), this.t('Umsatz über {amount} von/an „{counterparty}" endgültig löschen?', { amount: formatMoney(tx.amount), counterparty: tx.counterparty || '' }))) { return }
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
			if (!tx) { return }
			const rows = parts.filter((p) => p.accountId || p.amount)
			if (rows.length < 2) { showError(this.t('Eine Aufteilung braucht mindestens zwei Zeilen.')); return }
			if (rows.some((p) => !p.accountId)) { showError(this.t('Jeder Zeile der Aufteilung fehlt noch ein Konto.')); return }
			if (rows.some((p) => !(Number(p.amount) > 0))) { showError(this.t('Jede Zeile der Aufteilung braucht einen Betrag größer als 0.')); return }
			const total = Math.abs(tx.amountCents || 0) / 100
			if (!splitBalanced(total, rows)) {
				const rest = splitRemainder(total, rows)
				showError(rest > 0
					? this.t('Die Aufteilung geht nicht auf – es fehlen noch {amount}.', { amount: formatMoney(rest) })
					: this.t('Die Aufteilung übersteigt den Umsatz um {amount}.', { amount: formatMoney(-rest) }))
				return
			}
			try {
				await api.assignTransactionParts(tx.id, rows.map((p) => ({ accountId: p.accountId, amount: Number(p.amount) })))
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
					if (acc && acc.active) { return { accountId: acc.id, label: `${acc.number} ${acc.name}` } }
				}
			}
			if (tx.counterparty) {
				const hist = this.assignmentHistory[tx.counterparty.trim().toLowerCase()]
				if (hist) {
					const best = Object.entries(hist).sort((a, b) => b[1] - a[1])[0]
					const acc = this.accountsById[Number(best[0])]
					if (acc && acc.active) { return { accountId: acc.id, label: `${acc.number} ${acc.name}` } }
				}
			}
			return null
		},

		applySuggestion(tx) {
			const s = this.suggestionsById[tx.id]
			if (s) { this.onAssign(tx, s.accountId) }
		},

		async createRuleFromTx(tx) {
			if (!tx.counterparty || !tx.contraAccountId) { return }
			const value = tx.counterparty.trim()
			const exists = this.rules.some((r) => r.matchField === 'counterparty' && r.matchValue.toLowerCase() === value.toLowerCase())
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
			try { const { data } = await api.attachmentCounts(); this.attachmentCountMap = data } catch { /* ignorieren */ }
		},

		async loadAttachments(journalId) {
			if (!journalId) { this.bookingAttachments = []; return }
			try { const { data } = await api.listAttachments(journalId); this.bookingAttachments = data } catch { this.bookingAttachments = [] }
		},

		/**
		 * Filtert eine Dateiauswahl auf das, was der Server annimmt, und meldet
		 * jede abgelehnte Datei mit Namen. Der Typ wird nur geprueft, wenn der
		 * Browser einen nennt: der Server schaut ohnehin per finfo in die Datei,
		 * eine leere type-Angabe (unbekannte Endung) darf also durch.
		 *
		 * @param {File[]} files ausgewaehlte Dateien
		 * @return {File[]} die Dateien, die durchgehen
		 */
		belegeAnnehmbar(files) {
			const ok = []
			for (const file of files) {
				if (file.type && !BELEG_MIMES.includes(file.type)) {
					showError(this.t('{name}: Dieser Dateityp geht nicht – erlaubt sind PDF, JPG, PNG, GIF und WebP.', { name: file.name }))
				} else if (file.size > BELEG_MAX_BYTES) {
					showError(this.t('{name} ist zu groß – erlaubt sind höchstens 20 MB pro Beleg.', { name: file.name }))
				} else {
					ok.push(file)
				}
			}
			return ok
		},

		async uploadAttachment(event) {
			const files = this.belegeAnnehmbar(Array.from(event.target.files || []))
			if (!files.length || !this.bookingForm.id) { event.target.value = ''; return }
			this.attachmentUploading = true
			try {
				for (const file of files) {
					const fd = new FormData()
					fd.append('file', file)
					await api.uploadAttachment(this.bookingForm.id, fd)
				}
				await this.loadAttachments(this.bookingForm.id)
				this.loadAttachmentCounts()
			} catch (e) { showError(this.errMsg(e, this.t('Upload fehlgeschlagen'))) } finally { this.attachmentUploading = false; event.target.value = '' }
		},

		async deleteAttachment(id) {
			if (!await this.askConfirm(this.t('Beleg löschen'), this.t('Diesen Beleg wirklich unwiderruflich löschen?'))) { return }
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
			if ((r.isSplit && !r.splitSide) || this.attachmentCountMap[r.id]?.count === 1) { this.openQuickViewer(r) } else { this.editBooking(r) }
		},

		async openQuickViewer(r) {
			try {
				const { data } = await api.listAttachments(r.id)
				if (data.length) { this.openViewer(data[0]) }
			} catch { this.editBooking(r) }
		},

		formatFileSize(bytes) {
			if (bytes < 1024) { return bytes + ' B' }
			if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(1) + ' KB' }
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
			if (this.isMobile || this.bookingMode !== 'simple') { return }
			try { if (localStorage.getItem('vbh_booking_tour_seen') === '1') { return } } catch { return }
			this.bookingTour = { active: true, step: 0 }
			try { localStorage.setItem('vbh_booking_tour_seen', '1') } catch { /* voll/gesperrt – dann eben ohne */ }
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
			if (p.target === 'category') { this.bookingForm.categoryId = opt.id } else if (p.target === 'money') { this.bookingForm.moneyAccountId = opt.id } else if (p.target === 'debit') { this.bookingForm.debitAccountId = opt.id } else if (p.target === 'credit') { this.bookingForm.creditAccountId = opt.id } else if (p.target === 'assign' && p.tx) { this.onAssign(p.tx, opt.id) } else if (p.target && p.target.startsWith('splitline:')) {
				const i = Number(p.target.slice('splitline:'.length))
				const lines = this.splitAssign.open ? this.splitAssign.parts : this.bookingForm.splitLines
				if (lines[i]) { lines[i].accountId = opt.id }
			}
			this.pushRecentAccount(opt.id)
			this.closeAccountPicker()
		},

		onAccountPickerSuggest() {
			const p = this.accountPicker
			if (p.target === 'assign' && p.tx) {
				const s = this.suggestionsById[p.tx.id]
				if (s) { this.pushRecentAccount(s.id) }
				this.applySuggestion(p.tx)
			}
			this.closeAccountPicker()
		},

		loadRecentAccounts() {
			try {
				const list = JSON.parse(localStorage.getItem('vbh_recent_accounts') || '[]')
				this.recentAccountIds = Array.isArray(list) ? list : []
			} catch { this.recentAccountIds = [] }
		},

		pushRecentAccount(id) {
			if (!id) { return }
			this.recentAccountIds = [id, ...this.recentAccountIds.filter((x) => x !== id)].slice(0, 5)
			try { localStorage.setItem('vbh_recent_accounts', JSON.stringify(this.recentAccountIds)) } catch { /* voll/gesperrt – dann eben ohne */ }
		},

		// statementRowNet ist jetzt Teil von AccountsTab.vue.
		// Mobil: Konten-Drilldown zurück zur Liste
		closeAccountDetail() {
			this.selectedAccountId = null
			this.statement = null
		},

		// Belege beim Anlegen sammeln (mobil wie am Desktop), der Upload folgt
		// nach dem Speichern, sobald die Buchung eine ID hat
		addPendingFiles(event) {
			const known = new Set(this.pendingFiles.map(dateiSchluessel))
			for (const file of this.belegeAnnehmbar(Array.from(event.target.files || []))) {
				// Zweimal dieselbe Datei gewaehlt: sie soll nur einmal dranhaengen.
				if (known.has(dateiSchluessel(file))) { continue }
				known.add(dateiSchluessel(file))
				this.pendingFiles.push(file)
			}
			event.target.value = ''
		},

		/**
		 * Haengt die gesammelten Belege an die eben angelegte Buchung. Jede Datei
		 * einzeln, damit eine abgelehnte die uebrigen nicht mitreisst; was nicht
		 * ankommt, bleibt in der Warteliste und laesst sich erneut hochladen.
		 *
		 * @param {number|null} journalId Buchung, an die die Belege gehen
		 * @return {Promise<number>} Anzahl der Dateien, die nicht angekommen sind
		 */
		async uploadPendingFiles(journalId) {
			const files = this.pendingFiles
			if (!journalId || !files.length) { this.pendingFiles = []; return 0 }
			const failed = []
			let lastError = null
			for (const file of files) {
				const fd = new FormData()
				fd.append('file', file)
				try {
					await api.uploadAttachment(journalId, fd)
				} catch (e) { failed.push(file); lastError = e }
			}
			this.pendingFiles = failed
			this.loadAttachmentCounts()
			if (failed.length) {
				showError(this.t('Die Buchung steht, aber diese Belege kamen nicht an: {names} ({grund}). Sie warten weiter im Dialog.', {
					names: failed.map((f) => f.name).join(', '),
					grund: this.errMsg(lastError, this.t('Upload fehlgeschlagen')),
				}))
			}
			return failed.length
		},

		/** Zweiter Anlauf fuer Belege, die nach dem Speichern haengen geblieben sind. */
		async retryPendingFiles() {
			if (!this.bookingForm.id || !this.pendingFiles.length) { return }
			this.attachmentUploading = true
			try {
				const offen = await this.uploadPendingFiles(this.bookingForm.id)
				// Erst die Liste nachladen, dann Vollzug melden - sonst steht in
				// der Belegablage fuer einen Wimpernschlag noch "kein Beleg".
				await this.loadAttachments(this.bookingForm.id)
				if (offen === 0) { showSuccess(this.t('Beleg gespeichert.')) }
			} finally { this.attachmentUploading = false }
		},

		// Mobil: Tippen auf eine Buchungskarte
		openBookingCard(r) {
			if (this.canWrite) {
				this.editBooking(r)
				return
			}
			if (this.attachmentCountMap[r.id]) { this.openQuickViewer(r) }
		},

		// Mobil: Löschen aus dem Bearbeiten-Dialog (die Karten haben keinen
		// eigenen Löschen-Knopf; am Desktop bleibt der Knopf in der Zeile).
		async deleteBookingFromDialog() {
			const id = this.bookingForm.id
			const entryNo = this.bookingForm.entryNo
			if (!id) { return }
			if (!await this.askConfirm(this.t('Buchung löschen'), this.t('Buchung #{n} löschen?', { n: entryNo }))) { return }
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
			const fixed = (r.lines || []).find((l) => (side === 'credit' ? l.debitCents : l.creditCents) > 0)
			const parts = (r.lines || []).filter((l) => (side === 'credit' ? l.creditCents : l.debitCents) > 0)
			const f = this.bookingForm
			f.splitMode = true
			f.splitSide = side
			f.splitLines = parts.map((l) => ({
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
				if (side === 'credit') { f.debitAccountId = fixed ? fixed.accountId : null } else { f.creditAccountId = fixed ? fixed.accountId : null }
			}
		},

		closeBooking() { this.showBooking = false; this.bookingForm = this.emptyBookingForm(); this.bookingAttachments = []; this.pendingFiles = []; this.endTour() },
		/**
		 * Prueft das Formular und baut die Nutzlast der zweizeiligen Buchung.
		 *
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
		 *
		 * @return {object|null} null, wenn eine Meldung gezeigt wurde
		 */
		buildSplitPayload() {
			const f = this.bookingForm
			if (!f.date || !f.amount) { showError(this.t('Datum und Gesamtbetrag sind Pflicht.')); return null }
			if (!this.splitFixedAccountId()) {
				showError(this.bookingMode === 'simple' ? this.t('Das Geldkonto fehlt.') : this.t('Das Konto der festen Seite fehlt.'))
				return null
			}
			const rows = (f.splitLines || []).filter((l) => l.accountId || l.amount)
			if (rows.length < 2) { showError(this.t('Eine Aufteilung braucht mindestens zwei Zeilen.')); return null }
			if (rows.some((l) => !l.accountId)) { showError(this.t('Jeder Zeile der Aufteilung fehlt noch ein Konto.')); return null }
			if (rows.some((l) => !(Number(l.amount) > 0))) { showError(this.t('Jede Zeile der Aufteilung braucht einen Betrag größer als 0.')); return null }
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
			if (this.bookingSaving) { return }
			const f = this.bookingForm
			const payload = f.splitMode ? this.buildSplitPayload() : this.buildSimplePayload()
			if (!payload) { return }
			this.bookingSaving = true
			try {
				if (f.id) {
					await api.updateBooking(f.id, { ...payload, updatedAt: f.updatedAt || null })
				} else {
					const { data } = await api.createBooking(payload)
					if (await this.uploadPendingFiles(data && data.id)) {
						// Die Buchung steht, nur die Belege fehlen: aus dem Anlegen
						// wird das Bearbeiten dieser Buchung. So legt ein weiterer
						// Klick keine zweite Buchung an und die haengengebliebenen
						// Dateien lassen sich im offenen Dialog erneut hochladen.
						this.bookingForm = { ...f, id: data.id, entryNo: data.entryNo, updatedAt: data.updatedAt || null }
						await this.loadAttachments(data.id)
						await this.loadJournal(); await this.loadBalances(); await this.loadYears(); await this.loadSphereReport()
						return
					}
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
			} finally { this.bookingSaving = false }
		},

		async removeBooking(r) {
			if (!await this.askConfirm(this.t('Buchung löschen'), this.t('Buchung #{n} löschen?', { n: r.entryNo }))) { return }
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
			if (!data) { return }
			const form = {}
			for (const acc of data) { form[acc.id] = { amount: acc.openingBalance || 0, date: acc.openingDate || '' } }
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
				// Ein neues Geldkonto zaehlt in die Kopfzeile, bis jemand es
				// abwaehlt - dieselbe Vorgabe wie im Backend.
				countInTotal: true,
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
				// Altbestand ohne gesetztes Feld zaehlt mit (Spaltenvorgabe).
				countInTotal: acc.countInTotal !== false,
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
						countInTotal: !!f.countInTotal,
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
					// eslint-disable-next-line no-unused-vars -- active wird bewusst nur herausdestrukturiert, um es aus rest auszuschliessen
					const { active, ...rest } = f
					await api.createAccount({ ...rest, parentId: f.parentId || null, sphere: f.sphere || null, reserveKind: f.reserveKind || null, iban: f.iban || null, costCenterId: f.costCenterId || null })
				}
				this.showAccount = false
				this.accountEditId = null
				this.newAccount = { number: '', name: '', type: 'income', category: '', isBank: false, countInTotal: true, parentId: null, sphere: '', reserveKind: '', iban: '', costCenterId: null }
				await this.loadAccounts(); await this.loadBalances(); await this.loadSphereReport()
				showSuccess(this.t('Konto gespeichert.'))
			} catch (e) { showError(this.errMsg(e, this.t('Konto konnte nicht gespeichert werden'))) }
		},

		async deleteAccount(acc) {
			if (!await this.askConfirm(this.t('Konto löschen'), this.t('Konto "{number} {name}" löschen?', { number: acc.number, name: acc.name }))) { return }
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
				if (this.selectedAccountId === acc.id) { await this.loadStatement(acc.id) }
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
				if (this.selectedCCCode !== false && !data.costCenters.some((c) => c.code === this.selectedCCCode)) { this.selectedCCCode = false }
			} catch (e) { showError(this.errMsg(e, this.t('Bericht konnte nicht geladen werden'))) }
		},

		selectCC(cc) { this.selectedCCCode = cc.code; this.renameName = cc.name; this.ccExpanded = {} },
		isCCSelected(cc) { return this.selectedCCCode !== false && cc.code === this.selectedCCCode },
		// Eigener Wrapper um useBalances.loadSphereReport (balancesLoadSphereReport),
		// da hier zusätzlich die lokale Sphären-Auswahl (Reports-Tab) zurückgesetzt wird.
		async loadSphereReport() {
			const data = await this.balancesLoadSphereReport()
			if (data && this.selectedSphereCode !== false && !data.spheres.some((s) => s.code === this.selectedSphereCode)) { this.selectedSphereCode = false }
		},

		selectSphere(s) { this.selectedSphereCode = s.code },
		isSphereSelected(s) { return this.selectedSphereCode !== false && s.code === this.selectedSphereCode },
		async toggleCCAccount(accountId) {
			if (!accountId) { return }
			const open = !this.ccExpanded[accountId]
			this.ccExpanded[accountId] = open
			if (open && !this.ccBookings[accountId]) {
				try { const { data } = await api.accountJournal(accountId, false, this.selectedYear); this.ccBookings[accountId] = data.rows } catch (e) { showError(this.errMsg(e, this.t('Buchungen konnten nicht geladen werden'))) }
			}
		},

		async saveRename() {
			const cc = this.selectedCC
			if (!cc || !cc.code) { return }
			try { await api.renameCostCenter(cc.code, this.renameName); await this.loadReport(); showSuccess(this.t('Auswertungsgruppe umbenannt.')) } catch (e) { showError(this.errMsg(e, this.t('Umbenennen fehlgeschlagen'))) }
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
		 *
		 * @param accountId
		 */
		currentPlanForAccount(accountId) {
			const row = this.budgetData && this.budgetData.rows.find((r) => r.accountId === accountId)
			return row ? row.plan : 0
		},

		// --- Berechtigungen ---
		async loadMe() {
			const data = await this.authLoadMe()
			if (!this.visibleTabs.some((t) => t.id === this.activeTab)) {
				this.activeTab = this.visibleTabs.length ? this.visibleTabs[0].id : 'dashboard'
			}
			if (data.role === 'revisor') {
				try { this.revisorIntroDismissed = localStorage.getItem('vbh_revisor_intro_dismissed') === '1' } catch { this.revisorIntroDismissed = false }
			}
		},

		dismissRevisorIntro() {
			this.revisorIntroDismissed = true
			try { localStorage.setItem('vbh_revisor_intro_dismissed', '1') } catch { /* voll/gesperrt – dann eben ohne */ }
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
		// Ziele der Erste-Schritte-Checkliste (SetupChecklist.vue): 'settings:<id>'
		// verlaesst die App zur Nextcloud-Einstellungsseite auf dem genannten
		// Abschnitt, 'reports:<view>' wechselt in den Berichte-Tab auf die
		// genannte Auswertung - beides, weil die Zuordnung selbst seit
		// NAVIGATION-KONZEPT.md Abschnitt 4 teils nicht mehr in den Einstellungen
		// liegt (z. B. Sphären → Bericht „Sphären").
		onSetupNavigate(action) {
			if (action === 'accounts') { this.activeTab = 'accounts' } else if (action === 'booking') { this.openNewBooking() } else if (action.startsWith('settings:')) { window.location.href = this.settingsUrl(action.slice('settings:'.length)) } else if (action.startsWith('reports:')) { this.activeTab = 'reports'; this.reportView = action.slice('reports:'.length) }
		},

		errMsg,
		// Chart-Rendering (Monatschart) ist jetzt Teil von DashboardTab.vue.
	},
}
</script>
