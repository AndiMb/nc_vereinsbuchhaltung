import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/vereinsbuchhaltung/api'

const url = (path) => generateUrl(base + path)

// Kollaboration: Zeitpunkt des letzten eigenen Schreibzugriffs, damit das
// Revision-Polling eigene Änderungen (die Handler laden selbst nach) von
// fremden unterscheiden kann.
let lastWriteTs = 0
axios.interceptors.response.use((response) => {
	const method = ((response.config && response.config.method) || 'get').toLowerCase()
	const requestUrl = (response.config && response.config.url) || ''
	if (method !== 'get' && method !== 'head' && requestUrl.includes('/apps/vereinsbuchhaltung/')) {
		lastWriteTs = Date.now()
	}
	return response
})

export default {
	// Kollaboration (Änderungsstand)
	revision: () => axios.get(url('/revision')),
	lastWriteAt: () => lastWriteTs,

	// Geschäftsjahre: Liste, Regel, Grenzen, Festschreibung.
	// `period` ist überall die Perioden-ID; fehlt sie oder ist sie 0, meint der
	// Server „alle Zeiträume" (siehe PeriodService::isSelected()).
	periods: () => axios.get(url('/periods')),
	periodRule: () => axios.get(url('/periods/rule')),
	savePeriodRule: (rule, dryRun = false) => axios.put(url('/periods/rule'), { ...rule, dryRun: dryRun ? 1 : 0 }),
	createPeriod: () => axios.post(url('/periods')),
	updatePeriod: (id, data) => axios.put(url(`/periods/${id}`), data),
	deletePeriod: (id) => axios.delete(url(`/periods/${id}`)),
	closePeriod: (id) => axios.post(url(`/periods/${id}/close`)),
	reopenPeriod: (id) => axios.delete(url(`/periods/${id}/close`)),

	// Änderungsprotokoll
	auditLog: (limit = 100, offset = 0) => axios.get(url('/audit'), { params: { limit, offset } }),

	// Konten
	listAccounts: () => axios.get(url('/accounts')),
	createAccount: (data) => axios.post(url('/accounts'), data),
	updateAccount: (id, data) => axios.put(url(`/accounts/${id}`), data),
	deleteAccount: (id) => axios.delete(url(`/accounts/${id}`)),
	seedAccounts: () => axios.post(url('/accounts/seed')),
	setOpening: (id, amount, date) => axios.post(url(`/accounts/${id}/opening`), { amount, date }),
	accountJournal: (id, includeChildren, period) => axios.get(url(`/accounts/${id}/journal`), { params: { includeChildren: includeChildren ? 1 : 0, period: period || undefined } }),

	// Buchungen (Bankumsätze)
	listTransactions: (status) => axios.get(url('/transactions'), { params: { status } }),
	assignTransaction: (id, contraAccountId) => axios.post(url(`/transactions/${id}/assign`), { contraAccountId }),
	// Aufteilung auf mehrere Gegenkonten: parts = [{accountId, amount}, …] in Euro
	assignTransactionParts: (id, parts) => axios.post(url(`/transactions/${id}/assign`), { parts }),
	unassignTransaction: (id) => axios.delete(url(`/transactions/${id}/assign`)),
	deleteTransaction: (id) => axios.delete(url(`/transactions/${id}`)),

	// Import CSV-CAMT
	previewImport: (formData) => axios.post(url('/import/preview'), formData),
	commitImport: (formData) => axios.post(url('/import/commit'), formData),

	// Import zero Buchhaltung (.xbuc)
	previewXbuc: (formData) => axios.post(url('/import/xbuc/preview'), formData),
	commitXbuc: (formData) => axios.post(url('/import/xbuc'), formData),
	reset: () => axios.post(url('/reset')),
	seedDemo: () => axios.post(url('/demo/seed')),

	// Journal / Buchungssätze
	journal: (period) => axios.get(url('/journal'), { params: { period: period || undefined } }),
	createBooking: (data) => axios.post(url('/journal'), data),
	updateBooking: (id, data) => axios.put(url(`/journal/${id}`), data),
	deleteBooking: (id) => axios.delete(url(`/journal/${id}`)),
	// Eine Seite einer Buchung auf ein anderes Konto umbuchen (aus dem Kontoauszug)
	reassignBooking: (id, fromAccountId, toAccountId, updatedAt) => axios.post(url(`/journal/${id}/reassign`), { fromAccountId, toAccountId, updatedAt: updatedAt || null }),

	// Auswertung
	balances: (period) => axios.get(url('/journal/balances'), { params: { period: period || undefined } }),

	// Berichte / Kostenstellen
	costCenterReport: (period) => axios.get(url('/report/costcenters'), { params: { period: period || undefined } }),
	renameCostCenter: (code, name) => axios.put(url('/report/costcenters'), { code, name }),

	// Kostenstellen pflegen (frei definierbar)
	listCostCenters: () => axios.get(url('/costcenters')),
	createCostCenter: (code, name) => axios.post(url('/costcenters'), { code, name }),
	updateCostCenter: (id, code, name) => axios.put(url(`/costcenters/${id}`), { code, name }),
	deleteCostCenter: (id) => axios.delete(url(`/costcenters/${id}`)),
	assignCostCenter: (accountIds, costCenterId) => axios.post(url('/costcenters/assign'), { accountIds, costCenterId: costCenterId || 0 }),
	sphereReport: (period) => axios.get(url('/report/spheres'), { params: { period: period || undefined } }),
	bulkSphere: (accountIds, sphere) => axios.post(url('/accounts/sphere-bulk'), { accountIds, sphere }),
	multiyearTrend: () => axios.get(url('/report/multiyear-trend')),
	reserveReport: () => axios.get(url('/report/reserves')),

	// Finanzplan / Budget
	budget: (period) => axios.get(url('/budget'), { params: { period: period || undefined } }),
	setBudget: (accountId, period, amount, note = '') => axios.post(url('/budget'), { accountId, period, amount, note }),

	// Finanzplan-Stände (Snapshots)
	budgetSnapshots: (period) => axios.get(url('/budget/snapshots'), { params: { period: period || undefined } }),
	createBudgetSnapshot: (period, label) => axios.post(url('/budget/snapshots'), { period, label }),
	budgetSnapshot: (id) => axios.get(url(`/budget/snapshots/${id}`)),
	deleteBudgetSnapshot: (id) => axios.delete(url(`/budget/snapshots/${id}`)),

	// Regeln
	listRules: () => axios.get(url('/rules')),
	createRule: (data) => axios.post(url('/rules'), data),
	updateRule: (id, data) => axios.put(url(`/rules/${id}`), data),
	deleteRule: (id) => axios.delete(url(`/rules/${id}`)),

	// Offene Posten
	listOpenItems: () => axios.get(url('/open-items')),
	createOpenItem: (data) => axios.post(url('/open-items'), data),
	markOpenItemPaid: (id, journalId) => axios.post(url(`/open-items/${id}/pay`), { journalId: journalId || undefined }),
	cancelOpenItem: (id) => axios.post(url(`/open-items/${id}/cancel`)),
	reopenOpenItem: (id) => axios.post(url(`/open-items/${id}/reopen`)),
	deleteOpenItem: (id) => axios.delete(url(`/open-items/${id}`)),

	// SEPA-Lastschriftmandate (optionales Zusatzmodul)
	listSepaMandates: () => axios.get(url('/sepa/mandates')),
	createSepaMandate: (data) => axios.post(url('/sepa/mandates'), data),
	updateSepaMandate: (id, data) => axios.put(url(`/sepa/mandates/${id}`), data),
	revokeSepaMandate: (id) => axios.post(url(`/sepa/mandates/${id}/revoke`)),
	changeSepaMandateBankAccount: (id, data) => axios.post(url(`/sepa/mandates/${id}/change-account`), data),
	deleteSepaMandate: (id) => axios.delete(url(`/sepa/mandates/${id}`)),

	// Mitgliedsbeiträge mit Zahlungsfrequenz (optionales Zusatzmodul)
	listMembershipFees: () => axios.get(url('/sepa/fees')),
	createMembershipFee: (data) => axios.post(url('/sepa/fees'), data),
	updateMembershipFee: (id, data) => axios.put(url(`/sepa/fees/${id}`), data),
	deleteMembershipFee: (id) => axios.delete(url(`/sepa/fees/${id}`)),
	catchUpMembershipFee: (id) => axios.post(url(`/sepa/fees/${id}/catch-up`)),

	// Massenanlage aus einer CSV-Liste: erst pruefen, dann anlegen
	previewMemberImport: (csv) => axios.post(url('/sepa/members/import/preview'), { csv }),
	runMemberImport: (csv) => axios.post(url('/sepa/members/import'), { csv }),

	// SEPA-Sammeleinzüge (pain.008-Export)
	previewSepaExport: (executionDate) => axios.get(url('/sepa/export/preview'), { params: executionDate ? { executionDate } : {} }),
	listSepaBatches: () => axios.get(url('/sepa/export/batches')),
	createSepaBatch: (executionDate) => axios.post(url('/sepa/export/batches'), { executionDate }),
	deleteSepaBatch: (id) => axios.delete(url(`/sepa/export/batches/${id}`)),
	settleSepaBatch: (id, journalId) => axios.post(url(`/sepa/export/batches/${id}/settle`), { journalId: journalId || undefined }),
	listSepaBatchItems: (id) => axios.get(url(`/sepa/export/batches/${id}/items`)),
	revertSepaReturn: (itemId) => axios.post(url(`/sepa/export/items/${itemId}/revert-return`)),
	sepaBatchXmlUrl: (id) => generateUrl(base + `/sepa/export/batches/${id}/xml`),

	// Export (CSV-Download – Browser-Navigation, kein Axios)
	exportJournalUrl: (period) => generateUrl(base + '/export/journal') + (period ? `?period=${period}` : ''),
	exportBalancesUrl: (period) => generateUrl(base + '/export/balances') + (period ? `?period=${period}` : ''),
	exportReportUrl: (period) => generateUrl(base + '/export/report') + (period ? `?period=${period}` : ''),
	exportBudgetUrl: (period) => generateUrl(base + '/export/budget') + (period ? `?period=${period}` : ''),
	exportMultiyearUrl: () => generateUrl(base + '/export/multiyear'),
	kassenberichtUrl: (period) => generateUrl(base + '/export/kassenbericht') + (period ? `?period=${period}` : ''),
	kurzberichtUrl: (since) => generateUrl(base + '/export/kurzbericht') + (since ? `?since=${since}` : ''),
	exportAttachmentsUrl: (period) => generateUrl(base + '/export/attachments') + (period ? `?period=${period}` : ''),

	// Hilfe (Handbuch als lesbare Seite, optional mit Kapitel-Anker; druckfertige Kassenprüfer-Kurzanleitung)
	handbuchUrl: (anchor) => generateUrl(base + '/help/handbuch') + (anchor ? `#${anchor}` : ''),
	pruefleitfadenUrl: () => generateUrl(base + '/help/pruefleitfaden'),

	// Was ist neu (Splash-Screen nach Updates)
	whatsNew: () => axios.get(url('/whatsnew')),
	dismissWhatsNew: (version) => axios.post(url('/whatsnew/seen'), { version }),

	// Belegablage
	attachmentCounts: () => axios.get(url('/attachments/counts')),
	listAttachments: (journalId) => axios.get(url(`/journal/${journalId}/attachments`)),
	uploadAttachment: (journalId, formData) => axios.post(url(`/journal/${journalId}/attachments`), formData),
	deleteAttachment: (id) => axios.delete(url(`/attachments/${id}`)),
	attachmentViewUrl: (id) => generateUrl(base + `/attachments/${id}/view`),
	attachmentDownloadUrl: (id) => generateUrl(base + `/attachments/${id}/download`),
	// Wächter-Ordner für Belege
	attachmentInbox: () => axios.get(url('/attachments/inbox')),
	attachmentInboxSummary: () => axios.get(url('/attachments/inbox/summary')),
	attachmentInboxViewUrl: (fileId) => generateUrl(base + `/attachments/inbox/${fileId}/view`),
	linkAttachment: (journalId, fileId) => axios.post(url(`/journal/${journalId}/attachments/link`), { fileId }),

	// Einstellungen (Belegablage)
	getSettings: () => axios.get(url('/settings')),
	saveSettings: (data) => axios.post(url('/settings'), data),
	listFolders: (user, path) => axios.get(url('/settings/folders'), { params: { user, path } }),

	// Corporate Design (Vereins-Logo für den Kurzbericht)
	logoUrl: () => generateUrl(base + '/settings/logo'),
	uploadLogo: (formData) => axios.post(url('/settings/logo'), formData),
	deleteLogo: () => axios.delete(url('/settings/logo')),

	// Berechtigungen
	me: () => axios.get(url('/permissions/me')),
	listPermissions: () => axios.get(url('/permissions')),
	listGroups: () => axios.get(url('/permissions/groups')),
	listUsers: () => axios.get(url('/permissions/users')),
	setPermission: (data) => axios.post(url('/permissions'), data),
	deletePermission: (id) => axios.delete(url(`/permissions/${id}`)),
}
