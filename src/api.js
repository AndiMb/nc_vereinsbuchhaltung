import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/vereinsbuchhaltung/api'

const url = path => generateUrl(base + path)

// Kollaboration: Zeitpunkt des letzten eigenen Schreibzugriffs, damit das
// Revision-Polling eigene Änderungen (die Handler laden selbst nach) von
// fremden unterscheiden kann.
let lastWriteTs = 0
axios.interceptors.response.use(response => {
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

	// Jahresabschluss (Festschreibung) + Änderungsprotokoll
	closedYears: () => axios.get(url('/years/closed')),
	closeYear: year => axios.post(url(`/years/${year}/close`)),
	reopenYear: year => axios.delete(url(`/years/${year}/close`)),
	auditLog: (limit = 100, offset = 0) => axios.get(url('/audit'), { params: { limit, offset } }),

	// Konten
	listAccounts: () => axios.get(url('/accounts')),
	createAccount: data => axios.post(url('/accounts'), data),
	updateAccount: (id, data) => axios.put(url(`/accounts/${id}`), data),
	deleteAccount: id => axios.delete(url(`/accounts/${id}`)),
	seedAccounts: () => axios.post(url('/accounts/seed')),
	setOpening: (id, amount, date) => axios.post(url(`/accounts/${id}/opening`), { amount, date }),
	accountJournal: (id, includeChildren, year) => axios.get(url(`/accounts/${id}/journal`), { params: { includeChildren: includeChildren ? 1 : 0, year: year || undefined } }),

	// Buchungen (Bankumsätze)
	listTransactions: status => axios.get(url('/transactions'), { params: { status } }),
	assignTransaction: (id, contraAccountId) => axios.post(url(`/transactions/${id}/assign`), { contraAccountId }),
	unassignTransaction: id => axios.delete(url(`/transactions/${id}/assign`)),

	// Import CSV-CAMT
	previewImport: formData => axios.post(url('/import/preview'), formData),
	commitImport: formData => axios.post(url('/import/commit'), formData),
	listImports: () => axios.get(url('/imports')),

	// Import zero Buchhaltung (.xbuc)
	previewXbuc: formData => axios.post(url('/import/xbuc/preview'), formData),
	commitXbuc: formData => axios.post(url('/import/xbuc'), formData),
	reset: () => axios.post(url('/reset')),
	seedDemo: () => axios.post(url('/demo/seed')),

	// Journal / Buchungssätze
	journal: year => axios.get(url('/journal'), { params: { year: year || undefined } }),
	journalYears: () => axios.get(url('/journal/years')),
	createBooking: data => axios.post(url('/journal'), data),
	updateBooking: (id, data) => axios.put(url(`/journal/${id}`), data),
	deleteBooking: id => axios.delete(url(`/journal/${id}`)),
	// Eine Seite einer Buchung auf ein anderes Konto umbuchen (aus dem Kontoauszug)
	reassignBooking: (id, fromAccountId, toAccountId, updatedAt) => axios.post(url(`/journal/${id}/reassign`), { fromAccountId, toAccountId, updatedAt: updatedAt || null }),

	// Auswertung
	balances: year => axios.get(url('/journal/balances'), { params: { year: year || undefined } }),

	// Berichte / Kostenstellen
	costCenterReport: year => axios.get(url('/report/costcenters'), { params: { year: year || undefined } }),
	renameCostCenter: (code, name) => axios.put(url('/report/costcenters'), { code, name }),

	// Kostenstellen pflegen (frei definierbar)
	listCostCenters: () => axios.get(url('/costcenters')),
	createCostCenter: (code, name) => axios.post(url('/costcenters'), { code, name }),
	updateCostCenter: (id, code, name) => axios.put(url(`/costcenters/${id}`), { code, name }),
	deleteCostCenter: id => axios.delete(url(`/costcenters/${id}`)),
	assignCostCenter: (accountIds, costCenterId) => axios.post(url('/costcenters/assign'), { accountIds, costCenterId: costCenterId || 0 }),
	sphereReport: year => axios.get(url('/report/spheres'), { params: { year: year || undefined } }),
	bulkSphere: (accountIds, sphere) => axios.post(url('/accounts/sphere-bulk'), { accountIds, sphere }),
	multiyearTrend: () => axios.get(url('/report/multiyear-trend')),
	reserveReport: () => axios.get(url('/report/reserves')),

	// Finanzplan / Budget
	budget: year => axios.get(url('/budget'), { params: { year: year || undefined } }),
	setBudget: (accountId, year, amount, note = '') => axios.post(url('/budget'), { accountId, year, amount, note }),

	// Finanzplan-Stände (Snapshots)
	budgetSnapshots: year => axios.get(url('/budget/snapshots'), { params: { year: year || undefined } }),
	createBudgetSnapshot: (year, label) => axios.post(url('/budget/snapshots'), { year, label }),
	budgetSnapshot: id => axios.get(url(`/budget/snapshots/${id}`)),
	deleteBudgetSnapshot: id => axios.delete(url(`/budget/snapshots/${id}`)),

	// Regeln
	listRules: () => axios.get(url('/rules')),
	createRule: data => axios.post(url('/rules'), data),
	updateRule: (id, data) => axios.put(url(`/rules/${id}`), data),
	deleteRule: id => axios.delete(url(`/rules/${id}`)),

	// Offene Posten
	listOpenItems: () => axios.get(url('/open-items')),
	createOpenItem: data => axios.post(url('/open-items'), data),
	markOpenItemPaid: (id, journalId) => axios.post(url(`/open-items/${id}/pay`), { journalId: journalId || undefined }),
	cancelOpenItem: id => axios.post(url(`/open-items/${id}/cancel`)),
	reopenOpenItem: id => axios.post(url(`/open-items/${id}/reopen`)),
	deleteOpenItem: id => axios.delete(url(`/open-items/${id}`)),

	// Export (CSV-Download – Browser-Navigation, kein Axios)
	exportJournalUrl: year => generateUrl(base + '/export/journal') + (year ? `?year=${year}` : ''),
	exportBalancesUrl: year => generateUrl(base + '/export/balances') + (year ? `?year=${year}` : ''),
	exportReportUrl: year => generateUrl(base + '/export/report') + (year ? `?year=${year}` : ''),
	exportBudgetUrl: year => generateUrl(base + '/export/budget') + (year ? `?year=${year}` : ''),
	exportMultiyearUrl: () => generateUrl(base + '/export/multiyear'),
	kassenberichtUrl: year => generateUrl(base + '/export/kassenbericht') + (year ? `?year=${year}` : ''),
	kurzberichtUrl: since => generateUrl(base + '/export/kurzbericht') + (since ? `?since=${since}` : ''),
	exportAttachmentsUrl: year => generateUrl(base + '/export/attachments') + (year ? `?year=${year}` : ''),

	// Hilfe (Handbuch als lesbare Seite, optional mit Kapitel-Anker; druckfertige Kassenprüfer-Kurzanleitung)
	handbuchUrl: anchor => generateUrl(base + '/help/handbuch') + (anchor ? `#${anchor}` : ''),
	pruefleitfadenUrl: () => generateUrl(base + '/help/pruefleitfaden'),

	// Belegablage
	attachmentCounts: () => axios.get(url('/attachments/counts')),
	listAttachments: journalId => axios.get(url(`/journal/${journalId}/attachments`)),
	uploadAttachment: (journalId, formData) => axios.post(url(`/journal/${journalId}/attachments`), formData),
	deleteAttachment: id => axios.delete(url(`/attachments/${id}`)),
	attachmentViewUrl: id => generateUrl(base + `/attachments/${id}/view`),
	attachmentDownloadUrl: id => generateUrl(base + `/attachments/${id}/download`),

	// Einstellungen (Belegablage)
	getSettings: () => axios.get(url('/settings')),
	saveSettings: data => axios.post(url('/settings'), data),

	// Corporate Design (Vereins-Logo für den Kurzbericht)
	logoUrl: () => generateUrl(base + '/settings/logo'),
	uploadLogo: formData => axios.post(url('/settings/logo'), formData),
	deleteLogo: () => axios.delete(url('/settings/logo')),

	// Berechtigungen
	me: () => axios.get(url('/permissions/me')),
	listPermissions: () => axios.get(url('/permissions')),
	listGroups: () => axios.get(url('/permissions/groups')),
	listUsers: () => axios.get(url('/permissions/users')),
	setPermission: data => axios.post(url('/permissions'), data),
	deletePermission: id => axios.delete(url(`/permissions/${id}`)),
}
