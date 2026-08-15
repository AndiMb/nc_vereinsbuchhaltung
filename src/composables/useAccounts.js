import { showError } from '@nextcloud/dialogs'
import { computed, reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

const state = reactive({
	accounts: [],
})

const accountsById = computed(() => {
	const map = {}
	for (const acc of state.accounts) { map[acc.id] = acc }
	return map
})

const accountsSorted = computed(() => state.accounts.slice().sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true })))

const childrenOf = computed(() => {
	const map = {}
	for (const acc of state.accounts) {
		if (acc.parentId) { (map[acc.parentId] = map[acc.parentId] || []).push(acc) }
	}
	return map
})

/** @return {Promise<Array|null>} die geladenen Konten, oder null bei Fehler (bereits als Toast gemeldet) */
async function loadAccounts() {
	try {
		const { data } = await api.listAccounts()
		state.accounts = data
		return data
	} catch (e) {
		showError(errMsg(e, 'Konten konnten nicht geladen werden'))
		return null
	}
}

/** Legt den Standard-Kontenrahmen an (Backend prüft, ob der Verein noch leer ist). */
async function seedDefaults() {
	await api.seedAccounts()
}

export function useAccounts() {
	return { state, accountsById, accountsSorted, childrenOf, loadAccounts, seedDefaults }
}
