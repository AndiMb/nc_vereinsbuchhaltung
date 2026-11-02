import { showError } from '@nextcloud/dialogs'
import { computed, reactive } from 'vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'
import { isClaimItem } from '../lib/openItems.js'

// Dieselbe Liste enthält freie Posten und die Forderungen des Beitragsmoduls
// (memberId/type gesetzt); `isClaim` unterscheidet sie für die Ansicht - nur die
// freien lassen sich in der generischen Sicht bearbeiten (Issue #121).
// `openItems` ist nur der geladene Ausschnitt (der gewählte Filter); die Zähler der
// Chips und der Überfällig-Badge kommen als Aggregat aus der Datenbank (Issue #255).
const state = reactive({
	openItems: [],
	openItemCounts: { open: 0, overdue: 0, paid: 0, waived: 0, cancelled: 0, all: 0 },
	// Zuletzt geladener Filter: Nachladen ohne Argument (App.vue, nach Aktionen) behält ihn.
	openItemFilterKey: 'open',
})

const overdueCount = computed(() => state.openItemCounts.overdue || 0)

// Status je Filter-Chip: „Überfällig“ ist eine Teilmenge von „Offen“ und wird in der
// Ansicht weiter gefiltert; „Alle“ lädt ohne Statusfilter.
function statusForFilter(filterKey) {
	if (filterKey === 'all') { return null }
	if (filterKey === 'overdue') { return 'open' }
	return filterKey
}

async function loadOpenItems(filterKey = state.openItemFilterKey) {
	state.openItemFilterKey = filterKey
	try {
		const [list, counts] = await Promise.all([
			api.listOpenItems(statusForFilter(filterKey)),
			api.getOpenItemCounts(),
		])
		// Ein später abgeschlossener, älterer Aufruf darf den neueren Filter nicht überschreiben.
		if (state.openItemFilterKey !== filterKey) { return }
		state.openItems = list.data
		state.openItemCounts = counts.data
	} catch (e) { showError(errMsg(e, t('Offene Posten konnten nicht geladen werden'))) }
}

export function useOpenItems() {
	return { state, overdueCount, loadOpenItems, isClaim: isClaimItem }
}
