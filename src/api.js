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

	// Buchungen
	listTransactions: status => axios.get(url('/transactions'), { params: { status } }),
	assignTransaction: (id, contraAccountId) => axios.post(url(`/transactions/${id}/assign`), { contraAccountId }),
	unassignTransaction: id => axios.delete(url(`/transactions/${id}/assign`)),

	// Import
	previewImport: formData => axios.post(url('/import/preview'), formData),
	commitImport: formData => axios.post(url('/import/commit'), formData),
	listImports: () => axios.get(url('/imports')),

	// Auswertung
	balances: () => axios.get(url('/journal/balances')),
	journal: () => axios.get(url('/journal')),

	// Regeln
	listRules: () => axios.get(url('/rules')),
	createRule: data => axios.post(url('/rules'), data),
	deleteRule: id => axios.delete(url(`/rules/${id}`)),
}
