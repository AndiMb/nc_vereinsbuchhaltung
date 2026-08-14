<template>
	<div style="display: contents;">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button :class="{ active: bookingView === 'journal' }" @click="$emit('update:booking-view', 'journal')">
					{{ t('Alle Buchungen') }}
				</button>
				<button :class="{ active: bookingView === 'unassigned' }" @click="$emit('update:booking-view', 'unassigned')">
					{{ t('Zuzuordnen') }}
					<span v-if="unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
				</button>
				<button :class="{ active: bookingView === 'openitems' }" @click="$emit('update:booking-view', 'openitems')">
					{{ t('Offene Posten') }}
					<span v-if="overdueOpenItemsCount > 0" class="vbh-badge vbh-badge--alert">{{ overdueOpenItemsCount }}</span>
				</button>
				<button v-if="canWrite" :class="{ active: bookingView === 'rules' }" @click="$emit('update:booking-view', 'rules')">
					{{ t('Regeln') }}
				</button>
			</div>
			<div class="vbh-sectiontop-actions">
				<a v-if="bookingView === 'journal'"
					:href="exportJournalUrl"
					download
					class="vbh-export-btn"
					:title="t('Journal als CSV exportieren')"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> CSV</a>
				<NcButton v-if="canWrite" variant="secondary" @click="openImport">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUpload" :size="18" />
					</template>
					{{ t('Umsätze importieren') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-filterbar">
			<input v-model="bookingSearch"
				type="search"
				:placeholder="t('Suche…')"
				class="vbh-search">
			<NcSelect v-if="bookingView === 'journal'"
				v-model="bookingFilterAccountOption"
				:options="accountOptionsList"
				:filter-by="accountFilterBy"
				label="label"
				:clearable="true"
				:placeholder="t('Konto filtern')"
				class="vbh-filter-select" />
			<label v-if="bookingView === 'journal'" class="vbh-checkinline" :title="t('Nur Buchungen ohne angehängten Beleg zeigen (z. B. vor der Kassenprüfung)')">
				<input v-model="journalOnlyNoAttachment" type="checkbox">
				{{ t('nur ohne Beleg') }}
			</label>
		</div>

		<div class="vbh-sectionbody">
			<!-- JOURNAL VIEW -->
			<template v-if="bookingView === 'journal'">
				<div v-if="journalNumberIssues" class="vbh-yearwarn">
					<p class="vbh-warn-inline">
						{{ t('⚠ Buchungsnummern {year} nicht lückenlos:', { year: selectedYear }) }}
						<template v-if="journalNumberIssues.missing.length">
							{{ t('fehlend {list}', { list: journalNumberIssues.missing.slice(0, 20).join(', ') }) }}<template v-if="journalNumberIssues.missing.length > 20">
								…
							</template>
						</template>
						<template v-if="journalNumberIssues.missing.length && journalNumberIssues.duplicates.length">
							·
						</template>
						<template v-if="journalNumberIssues.duplicates.length">
							{{ t('doppelt {list}', { list: journalNumberIssues.duplicates.join(', ') }) }}
						</template>
					</p>
				</div>
				<div v-if="filteredJournalRows.length && isMobile" class="vbh-cardlist">
					<div class="vbh-tablecount">
						{{ filteredJournalRows.length !== sortedJournalRows.length ? t('{n} von {total} Buchungssätze', { n: filteredJournalRows.length, total: sortedJournalRows.length }) : t('{n} Buchungssätze', { n: filteredJournalRows.length }) }}
					</div>
					<template v-for="g in journalCardGroups">
						<div :key="g.key" class="vbh-monthdivider">
							{{ g.label }}
						</div>
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
					<div class="vbh-tablecount">
						{{ filteredJournalRows.length !== sortedJournalRows.length ? t('{n} von {total} Buchungssätze', { n: filteredJournalRows.length, total: sortedJournalRows.length }) : t('{n} Buchungssätze', { n: filteredJournalRows.length }) }}
					</div>
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable num vbh-col-hide-sm" @click="toggleSort('journal','entryNo')">
									{{ t('Nr.') }}{{ sortArrow('journal','entryNo') }}
								</th>
								<th class="sortable nowrap" @click="toggleSort('journal','date')">
									{{ t('Datum') }}{{ sortArrow('journal','date') }}
								</th>
								<th class="sortable" @click="toggleSort('journal','description')">
									{{ t('Beschreibung') }}{{ sortArrow('journal','description') }}
								</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal','soll')">
									{{ t('Soll') }}{{ sortArrow('journal','soll') }}
								</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal','haben')">
									{{ t('Haben') }}{{ sortArrow('journal','haben') }}
								</th>
								<th class="sortable num" @click="toggleSort('journal','amount')">
									{{ t('Betrag') }}{{ sortArrow('journal','amount') }}
								</th>
								<th />
							</tr>
						</thead>
						<tbody>
							<tr v-for="r in filteredJournalRows" :key="r.id">
								<td class="num strong vbh-col-hide-sm">
									{{ r.entryNo }}
								</td>
								<td class="nowrap">
									{{ formatDate(r.date) }}
								</td>
								<td class="vbh-purpose" :title="r.description">
									<span class="vbh-clamp">{{ r.description }}</span>
								</td>
								<td class="vbh-col-hide-sm">
									{{ r.soll }}
								</td>
								<td class="vbh-col-hide-sm">
									{{ r.haben }}
								</td>
								<td class="num strong">
									{{ formatMoney(r.amount) }}
								</td>
								<td class="nowrap right">
									<div class="vbh-actions">
										<NcButton v-if="attachmentCountMap[r.id]"
											variant="tertiary"
											:title="attachmentCountMap[r.id].count === 1 ? t('Beleg anzeigen') : t('{n} Belege', { n: attachmentCountMap[r.id].count })"
											:aria-label="t('{n} Beleg(e)', { n: attachmentCountMap[r.id].count })"
											@click="clickPaperclip(r)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiPaperclip" :size="16" />
											</template>
										</NcButton>
										<NcButton v-if="canWrite"
											variant="tertiary"
											:aria-label="t('Bearbeiten')"
											@click="editBooking(r)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiPencil" :size="20" />
											</template>
										</NcButton>
										<!-- Seltener genutzte Aktionen in einem Menü statt als eigene
										     Buttons, sonst wird die Zeile durch bis zu 4 Icon-Buttons
										     zweizeilig (siehe .vbh-table thead th:empty in styles.css). -->
										<NcActions v-if="canWrite && (txByJournalId[r.id] || !isYearClosed(r.date))" :force-menu="true">
											<NcActionButton v-if="txByJournalId[r.id]"
												:title="t('Regel anlegen: {counterparty} künftig automatisch zuordnen', { counterparty: txByJournalId[r.id].counterparty })"
												@click="createRuleFromTx(txByJournalId[r.id])">
												<template #icon>
													<NcIconSvgWrapper :path="mdiFlash" :size="16" />
												</template>
												{{ t('Regel anlegen') }}
											</NcActionButton>
											<NcActionButton v-if="!isYearClosed(r.date)" @click="removeBooking(r)">
												<template #icon>
													<NcIconSvgWrapper :path="mdiDelete" :size="16" />
												</template>
												{{ t('Löschen') }}
											</NcActionButton>
										</NcActions>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else-if="bookingSearch || bookingFilterAccountId" :name="t('Keine Treffer')" :description="t('Suchfilter anpassen oder löschen.')" />
				<NcEmptyContent v-else :name="t('Noch keine Buchungssätze')" :description="t('Lege mit ‛Neue Buchung\' einen ersten Buchungssatz an.')">
					<template #action>
						<NcButton variant="tertiary" @click="$emit('help')">
							{{ t('Mehr dazu') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</template>

			<!-- TRANSACTIONS VIEW (unassigned / assigned) -->
			<template v-else-if="bookingView === 'unassigned'">
				<div v-if="assignProgress.total > 0" class="vbh-progress">
					<span class="vbh-progress-label">{{ t('{done} von {total} Bankbuchungen zugeordnet', { done: assignProgress.done, total: assignProgress.total }) }}</span>
					<div class="vbh-progress-bar">
						<div class="vbh-progress-fill" :style="{ width: assignProgress.pct + '%' }" />
					</div>
				</div>
				<div v-if="currentTransactions.length && isMobile" class="vbh-cardlist">
					<div class="vbh-tablecount">
						{{ t('{n} Buchungen', { n: currentTransactions.length }) }}
					</div>
					<div v-for="tx in currentTransactions"
						:key="'m' + tx.id"
						class="vbh-mcard"
						:class="tx.status === 'assigned' ? '' : 'open'">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-meta">{{ formatDate(tx.bookingDate) }}</span>
							<span class="vbh-mcard-amount" :class="tx.amount < 0 ? 'neg' : 'pos'">{{ formatMoney(tx.amount) }}</span>
							<NcButton v-if="canWrite && tx.status === 'unassigned' && !isYearClosed(tx.bookingDate)"
								variant="tertiary"
								:aria-label="t('Umsatz löschen')"
								:title="t('Umsatz löschen (z. B. Dublette)')"
								@click="removeTransaction(tx)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="18" />
								</template>
							</NcButton>
						</div>
						<div class="vbh-mcard-title">
							{{ tx.counterparty }}
						</div>
						<div v-if="tx.purpose" class="vbh-mcard-purpose">
							{{ tx.purpose }}
						</div>
						<button v-if="canWrite && !isSplitAssigned(tx) && !tx.contraAccountId && suggestionsById[tx.id] && !isYearClosed(tx.bookingDate)"
							type="button"
							class="vbh-suggest-chip vbh-suggest-chip--big"
							@click="applySuggestion(tx)">
							{{ t('✓ Vorschlag übernehmen: {label}', { label: suggestionsById[tx.id].label }) }}
						</button>
						<template v-if="isSplitAssigned(tx)">
							<span class="vbh-split-badge">{{ t('Aufgeteilt auf mehrere Konten') }}</span>
							<button v-if="canWrite && !isYearClosed(tx.bookingDate)"
								type="button"
								class="vbh-suggest-chip"
								@click="onAssign(tx, '')">
								{{ t('Zuordnung aufheben') }}
							</button>
						</template>
						<template v-else>
							<button type="button"
								class="vbh-fieldbtn"
								:disabled="!canWrite || isYearClosed(tx.bookingDate)"
								@click="openAccountPicker('assign', tx)">
								<span class="vbh-fieldbtn-text">
									<span class="vbh-fieldbtn-lab">{{ t('Konto / Kategorie') }}</span>
									<span class="vbh-fieldbtn-val" :class="{ placeholder: !tx.contraAccountId }">{{ tx.contraAccountId ? accountLabel(tx.contraAccountId) : t('Konto wählen…') }}</span>
								</span>
								<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
							</button>
							<button v-if="canWrite && !isYearClosed(tx.bookingDate)"
								type="button"
								class="vbh-suggest-chip"
								@click="openSplitAssign(tx)">
								{{ t('Aufteilen…') }}
							</button>
						</template>
					</div>
				</div>
				<div v-else-if="currentTransactions.length" class="vbh-tablecard">
					<div class="vbh-tablecount">
						{{ t('{n} Buchungen', { n: currentTransactions.length }) }}
					</div>
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable nowrap" @click="toggleSort('transactions','bookingDate')">
									{{ t('Datum') }}{{ sortArrow('transactions','bookingDate') }}
								</th>
								<th class="sortable" @click="toggleSort('transactions','counterparty')">
									{{ t('Empfänger/Zahler') }}{{ sortArrow('transactions','counterparty') }}
								</th>
								<th class="vbh-col-hide-sm">
									{{ t('Verwendungszweck') }}
								</th>
								<th class="sortable num" @click="toggleSort('transactions','amount')">
									{{ t('Betrag') }}{{ sortArrow('transactions','amount') }}
								</th>
								<th>{{ t('Konto / Kategorie') }}</th>
							</tr>
						</thead>
						<transition-group tag="tbody" name="vbh-row">
							<tr v-for="tx in currentTransactions" :key="tx.id" :class="{ assigned: tx.status === 'assigned', open: tx.status !== 'assigned' }">
								<td class="nowrap">
									{{ formatDate(tx.bookingDate) }}
								</td>
								<td>{{ tx.counterparty }}</td>
								<td class="vbh-purpose vbh-col-hide-sm" :title="tx.purpose">
									<span class="vbh-clamp">{{ tx.purpose }}</span>
								</td>
								<td class="num" :class="amountClass(tx.amount)">
									{{ formatMoney(tx.amount) }}
								</td>
								<td class="vbh-assign-cell">
									<!-- Aufgeteilter Umsatz: das Auswahlfeld fasst nur ein Konto
									     und stuende hier leer da. Die Konten selbst zeigt der
									     Kontoauszug bzw. das Journal. -->
									<div v-if="isSplitAssigned(tx)" class="vbh-assign-inner">
										<span class="vbh-split-badge">{{ t('Aufgeteilt auf mehrere Konten') }}</span>
										<button v-if="canWrite && !isYearClosed(tx.bookingDate)"
											class="vbh-suggest-chip"
											:title="t('Zuordnung aufheben und neu vergeben')"
											@click="onAssign(tx, '')">
											{{ t('Zuordnung aufheben') }}
										</button>
									</div>
									<div v-else class="vbh-assign-inner">
										<div class="vbh-assign-row">
											<NcSelect :model-value="accountOptionFor(tx.contraAccountId)"
												:options="accountOptionsList"
												:filter-by="accountFilterBy"
												:clearable="!!tx.contraAccountId"
												:disabled="!canWrite || isYearClosed(tx.bookingDate)"
												label="label"
												:placeholder="t('– nicht zugeordnet –')"
												class="vbh-assign-select"
												@update:model-value="v => onAssign(tx, v ? v.id : '')" />
										</div>
										<button v-if="canWrite && !tx.contraAccountId && suggestionsById[tx.id] && !isYearClosed(tx.bookingDate)"
											class="vbh-suggest-chip"
											:title="t('Vorschlag übernehmen: {label}', { label: suggestionsById[tx.id].label })"
											@click="applySuggestion(tx)">
											{{ t('✓ Vorschlag: {label}', { label: suggestionsById[tx.id].label }) }}
										</button>
										<button v-if="canWrite && !isYearClosed(tx.bookingDate)"
											class="vbh-suggest-chip"
											:title="t('Den Umsatz auf mehrere Gegenkonten verteilen')"
											@click="openSplitAssign(tx)">
											{{ t('Aufteilen…') }}
										</button>
										<NcButton v-if="canWrite && !isYearClosed(tx.bookingDate)"
											variant="tertiary"
											:aria-label="t('Umsatz löschen')"
											:title="t('Umsatz löschen (z. B. Dublette)')"
											@click="removeTransaction(tx)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiDelete" :size="18" />
											</template>
										</NcButton>
									</div>
								</td>
							</tr>
						</transition-group>
					</table>
				</div>
				<NcEmptyContent v-else-if="bookingSearch" :name="t('Keine Treffer')" :description="t('Suchfilter anpassen.')" />
				<NcEmptyContent v-else :name="t('Alle Buchungen zugeordnet')" :description="t('Keine offenen Bankbuchungen – alles erledigt.')">
					<template v-if="canWrite" #action>
						<NcButton variant="secondary" @click="openImport">
							<template #icon>
								<NcIconSvgWrapper :path="mdiUpload" :size="18" />
							</template>
							{{ t('Neue Umsätze importieren') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</template>

			<!-- OFFENE POSTEN -->
			<template v-else-if="bookingView === 'openitems'">
				<div v-if="canWrite" class="vbh-card">
					<h4>{{ t('Neuer offener Posten') }}</h4>
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('Debitor') }}<input v-model="openItemForm.debtor" :placeholder="t('z. B. Max Mustermann')"></label>
						<label>{{ t('Betrag (€)') }}<input v-model.number="openItemForm.amount"
							type="number"
							step="0.01"
							min="0.01"
							class="vbh-num"></label>
						<label>{{ t('Fällig am') }}<input v-model="openItemForm.dueDate" type="date"></label>
					</div>
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('Konto (für die spätere Buchung)') }}
							<NcSelect v-model="openItemAccountOption"
								:options="accountOptionsList"
								:filter-by="accountFilterBy"
								label="label"
								:placeholder="t('optional')"
								:clearable="true" />
						</label>
						<label class="vbh-grow">{{ t('Notiz') }}<input v-model="openItemForm.description" :placeholder="t('optional')"></label>
						<NcButton variant="primary" :disabled="!openItemForm.debtor || !openItemForm.amount" @click="createOpenItem">
							{{ t('Anlegen') }}
						</NcButton>
					</div>
				</div>

				<div class="vbh-filterbar">
					<button v-for="f in openItemFilterOptions"
						:key="f.key"
						type="button"
						class="vbh-chip"
						:class="{ active: openItemFilter === f.key }"
						@click="openItemFilter = f.key">
						{{ f.label }}
					</button>
				</div>

				<div v-if="filteredOpenItems.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Debitor') }}</th>
								<th class="vbh-col-hide-sm">
									{{ t('Notiz') }}
								</th>
								<th class="nowrap">
									{{ t('Fällig') }}
								</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
								<th class="vbh-col-hide-sm">
									{{ t('Konto') }}
								</th>
								<th>{{ t('Status') }}</th>
								<th />
							</tr>
						</thead>
						<tbody>
							<tr v-for="o in filteredOpenItems" :key="o.id">
								<td>{{ o.debtor }}</td>
								<td class="vbh-col-hide-sm vbh-purpose">
									<span class="vbh-clamp">{{ o.description }}</span>
								</td>
								<td class="nowrap">
									{{ o.dueDate ? formatDate(o.dueDate) : '–' }}
									<span v-if="o.overdue" class="vbh-warn-inline">{{ t('überfällig') }}</span>
								</td>
								<td class="num strong">
									{{ formatMoney(o.amount) }}
								</td>
								<td class="vbh-col-hide-sm">
									{{ o.accountId ? accountLabel(o.accountId) : '' }}
								</td>
								<td><span class="vbh-typetag" :class="o.status">{{ openItemStatusLabel(o.status) }}</span></td>
								<td class="right nowrap">
									<template v-if="canWrite && o.status === 'open'">
										<NcButton variant="tertiary" @click="markOpenItemPaid(o)">
											{{ t('Bezahlt') }}
										</NcButton>
										<NcButton variant="tertiary" @click="cancelOpenItem(o)">
											{{ t('Stornieren') }}
										</NcButton>
									</template>
									<NcButton v-else-if="canWrite" variant="tertiary" @click="reopenOpenItem(o)">
										{{ t('Wieder öffnen') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else :name="t('Keine offenen Posten')" :description="t('Lege oben einen neuen offenen Posten an, z. B. einen unbezahlten Mitgliedsbeitrag.')" />
			</template>

			<!-- REGELN -->
			<template v-else-if="bookingView === 'rules' && canWrite">
				<RulesPanel />
			</template>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcActions, NcActionButton, NcSelect, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiDownload, mdiUpload, mdiPaperclip, mdiPencil, mdiFlash, mdiDelete } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import BookingCard from './BookingCard.vue'
import RulesPanel from './RulesPanel.vue'
import api from '../api.js'
import { formatMoney, formatDate, amountClass, errMsg } from '../lib/format.js'
import { useAuth } from '../composables/useAuth.js'
import { useYears } from '../composables/useYears.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useJournal } from '../composables/useJournal.js'
import { useOpenItems } from '../composables/useOpenItems.js'
import { useSort } from '../composables/useSort.js'

export default {
	name: 'BookingsTab',
	components: { NcButton, NcActions, NcActionButton, NcSelect, NcEmptyContent, NcIconSvgWrapper, BookingCard, RulesPanel },
	props: {
		isMobile: { type: Boolean, required: true },
		bookingView: { type: String, required: true },
		attachmentCountMap: { type: Object, required: true },
		// suggestionsById bleibt in App.vue berechnet (wird auch vom
		// AccountPickerSheet-Flow dort gebraucht), hier nur als Prop gelesen.
		suggestionsById: { type: Object, required: true },
		openImport: { type: Function, required: true },
		clickPaperclip: { type: Function, required: true },
		openBookingCard: { type: Function, required: true },
		editBooking: { type: Function, required: true },
		createRuleFromTx: { type: Function, required: true },
		removeBooking: { type: Function, required: true },
		removeTransaction: { type: Function, required: true },
		openAccountPicker: { type: Function, required: true },
		onAssign: { type: Function, required: true },
		openSplitAssign: { type: Function, required: true },
		applySuggestion: { type: Function, required: true },
	},
	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const journal = useJournal()
		const openItemsC = useOpenItems()
		return {
			canWrite: auth.canWrite,
			...toRefs(years.state),
			isYearClosed: years.isYearClosed,
			accountsSorted: accounts.accountsSorted,
			accountsById: accounts.accountsById,
			journalRows: journal.journalRows,
			...toRefs(journal.state),
			unassignedCount: journal.unassignedCount,
			...toRefs(openItemsC.state),
			overdueOpenItemsCount: openItemsC.overdueCount,
			loadOpenItems: openItemsC.loadOpenItems,
			// Sortierung aus dem gemeinsamen Zustand (dieselbe Einstellung nutzt
			// die Saldenliste im Berichte-Tab), nicht mehr als Prop aus App.vue.
			...useSort(),
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
			openItemForm: { debtor: '', description: '', amount: '', dueDate: '', accountId: null },
			openItemFilter: 'open',
			openItemFilterOptions: [
				{ key: 'open', label: this.t('Offen') },
				{ key: 'paid', label: this.t('Bezahlt') },
				{ key: 'cancelled', label: this.t('Storniert') },
				{ key: 'all', label: this.t('Alle') },
			],
		}
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
				const cat = acc.category || this.t('Sonstige')
				;(groups[cat] = groups[cat] || []).push(acc)
			}
			return groups
		},
		accountOptionsList() {
			const opts = []
			if (this.frequentAccounts.length >= 2) {
				opts.push({ id: null, label: this.t('★ Häufig verwendet'), $isDisabled: true })
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
					(r.description || '').toLowerCase().includes(s)
					|| String(r.entryNo || '').includes(s)
					|| (r.soll || '').toLowerCase().includes(s)
					|| (r.haben || '').toLowerCase().includes(s),
				)
			}
			if (this.bookingFilterAccountId) {
				rows = rows.filter(r =>
					r.debitAccountId === this.bookingFilterAccountId
					|| r.creditAccountId === this.bookingFilterAccountId,
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
			const names = [this.t('Januar'), this.t('Februar'), this.t('März'), this.t('April'), this.t('Mai'), this.t('Juni'), this.t('Juli'), this.t('August'), this.t('September'), this.t('Oktober'), this.t('November'), this.t('Dezember')]
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
					(t.counterparty || '').toLowerCase().includes(s)
					|| (t.purpose || '').toLowerCase().includes(s)
					|| (t.bookingDate || '').includes(s),
				)
			}
			return txs
		},
		assignProgress() {
			const total = this.transactions.length
			const done = this.transactions.filter(t => t.status === 'assigned').length
			return { total, done, pct: total ? Math.round((done / total) * 100) : 0 }
		},
		openItemAccountOption: {
			get() { return this.accountOptionFor(this.openItemForm.accountId) },
			set(v) { this.openItemForm.accountId = v ? v.id : null },
		},
		filteredOpenItems() {
			if (this.openItemFilter === 'all') return this.openItems
			return this.openItems.filter(o => o.status === this.openItemFilter)
		},
	},
	watch: {
		// Suchfeld beim Wechsel zwischen "Alle Buchungen"/"Zuzuordnen" leeren
		// (Original-Verhalten aus App.vue's bookingView-Watcher).
		bookingView(v) {
			this.bookingSearch = ''
			if (v === 'openitems') this.loadOpenItems()
		},
	},
	mounted() {
		if (this.bookingView === 'openitems') this.loadOpenItems()
	},
	methods: {
		formatMoney,
		formatDate,
		amountClass,
		/**
		 * Zugeordnet, aber ohne einzelnes Gegenkonto = der Umsatz wurde auf
		 * mehrere verteilt. contra_account_id bleibt dann leer, siehe
		 * BookingService::doAssign().
		 */
		isSplitAssigned(tx) {
			return tx.status === 'assigned' && !tx.contraAccountId
		},
		openItemStatusLabel(status) {
			return { open: this.t('Offen'), paid: this.t('Bezahlt'), cancelled: this.t('Storniert') }[status] || status
		},
		async createOpenItem() {
			if (!this.openItemForm.debtor || !this.openItemForm.amount) return
			try {
				await api.createOpenItem({
					debtor: this.openItemForm.debtor,
					description: this.openItemForm.description || null,
					amount: Number(this.openItemForm.amount),
					dueDate: this.openItemForm.dueDate || null,
					accountId: this.openItemForm.accountId,
				})
				this.openItemForm = { debtor: '', description: '', amount: '', dueDate: '', accountId: null }
				await this.loadOpenItems()
				showSuccess(this.t('Offener Posten angelegt.'))
			} catch (e) { showError(errMsg(e, this.t('Offener Posten konnte nicht angelegt werden'))) }
		},
		async markOpenItemPaid(o) {
			try { await api.markOpenItemPaid(o.id); await this.loadOpenItems(); showSuccess(this.t('Als bezahlt markiert.')) } catch (e) { showError(errMsg(e, this.t('Konnte nicht als bezahlt markiert werden'))) }
		},
		async cancelOpenItem(o) {
			try { await api.cancelOpenItem(o.id); await this.loadOpenItems(); showSuccess(this.t('Storniert.')) } catch (e) { showError(errMsg(e, this.t('Konnte nicht storniert werden'))) }
		},
		async reopenOpenItem(o) {
			try { await api.reopenOpenItem(o.id); await this.loadOpenItems(); showSuccess(this.t('Wieder geöffnet.')) } catch (e) { showError(errMsg(e, this.t('Konnte nicht wieder geöffnet werden'))) }
		},
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
	},
}
</script>
