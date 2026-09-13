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

/**
 * Baut die Options-Liste fuer Konto-Autocompletes: eine "Haeufig verwendet"-Gruppe der bis zu
 * 5 meistgebuchten Konten, danach die restlichen aktiven Konten nach Kategorie gruppiert.
 * Haeufig verwendete Konten werden aus ihrer Kategorie-Gruppe ausgeschlossen, damit kein Konto
 * doppelt erscheint.
 *
 * @param {Array} accountsSorted alle Konten, nach Kontonummer sortiert
 * @param {object} usageCounts accountId -> Anzahl Buchungen
 * @param {(key: string) => string} t Uebersetzungsfunktion
 * @return {Array} Options fuer NcSelect
 */
export function buildAccountOptions(accountsSorted, usageCounts, t) {
	const active = accountsSorted.filter((acc) => acc.active)
	const frequent = active
		.filter((acc) => usageCounts[acc.id])
		.sort((a, b) => usageCounts[b.id] - usageCounts[a.id])
		.slice(0, 5)

	const opts = []
	const frequentIds = new Set()
	if (frequent.length >= 2) {
		opts.push({ id: null, label: t('★ Häufig verwendet'), $isDisabled: true })
		for (const acc of frequent) {
			frequentIds.add(acc.id)
			opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
		}
	}

	const groups = {}
	for (const acc of active) {
		if (frequentIds.has(acc.id)) { continue }
		const cat = acc.category || t('Sonstige')
		;(groups[cat] = groups[cat] || []).push(acc)
	}
	for (const [cat, accs] of Object.entries(groups)) {
		opts.push({ id: null, label: cat, $isDisabled: true })
		for (const acc of accs) {
			opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
		}
	}
	return opts
}
