import { showError } from '@nextcloud/dialogs'
import { computed, reactive } from 'vue'
import api from '../api.js'
import { accountCategoryLabel } from '../lib/accountFilter.js'
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
 * Die Kategorie-Ueberschriften sind normalerweise reine, nicht waehlbare Trenner. Mit
 * selectableCategories werden sie zu echten Optionen (isCategory, eindeutige String-ID),
 * mit denen der Kontofilter im Journal alle Konten der Kategorie auf einmal meint. In diesem
 * Modus erscheint jede Kategorie auch dann, wenn alle ihre Konten in der "Haeufig
 * verwendet"-Gruppe stehen - der Filter darf nicht davon abhaengen, was gerade oft gebucht wird.
 *
 * @param {Array} accountsSorted alle Konten, nach Kontonummer sortiert
 * @param {object} usageCounts accountId -> Anzahl Buchungen
 * @param {(key: string) => string} t Uebersetzungsfunktion
 * @param {{selectableCategories?: boolean}} [opts]
 * @return {Array} Options fuer NcSelect
 */
export function buildAccountOptions(accountsSorted, usageCounts, t, { selectableCategories = false } = {}) {
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

	// Reihenfolge der Gruppen: erstes Vorkommen in der Kontonummern-Sortierung.
	const groups = new Map()
	for (const acc of active) {
		const cat = accountCategoryLabel(acc, t)
		if (!groups.has(cat)) { groups.set(cat, []) }
		if (!frequentIds.has(acc.id)) { groups.get(cat).push(acc) }
	}
	for (const [cat, accs] of groups) {
		if (selectableCategories) {
			opts.push({ id: `category:${cat}`, label: cat, category: cat, isCategory: true })
		} else if (accs.length) {
			opts.push({ id: null, label: cat, $isDisabled: true })
		}
		for (const acc of accs) {
			opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
		}
	}
	return opts
}
