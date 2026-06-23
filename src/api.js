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
	accountJournal: (id, includeChildren) => axios.get(url(`/accounts/${id}/journal`), { params: { includeChildren: includeChildren ? 1 : 0 } }),

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
	journal: () => axios.get(url('/journal')),
	createBooking: data => axios.post(url('/journal'), data),
	updateBooking: (id, data) => axios.put(url(`/journal/${id}`), data),
	deleteBooking: id => axios.delete(url(`/journal/${id}`)),

	// Auswertung
	balances: () => axios.get(url('/journal/balances')),

	// Berichte / Kostenstellen
	costCenterReport: () => axios.get(url('/report/costcenters')),
	renameCostCenter: (code, name) => axios.put(url('/report/costcenters'), { code, name }),

	// Regeln
	listRules: () => axios.get(url('/rules')),
	createRule: data => axios.post(url('/rules'), data),
	deleteRule: id => axios.delete(url(`/rules/${id}`)),

	// Berechtigungen
	me: () => axios.get(url('/permissions/me')),
	listPermissions: () => axios.get(url('/permissions')),
	listGroups: () => axios.get(url('/permissions/groups')),
	setPermission: data => axios.post(url('/permissions'), data),
	deletePermission: id => axios.delete(url(`/permissions/${id}`)),
}
