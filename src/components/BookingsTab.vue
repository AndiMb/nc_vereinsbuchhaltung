<template>
	<div style="display: contents;">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button :class="{ active: bookingView === 'journal' }" @click="$emit('update:booking-view', 'journal')">Alle Buchungen</button>
				<button :class="{ active: bookingView === 'unassigned' }" @click="$emit('update:booking-view', 'unassigned')">
					Zuzuordnen
					<span v-if="unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
				</button>
			</div>
			<div class="vbh-sectiontop-actions">
				<a v-if="bookingView === 'journal'" :href="exportJournalUrl" download class="vbh-export-btn" title="Journal als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> CSV</a>
				<NcButton v-if="canWrite" variant="secondary" @click="openImport">
					<template #icon><NcIconSvgWrapper :path="mdiUpload" :size="18" /></template>
					Umsätze importieren
				</NcButton>
			</div>
		</div>

		<div class="vbh-filterbar">
			<input v-model="bookingSearch" type="search" placeholder="Suche…" class="vbh-search">
			<NcSelect
				v-if="bookingView === 'journal'"
				v-model="bookingFilterAccountOption"
				:options="accountOptionsList"
				:filter-by="accountFilterBy"
				label="label"
				:clearable="true"
				placeholder="Konto filtern"
				class="vbh-filter-select"
			/>
			<label v-if="bookingView === 'journal'" class="vbh-checkinline" title="Nur Buchungen ohne angehängten Beleg zeigen (z. B. vor der Kassenprüfung)">
				<input v-model="journalOnlyNoAttachment" type="checkbox">
				nur ohne Beleg
			</label>
		</div>

		<div class="vbh-sectionbody">
			<!-- JOURNAL VIEW -->
			<template v-if="bookingView === 'journal'">
				<div v-if="journalNumberIssues" class="vbh-yearwarn">
					<p class="vbh-warn-inline">
						⚠ Buchungsnummern {{ selectedYear }} nicht lückenlos:
						<template v-if="journalNumberIssues.missing.length">fehlend {{ journalNumberIssues.missing.slice(0, 20).join(', ') }}<template v-if="journalNumberIssues.missing.length > 20"> …</template></template>
						<template v-if="journalNumberIssues.missing.length && journalNumberIssues.duplicates.length"> · </template>
						<template v-if="journalNumberIssues.duplicates.length">doppelt {{ journalNumberIssues.duplicates.join(', ') }}</template>
					</p>
				</div>
				<div v-if="filteredJournalRows.length && isMobile" class="vbh-cardlist">
					<div class="vbh-tablecount">{{ filteredJournalRows.length }}<template v-if="filteredJournalRows.length !== sortedJournalRows.length"> von {{ sortedJournalRows.length }}</template> Buchungssätze</div>
					<template v-for="g in journalCardGroups">
						<div :key="g.key" class="vbh-monthdivider">{{ g.label }}</div>
						<BookingCard v-for="r in g.rows"
							:key="g.key + '-' + r.id"
							:row="r"
							:attachment-count="attachmentCountMap[r.id] ? attachmentCountMap[r.id].count : 0"
							:flow="rowFlow(r)"
							:tappable="canWrite || !!attachmentCountMap[r.id]"
							@open="openBookingCard(r)"
							@paperclip="clickPaperclip(r)" />
					</template>
				</div>
				<div v-else-if="filteredJournalRows.length" class="vbh-tablecard">
					<div class="vbh-tablecount">{{ filteredJournalRows.length }}<template v-if="filteredJournalRows.length !== sortedJournalRows.length"> von {{ sortedJournalRows.length }}</template> Buchungssätze</div>
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable num vbh-col-hide-sm" @click="toggleSort('journal','entryNo')">Nr.{{ sortArrow('journal','entryNo') }}</th>
								<th class="sortable nowrap" @click="toggleSort('journal','date')">Datum{{ sortArrow('journal','date') }}</th>
								<th class="sortable" @click="toggleSort('journal','description')">Beschreibung{{ sortArrow('journal','description') }}</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal','soll')">Soll{{ sortArrow('journal','soll') }}</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal','haben')">Haben{{ sortArrow('journal','haben') }}</th>
								<th class="sortable num" @click="toggleSort('journal','amount')">Betrag{{ sortArrow('journal','amount') }}</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="r in filteredJournalRows" :key="r.id">
								<td class="num strong vbh-col-hide-sm">{{ r.entryNo }}</td>
								<td class="nowrap">{{ formatDate(r.date) }}</td>
								<td class="vbh-purpose" :title="r.description"><span class="vbh-clamp">{{ r.description }}</span></td>
								<td class="vbh-col-hide-sm">{{ r.soll }}</td>
								<td class="vbh-col-hide-sm">{{ r.haben }}</td>
								<td class="num strong">{{ formatMoney(r.amount) }}</td>
								<td class="nowrap right">
									<div class="vbh-actions">
										<NcButton v-if="attachmentCountMap[r.id]"
											variant="tertiary"
											:title="attachmentCountMap[r.id].count === 1 ? 'Beleg anzeigen' : attachmentCountMap[r.id].count + ' Belege'"
											:aria-label="attachmentCountMap[r.id].count + ' Beleg(e)'"
											@click="clickPaperclip(r)">
											<template #icon><NcIconSvgWrapper :path="mdiPaperclip" :size="16" /></template>
										</NcButton>
										<NcButton v-if="canWrite" variant="tertiary" aria-label="Bearbeiten" @click="editBooking(r)">
											<template #icon><NcIconSvgWrapper :path="mdiPencil" :size="20" /></template>
										</NcButton>
										<NcButton v-if="canWrite && txByJournalId[r.id]"
											variant="tertiary"
											:title="'Regel anlegen: ' + txByJournalId[r.id].counterparty + ' künftig automatisch zuordnen'"
											aria-label="Zuordnungsregel anlegen"
											@click="createRuleFromTx(txByJournalId[r.id])">
											<template #icon><NcIconSvgWrapper :path="mdiFlash" :size="16" /></template>
										</NcButton>
										<NcButton v-if="canWrite && !isYearClosed(r.date)" variant="error" aria-label="Löschen" @click="removeBooking(r)">
											<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
										</NcButton>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else-if="bookingSearch || bookingFilterAccountId" name="Keine Treffer" description="Suchfilter anpassen oder löschen." />
				<NcEmptyContent v-else name="Noch keine Buchungssätze" description="Lege mit ‛Neue Buchung' einen ersten Buchungssatz an.">
					<template #action>
						<NcButton variant="tertiary" @click="$emit('help')">Mehr dazu</NcButton>
					</template>
				</NcEmptyContent>
			</template>

			<!-- TRANSACTIONS VIEW (unassigned / assigned) -->
			<template v-else>
				<div v-if="bookingView === 'unassigned' && assignProgress.total > 0" class="vbh-progress">
					<span class="vbh-progress-label">{{ assignProgress.done }} von {{ assignProgress.total }} Bankbuchungen zugeordnet</span>
					<div class="vbh-progress-bar"><div class="vbh-progress-fill" :style="{ width: assignProgress.pct + '%' }"></div></div>
				</div>
				<div v-if="currentTransactions.length && isMobile" class="vbh-cardlist">
					<div class="vbh-tablecount">{{ currentTransactions.length }} Buchungen</div>
					<div v-for="tx in currentTransactions" :key="'m' + tx.id" class="vbh-mcard" :class="tx.status === 'assigned' ? '' : 'open'">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-meta">{{ formatDate(tx.bookingDate) }}</span>
							<span class="vbh-mcard-amount" :class="tx.amount < 0 ? 'neg' : 'pos'">{{ formatMoney(tx.amount) }}</span>
						</div>
						<div class="vbh-mcard-title">{{ tx.counterparty }}</div>
						<div v-if="tx.purpose" class="vbh-mcard-purpose">{{ tx.purpose }}</div>
						<button
							v-if="canWrite && !tx.contraAccountId && suggestionsById[tx.id] && !isYearClosed(tx.bookingDate)"
							type="button"
							class="vbh-suggest-chip vbh-suggest-chip--big"
							@click="applySuggestion(tx)"
						>
							✓ Vorschlag übernehmen: {{ suggestionsById[tx.id].label }}
						</button>
						<button type="button" class="vbh-fieldbtn" :disabled="!canWrite || isYearClosed(tx.bookingDate)" @click="openAccountPicker('assign', tx)">
							<span class="vbh-fieldbtn-text">
								<span class="vbh-fieldbtn-lab">Konto / Kategorie</span>
								<span class="vbh-fieldbtn-val" :class="{ placeholder: !tx.contraAccountId }">{{ tx.contraAccountId ? accountLabel(tx.contraAccountId) : 'Konto wählen…' }}</span>
							</span>
							<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
						</button>
					</div>
				</div>
				<div v-else-if="currentTransactions.length" class="vbh-tablecard">
					<div class="vbh-tablecount">{{ currentTransactions.length }} Buchungen</div>
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable nowrap" @click="toggleSort('transactions','bookingDate')">Datum{{ sortArrow('transactions','bookingDate') }}</th>
								<th class="sortable" @click="toggleSort('transactions','counterparty')">Empfänger/Zahler{{ sortArrow('transactions','counterparty') }}</th>
								<th class="vbh-col-hide-sm">Verwendungszweck</th>
								<th class="sortable num" @click="toggleSort('transactions','amount')">Betrag{{ sortArrow('transactions','amount') }}</th>
								<th>Konto / Kategorie</th>
							</tr>
						</thead>
						<transition-group tag="tbody" name="vbh-row">
							<tr v-for="tx in currentTransactions" :key="tx.id" :class="{ assigned: tx.status === 'assigned', open: tx.status !== 'assigned' }">
								<td class="nowrap">{{ formatDate(tx.bookingDate) }}</td>
								<td>{{ tx.counterparty }}</td>
								<td class="vbh-purpose vbh-col-hide-sm" :title="tx.purpose"><span class="vbh-clamp">{{ tx.purpose }}</span></td>
								<td class="num" :class="amountClass(tx.amount)">{{ formatMoney(tx.amount) }}</td>
								<td class="vbh-assign-cell">
									<div class="vbh-assign-inner">
									<div class="vbh-assign-row">
										<NcSelect
											:model-value="accountOptionFor(tx.contraAccountId)"
											:options="accountOptionsList"
											:filter-by="accountFilterBy"
											:clearable="!!tx.contraAccountId"
											:disabled="!canWrite || isYearClosed(tx.bookingDate)"
											label="label"
											placeholder="– nicht zugeordnet –"
											class="vbh-assign-select"
											@update:model-value="v => onAssign(tx, v ? v.id : '')"
										/>
									</div>
									<button
										v-if="canWrite && !tx.contraAccountId && suggestionsById[tx.id] && !isYearClosed(tx.bookingDate)"
										class="vbh-suggest-chip"
										:title="'Vorschlag übernehmen: ' + suggestionsById[tx.id].label"
										@click="applySuggestion(tx)"
									>
										✓ Vorschlag: {{ suggestionsById[tx.id].label }}
									</button>
									</div>
								</td>
							</tr>
						</transition-group>
					</table>
				</div>
				<NcEmptyContent v-else-if="bookingSearch" name="Keine Treffer" description="Suchfilter anpassen." />
				<NcEmptyContent v-else-if="bookingView === 'unassigned'" name="Alle Buchungen zugeordnet" description="Keine offenen Bankbuchungen – alles erledigt.">
					<template v-if="canWrite" #action>
						<NcButton variant="secondary" @click="openImport">
							<template #icon><NcIconSvgWrapper :path="mdiUpload" :size="18" /></template>
							Neue Umsätze importieren
						</NcButton>
					</template>
				</NcEmptyContent>
			</template>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiDownload, mdiUpload, mdiPaperclip, mdiPencil, mdiFlash, mdiDelete } from '@mdi/js'
import BookingCard from './BookingCard.vue'
import api from '../api.js'
import { formatMoney, formatDate, amountClass } from '../lib/format.js'
import { useAuth } from '../composables/useAuth.js'
import { useYears } from '../composables/useYears.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useJournal } from '../composables/useJournal.js'

export default {
	name: 'BookingsTab',
	components: { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper, BookingCard },
	props: {
		isMobile: { type: Boolean, required: true },
		bookingView: { type: String, required: true },
		attachmentCountMap: { type: Object, required: true },
		// suggestionsById bleibt in App.vue berechnet (wird auch vom
		// AccountPickerSheet-Flow dort gebraucht), hier nur als Prop gelesen.
		suggestionsById: { type: Object, required: true },
		// sort wird per Referenz durchgereicht (auch von der Saldenliste im
		// Berichte-Tab genutzt, App.vue behaelt das gemeinsame Sortier-Objekt).
		sort: { type: Object, required: true },
		openImport: { type: Function, required: true },
		clickPaperclip: { type: Function, required: true },
		openBookingCard: { type: Function, required: true },
		editBooking: { type: Function, required: true },
		createRuleFromTx: { type: Function, required: true },
		removeBooking: { type: Function, required: true },
		openAccountPicker: { type: Function, required: true },
		onAssign: { type: Function, required: true },
		applySuggestion: { type: Function, required: true },
		toggleSort: { type: Function, required: true },
		sortArrow: { type: Function, required: true },
	},
	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const journal = useJournal()
		return {
			canWrite: auth.canWrite,
			...toRefs(years.state),
			isYearClosed: years.isYearClosed,
			accountsSorted: accounts.accountsSorted,
			accountsById: accounts.accountsById,
			journalRows: journal.journalRows,
			...toRefs(journal.state),
			unassignedCount: journal.unassignedCount,
		}
	},
	data() {
		return {
			mdiDownload,
			mdiUpload,
			mdiPaperclip,
			mdiPencil,
			mdiFlash,
			mdiDelete,
			bookingSearch: '',
			bookingFilterAccountId: null,
			journalOnlyNoAttachment: false,
		}
	},
	watch: {
		// Suchfeld beim Wechsel zwischen "Alle Buchungen"/"Zuzuordnen" leeren
		// (Original-Verhalten aus App.vue's bookingView-Watcher).
		bookingView() { this.bookingSearch = '' },
	},
	computed: {
		exportJournalUrl() { return api.exportJournalUrl(this.selectedYear) },
		accountUsageCounts() {
			const counts = {}
			for (const item of this.journalData) {
				for (const l of (item.lines || [])) {
					counts[l.accountId] = (counts[l.accountId] || 0) + 1
				}
			}
			return counts
		},
		frequentAccounts() {
			const counts = this.accountUsageCounts
			return this.accountsSorted
				.filter(a => a.active && counts[a.id])
				.sort((a, b) => counts[b.id] - counts[a.id])
				.slice(0, 5)
		},
		accountsByCategory() {
			const groups = {}
			for (const acc of this.accountsSorted) {
				if (!acc.active) continue
				const cat = acc.category || 'Sonstige'
				;(groups[cat] = groups[cat] || []).push(acc)
			}
			return groups
		},
		accountOptionsList() {
			const opts = []
			if (this.frequentAccounts.length >= 2) {
				opts.push({ id: null, label: '★ Häufig verwendet', $isDisabled: true })
				for (const acc of this.frequentAccounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			for (const [cat, accounts] of Object.entries(this.accountsByCategory)) {
				opts.push({ id: null, label: cat, $isDisabled: true })
				for (const acc of accounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			return opts
		},
		bookingFilterAccountOption: {
			get() {
				if (!this.bookingFilterAccountId) return null
				return this.accountOptionsList.find(o => o.id === this.bookingFilterAccountId) ?? null
			},
			set(v) { this.bookingFilterAccountId = v ? v.id : null },
		},
		sortedJournalRows() { return this.applySort(this.journalRows, this.sort.journal) },
		filteredJournalRows() {
			let rows = this.sortedJournalRows
			const s = this.bookingSearch.trim().toLowerCase()
			if (s) {
				rows = rows.filter(r =>
					(r.description || '').toLowerCase().includes(s) ||
					String(r.entryNo || '').includes(s) ||
					(r.soll || '').toLowerCase().includes(s) ||
					(r.haben || '').toLowerCase().includes(s),
				)
			}
			if (this.bookingFilterAccountId) {
				rows = rows.filter(r =>
					r.debitAccountId === this.bookingFilterAccountId ||
					r.creditAccountId === this.bookingFilterAccountId,
				)
			}
			if (this.journalOnlyNoAttachment) {
				rows = rows.filter(r => !this.attachmentCountMap[r.id])
			}
			return rows
		},
		// Mobil: Journal fest nach Datum absteigend, gruppiert nach Monat
		// (die Spaltenkopf-Sortierung der Tabelle entfällt auf Karten).
		journalCardGroups() {
			const rows = [...this.filteredJournalRows].sort((a, b) =>
				String(b.date || '').localeCompare(String(a.date || '')) || (b.entryNo || 0) - (a.entryNo || 0))
			const names = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']
			const groups = []
			let cur = null
			for (const r of rows) {
				const key = String(r.date || '').slice(0, 7)
				if (!cur || cur.key !== key) {
					const m = parseInt(key.slice(5, 7), 10)
					cur = { key, label: (names[m - 1] || '?') + ' ' + key.slice(0, 4), rows: [] }
					groups.push(cur)
				}
				cur.rows.push(r)
			}
			return groups
		},
		// Kassenprüfung: fehlende/doppelte Buchungsnummern im gewählten Jahr
		// (bei „Alle Jahre" nicht sinnvoll, da je Jahr nummeriert wird).
		journalNumberIssues() {
			if (!this.selectedYear) return null
			const nos = this.journalRows
				.map(r => r.entryNo)
				.filter(n => n != null)
				.map(Number)
				.sort((a, b) => a - b)
			if (!nos.length) return null
			const missing = []
			const duplicates = []
			for (let i = 1; i < nos.length; i++) {
				if (nos[i] === nos[i - 1]) { duplicates.push(nos[i]); continue }
				for (let n = nos[i - 1] + 1; n < nos[i] && missing.length <= 20; n++) missing.push(n)
			}
			if (!missing.length && !duplicates.length) return null
			return { missing, duplicates: [...new Set(duplicates)] }
		},
		// Verknüpft Journal-Zeilen mit ihrer bankstämmigen Buchung, damit in
		// "Alle Buchungen" direkt eine Zuordnungsregel angelegt werden kann.
		txByJournalId() {
			const map = {}
			for (const t of this.transactions) {
				if (t.journalId && t.status === 'assigned' && t.counterparty && t.contraAccountId) {
					map[t.journalId] = t
				}
			}
			return map
		},
		currentTransactions() {
			const status = 'unassigned'
			let txs = this.applySort(
				this.transactions.filter(t => t.status === status),
				this.sort.transactions,
			)
			const s = this.bookingSearch.trim().toLowerCase()
			if (s) {
				txs = txs.filter(t =>
					(t.counterparty || '').toLowerCase().includes(s) ||
					(t.purpose || '').toLowerCase().includes(s) ||
					(t.bookingDate || '').includes(s),
				)
			}
			return txs
		},
		assignProgress() {
			const total = this.transactions.length
			const done = this.transactions.filter(t => t.status === 'assigned').length
			return { total, done, pct: total ? Math.round((done / total) * 100) : 0 }
		},
	},
	methods: {
		formatMoney,
		formatDate,
		amountClass,
		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},
		accountOptionFor(id) {
			return id ? (this.accountOptionsList.find(o => o.id === id) ?? null) : null
		},
		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) return true
			if (option && option.$isDisabled) return false
			if (/^[\d\s]+$/.test(s)) {
				const digits = s.replace(/\s+/g, '')
				const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
				return num.startsWith(digits)
			}
			return String(label || '').toLowerCase().includes(s)
		},
		rowFlow(r) {
			if (r.isSplit) return ''
			const d = this.accountsById[r.debitAccountId]
			const c = this.accountsById[r.creditAccountId]
			const dIn = !!(d && d.isBank)
			const cOut = !!(c && c.isBank)
			if (dIn && !cOut) return 'in'
			if (cOut && !dIn) return 'out'
			return ''
		},
		applySort(rows, state, lexKeys = []) {
			if (!state || !state.key) return rows
			const f = state.dir === 'asc' ? 1 : -1
			const lex = lexKeys.includes(state.key)
			return rows.slice().sort((a, b) => {
				let x = a[state.key]; let y = b[state.key]
				if (x === null || x === undefined) x = ''
				if (y === null || y === undefined) y = ''
				if (lex) {
					const sx = String(x); const sy = String(y)
					return (sx < sy ? -1 : sx > sy ? 1 : 0) * f
				}
				return (x < y ? -1 : x > y ? 1 : 0) * f
			})
		},
	},
}
</script>
