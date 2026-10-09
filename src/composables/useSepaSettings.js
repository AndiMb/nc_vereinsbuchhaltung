import { reactive } from 'vue'
import api from '../api.js'
import { useDueDateSchedule } from './useDueDateSchedule.js'

/**
 * Die Einstellungen des Beitrags-/SEPA-Moduls, die die Einstellungsseite
 * (SettingsSepaModule.vue und die Karten darin) pflegt (Issue #101).
 *
 * Sie liegen serverseitig an drei Stellen, je nach Ticket, das sie
 * eingefuehrt hat: der allgemeine Einstellungssatz (`/settings`), die
 * Einzugs-Einstellungen (`/debit-batches/settings`) und die der
 * Ruecklastschrift-Verbuchung (`/sepa-import/settings`). Dieses Modul haelt
 * den gespeicherten Stand zusammen und bietet je Karte EINEN Speichern-Aufruf.
 * Die Karten arbeiten mit eigenen Entwuerfen und uebernehmen erst nach dem
 * Speichern, was der Server zurueckgibt.
 *
 * Die drei Fristen vor dem Einzug (Vorwarnfenster, Vorabinfo-Vorlauf, Freigabe-
 * Vorlauf) stehen zusammen in der Karte „Beitragsjahr und Einzugszyklus“. Zwei
 * davon liegen serverseitig beim Terminplan (`/due-date-schedule`, siehe
 * useDueDateSchedule); der Reiter Einzug zeigt sie nur noch an.
 *
 * Alle Speichern-Funktionen werfen bei einem Fehler den Axios-Fehler weiter;
 * die Karte zeigt dann `saveErrorMessage(e)` (lib/sepaSettings.js).
 */
const state = reactive({
	loaded: false,
	loadFailed: false,
	// /settings
	fiscalYearStartMonth: 1,
	mandateReferencePrefix: '',
	mandateDocumentFolder: '',
	showMissingDocumentWarning: true,
	expiryWarningDays: 180,
	dunningIntervalDays: 14,
	// /debit-batches/settings
	releaseLeadDays: 5,
	xmlFolderEnabled: false,
	xmlFolderPath: '',
	// /sepa-import/settings
	returnFeeAccountId: null,
	returnFeeRechargeEnabled: false,
	contributionDefaultAccountId: null,
})

function applySettings(data) {
	state.fiscalYearStartMonth = data.fiscal_year_start_month ?? 1
	state.mandateReferencePrefix = data.mandate_reference_prefix ?? ''
	state.mandateDocumentFolder = data.mandate_document_folder ?? ''
	state.showMissingDocumentWarning = !!data.show_missing_document_warning
	state.expiryWarningDays = data.expiry_warning_days ?? 180
	state.dunningIntervalDays = data.dunning_interval_days ?? 14
}

function applyDebitBatchSettings(data) {
	state.releaseLeadDays = data.releaseLeadDays ?? 5
	state.xmlFolderEnabled = !!data.xmlFolderEnabled
	state.xmlFolderPath = data.xmlFolderPath ?? ''
}

function applySepaImportSettings(data) {
	state.returnFeeAccountId = data.returnFeeAccountId ?? null
	state.returnFeeRechargeEnabled = !!data.returnFeeRechargeEnabled
	state.contributionDefaultAccountId = data.contributionDefaultAccountId ?? null
}

/** Liest alle Einstellungen neu vom Server; `loadFailed` zeigt, ob das misslang. */
async function loadSepaSettings() {
	state.loadFailed = false
	try {
		const [settings, debitBatch, sepaImport] = await Promise.all([
			api.getSettings(),
			api.getDebitBatchSettings(),
			api.getSepaImportSettings(),
			// Fuer die Terminplan-Uebersicht; meldet einen eigenen Ladefehler selbst
			useDueDateSchedule().loadDueDateSchedule(),
		])
		applySettings(settings.data)
		applyDebitBatchSettings(debitBatch.data)
		applySepaImportSettings(sepaImport.data)
		state.loaded = true
	} catch {
		state.loadFailed = true
	}
}

/**
 * Beitragsjahr-Beginn (`/settings`) und die drei Fristen (`/due-date-schedule/lead-days`,
 * ein Aufruf für Vorwarnfenster, Vorabinfo-Vorlauf und Freigabe-Vorlauf).
 */
async function saveCycleSettings({ fiscalYearStartMonth, releaseLeadDays, warningLeadDays, prenotificationLeadDays }) {
	const { data: leads } = await api.setDueDateScheduleLeadDays({ warningLeadDays, prenotificationLeadDays, releaseLeadDays })
	const schedule = useDueDateSchedule().state
	schedule.warningLeadDays = leads.warningLeadDays
	schedule.prenotificationLeadDays = leads.prenotificationLeadDays
	schedule.releaseLeadDays = leads.releaseLeadDays
	state.releaseLeadDays = leads.releaseLeadDays
	const settings = await api.saveSettings({ fiscal_year_start_month: fiscalYearStartMonth })
	applySettings(settings.data)
}

/** XML-Ablage der Einzugsdatei (`/debit-batches/settings`). */
async function saveXmlStorage({ xmlFolderEnabled, xmlFolderPath }) {
	const { data } = await api.saveDebitBatchSettings({
		xmlFolderEnabled: xmlFolderEnabled ? '1' : '0',
		xmlFolderPath,
	})
	applyDebitBatchSettings(data)
}

/** Mandate: Referenz-Praefix, Nachweis-Ordner, Hinweis „ohne Nachweis", Ablauf-Vorwarnung (`/settings`). */
async function saveMandateSettings({ mandateReferencePrefix, mandateDocumentFolder, showMissingDocumentWarning, expiryWarningDays }) {
	const { data } = await api.saveSettings({
		mandate_reference_prefix: mandateReferencePrefix,
		mandate_document_folder: mandateDocumentFolder,
		show_missing_document_warning: showMissingDocumentWarning ? '1' : '0',
		expiry_warning_days: expiryWarningDays,
	})
	applySettings(data)
}

/**
 * Ruecklastschriften und Mahnwesen: erst die Konten (die strengere Pruefung),
 * dann der Mahnabstand. Eine Auswahl „kein Konto" geht als 0 hinaus - der
 * Server liest „nicht gesendet" als „nicht anfassen".
 */
async function saveReturnSettings({ returnFeeAccountId, returnFeeRechargeEnabled, contributionDefaultAccountId, dunningIntervalDays }) {
	const sepaImport = await api.saveSepaImportSettings({
		returnFeeAccountId: returnFeeAccountId ?? 0,
		returnFeeRechargeEnabled: returnFeeRechargeEnabled ? '1' : '0',
		contributionDefaultAccountId: contributionDefaultAccountId ?? 0,
	})
	applySepaImportSettings(sepaImport.data)
	const settings = await api.saveSettings({ dunning_interval_days: dunningIntervalDays })
	applySettings(settings.data)
}

export function useSepaSettings() {
	return {
		state,
		loadSepaSettings,
		saveCycleSettings,
		saveXmlStorage,
		saveMandateSettings,
		saveReturnSettings,
	}
}
