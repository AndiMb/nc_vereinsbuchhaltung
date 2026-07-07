import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/vereinsbuchhaltung/api'

const url = path => generateUrl(base + path)

export default {
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

	// Journal / Buchungssätze
	journal: year => axios.get(url('/journal'), { params: { year: year || undefined } }),
	journalYears: () => axios.get(url('/journal/years')),
	createBooking: data => axios.post(url('/journal'), data),
	updateBooking: (id, data) => axios.put(url(`/journal/${id}`), data),
	deleteBooking: id => axios.delete(url(`/journal/${id}`)),

	// Auswertung
	balances: year => axios.get(url('/journal/balances'), { params: { year: year || undefined } }),

	// Berichte / Kostenstellen
	costCenterReport: year => axios.get(url('/report/costcenters'), { params: { year: year || undefined } }),
	renameCostCenter: (code, name) => axios.put(url('/report/costcenters'), { code, name }),

	// Finanzplan / Budget
	budget: year => axios.get(url('/budget'), { params: { year: year || undefined } }),
	setBudget: (accountId, year, amount) => axios.post(url('/budget'), { accountId, year, amount }),

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

	// Export (CSV-Download – Browser-Navigation, kein Axios)
	exportJournalUrl:  year => generateUrl(base + '/export/journal')  + (year ? `?year=${year}` : ''),
	exportBalancesUrl: year => generateUrl(base + '/export/balances') + (year ? `?year=${year}` : ''),
	exportReportUrl:   year => generateUrl(base + '/export/report')   + (year ? `?year=${year}` : ''),
	exportBudgetUrl:   year => generateUrl(base + '/export/budget')   + (year ? `?year=${year}` : ''),

	// Belegablage
	attachmentCounts:     () => axios.get(url('/attachments/counts')),
	listAttachments:      journalId => axios.get(url(`/journal/${journalId}/attachments`)),
	uploadAttachment:     (journalId, formData) => axios.post(url(`/journal/${journalId}/attachments`), formData),
	deleteAttachment:     id => axios.delete(url(`/attachments/${id}`)),
	attachmentViewUrl:     id => generateUrl(base + `/attachments/${id}/view`),
	attachmentDownloadUrl: id => generateUrl(base + `/attachments/${id}/download`),

	// Einstellungen (Belegablage)
	getSettings: () => axios.get(url('/settings')),
	saveSettings: data => axios.post(url('/settings'), data),

	// Berechtigungen
	me: () => axios.get(url('/permissions/me')),
	listPermissions: () => axios.get(url('/permissions')),
	listGroups: () => axios.get(url('/permissions/groups')),
	listUsers: () => axios.get(url('/permissions/users')),
	setPermission: data => axios.post(url('/permissions'), data),
	deletePermission: id => axios.delete(url(`/permissions/${id}`)),
}
