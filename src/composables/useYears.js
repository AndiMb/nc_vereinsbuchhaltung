import { computed, reactive } from 'vue'
import api from '../api.js'

const state = reactive({
	selectedYear: null,
	years: [],
	closedYears: [],
})

// Jahresabschluss: Jahr → Abschluss-Info (schneller Lookup)
const closedYearSet = computed(() => {
	const s = {}
	for (const c of state.closedYears) { s[c.year] = c }
	return s
})

const yearClosed = computed(() => state.selectedYear !== null && !!closedYearSet.value[state.selectedYear])

function isYearClosed(date) {
	if (!date) { return false }
	return !!closedYearSet.value[parseInt(String(date).slice(0, 4), 10)]
}

async function loadYears() {
	try {
		const { data } = await api.journalYears()
		state.years = data
		if (state.selectedYear === null && data.length) { state.selectedYear = data[0] }
	} catch { /* Jahre optional */ }
}

async function loadClosedYears() {
	try { const { data } = await api.closedYears(); state.closedYears = data } catch { /* optional */ }
}

export function useYears() {
	return { state, closedYearSet, yearClosed, isYearClosed, loadYears, loadClosedYears }
}
