<template>
	<div style="display: contents;">
		<div v-if="!isMobile || !selectedAccountId" class="vbh-tree">
			<div class="vbh-treehead">
				<NcButton v-if="canWrite" variant="primary" size="small" @click="openNewAccount">
					<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="18" /></template>
					Konto
				</NcButton>
				<span v-else></span>
				<div class="vbh-treeactions">
					<NcButton variant="tertiary" @click="expandAll">alle auf</NcButton>
					<NcButton variant="tertiary" @click="collapseAll">alle zu</NcButton>
				</div>
			</div>

			<div class="vbh-treesearch">
				<input v-model="accountSearch" type="search" placeholder="Konto suchen…" class="vbh-search vbh-search--full">
			</div>

			<p v-if="accounts.length === 0" class="vbh-hint">
				Noch keine Konten.<br>
				<NcButton v-if="canWrite" variant="primary" @click="seedAccounts">Standard-Kontenrahmen anlegen</NcButton>
				<NcButton variant="tertiary" @click="$emit('help')">Mehr dazu</NcButton>
			</p>

			<div class="vbh-treelist">
				<div v-for="node in currentTree" :key="node.id"
					class="vbh-treenode" :class="{ selected: node.id === selectedAccountId, group: node.hasChildren }"
					:style="{ paddingLeft: (8 + node.depth * 18) + 'px' }"
					@click="selectAccount(node)">
					<button v-if="node.hasChildren && !accountSearch" class="vbh-caret" :class="{ open: expanded[node.id] }" @click.stop="toggleExpand(node.id)">›</button>
					<span v-else class="vbh-caret empty">·</span>
					<span class="vbh-treenum">{{ node.number }}</span>
					<span class="vbh-treename">{{ node.name }}</span>
					<span class="vbh-treesaldo" :class="[amountClass(balanceFor(node.id)), { zero: !balanceFor(node.id) }]">{{ formatMoney(balanceFor(node.id)) }}</span>
				</div>
			</div>
		</div>

		<div v-if="!isMobile || selectedAccountId" class="vbh-detail">
			<div v-if="isMobile" class="vbh-backbar">
				<button type="button" class="vbh-backbtn" @click="closeAccountDetail">‹ Konten</button>
			</div>
			<p v-if="!selectedAccount" class="vbh-empty vbh-detailhint">Konto links auswählen, um Buchungen anzuzeigen.</p>

			<template v-else>
				<div class="vbh-detailhead">
					<div>
						<h3>{{ selectedAccount.number }} · {{ selectedAccount.name }}</h3>
						<span class="vbh-typetag" :class="selectedAccount.type">{{ typeLabel(selectedAccount.type) }}</span>
						<span v-if="selectedAccount.category" class="vbh-cat">{{ selectedAccount.category }}</span>
					</div>
					<span v-if="canWrite" class="nowrap">
						<NcButton variant="tertiary" aria-label="Konto bearbeiten" @click="openEditAccount(selectedAccount)">
							<template #icon><NcIconSvgWrapper :path="mdiPencil" :size="20" /></template>
						</NcButton>
						<NcButton variant="error" aria-label="Konto löschen" @click="deleteAccount(selectedAccount)">
							<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
						</NcButton>
					</span>
				</div>

				<!-- Eröffnungssaldo nur für Geldkonten: nur deren Bestand geht über Jahresgrenzen. -->
				<div v-if="canWrite && selectedAccount.isBank" class="vbh-opening">
					<span>Eröffnungssaldo:</span>
					<input v-model.number="openingForm[selectedAccount.id].amount" type="number" step="0.01" class="vbh-num">
					<input v-model="openingForm[selectedAccount.id].date" type="date" class="vbh-date">
					<NcButton variant="primary" size="small" @click="saveOpening(selectedAccount)">Speichern</NcButton>
				</div>

				<div v-if="statement" class="vbh-statementbar">
					<NcCheckboxRadioSwitch :model-value="statementIncludeChildren" @update:model-value="onIncludeChildrenChange">inkl. Unterkonten</NcCheckboxRadioSwitch>
					<div class="vbh-previewsummary">
						<span class="vbh-badge muted">{{ statement.totals.count }} Buchungen</span>
						<span class="vbh-badge muted">Soll {{ formatMoney(statement.totals.debit) }}</span>
						<span class="vbh-badge muted">Haben {{ formatMoney(statement.totals.credit) }}</span>
						<span class="vbh-badge pos">Saldo {{ formatMoney(statement.totals.balance) }}</span>
					</div>
				</div>

				<div v-if="statementRows.length && isMobile" class="vbh-cardlist">
					<div v-if="statement.carry" class="vbh-mcard">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">Saldovortrag aus Vorjahr</span>
							<span class="vbh-mcard-amount" :class="amountClass(statement.carry)">{{ formatMoney(statement.carry) }}</span>
						</div>
					</div>
					<div v-for="(row, i) in statementRows" :key="'m' + i" class="vbh-mcard">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-meta">#{{ row.entryNo }} · {{ formatDate(row.date) }}</span>
							<span class="vbh-mcard-amount" :class="statementRowNet(row) < 0 ? 'neg' : 'pos'">{{ formatMoney(statementRowNet(row)) }}</span>
						</div>
						<div class="vbh-mcard-title">{{ row.description }}</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ row.contra }}</span>
							<span class="vbh-mcard-meta">Saldo {{ formatMoney(row.saldo) }}</span>
						</div>
					</div>
					<div class="vbh-mcard vbh-mcard--sum">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">Saldo{{ selectedYear ? ' ' + selectedYear : '' }}</span>
							<span class="vbh-mcard-amount" :class="amountClass(statement.totals.balance)">{{ formatMoney(statement.totals.balance) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ statement.totals.count }} Buchungen · Soll {{ formatMoney(statement.totals.debit) }} · Haben {{ formatMoney(statement.totals.credit) }}</span>
						</div>
					</div>
				</div>
				<div v-else-if="statementRows.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead><tr><th class="num vbh-col-hide-sm">Nr.</th><th class="nowrap">Datum</th><th>Beschreibung</th><th class="vbh-col-hide-sm">Gegenkonto</th><th class="num vbh-col-hide-sm">Soll</th><th class="num vbh-col-hide-sm">Haben</th><th class="num">Saldo</th></tr></thead>
						<tbody>
							<tr v-if="statement.carry" class="vbh-carryrow">
								<td class="vbh-col-hide-sm"></td>
								<td class="nowrap">{{ formatDate(selectedYear + '-01-01') }}</td>
								<td><em>Saldovortrag aus Vorjahr</em></td>
								<td class="vbh-col-hide-sm"></td>
								<td class="num vbh-col-hide-sm"></td>
								<td class="num vbh-col-hide-sm"></td>
								<td class="num strong" :class="amountClass(statement.carry)">{{ formatMoney(statement.carry) }}</td>
							</tr>
							<tr v-for="(row, i) in statementRows" :key="i">
								<td class="num vbh-col-hide-sm">{{ row.entryNo }}</td>
								<td class="nowrap">{{ formatDate(row.date) }}</td>
								<td class="vbh-purpose" :title="row.description"><span class="vbh-clamp">{{ row.description }}</span></td>
								<td class="vbh-col-hide-sm">{{ row.contra }}</td>
								<td class="num vbh-col-hide-sm">{{ row.debit ? formatMoney(row.debit) : '' }}</td>
								<td class="num vbh-col-hide-sm">{{ row.credit ? formatMoney(row.credit) : '' }}</td>
								<td class="num strong" :class="amountClass(row.saldo)">{{ formatMoney(row.saldo) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else-if="statement" class="vbh-empty">Keine Buchungen auf diesem Konto{{ statementIncludeChildren ? ' (inkl. Unterkonten)' : '' }}.</p>
			</template>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiPlus, mdiPencil, mdiDelete } from '@mdi/js'
import { formatMoney, formatDate, typeLabel, amountClass } from '../lib/format.js'
import { useAuth } from '../composables/useAuth.js'
import { useYears } from '../composables/useYears.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useBalances } from '../composables/useBalances.js'

export default {
	name: 'AccountsTab',
	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper },
	props: {
		isMobile: { type: Boolean, required: true },
		// selectedAccountId/statement/statementIncludeChildren bleiben in App.vue
		// (auch von mounted()/refreshAfterRemoteChange()/resetAll()/saveAccount()
		// gebraucht) - hier nur als Props durchgereicht, nicht lokal geklont.
		selectedAccountId: { type: [Number, String], default: null },
		statement: { type: Object, default: null },
		statementIncludeChildren: { type: Boolean, required: true },
		// openingForm wird per Referenz durchgereicht (wie bookingForm bei
		// BookingDialog): App.vue befuellt es in loadAccounts(), das Formular
		// hier mutiert die Werte direkt im selben Objekt.
		openingForm: { type: Object, required: true },
		selectAccount: { type: Function, required: true },
		closeAccountDetail: { type: Function, required: true },
		reloadStatement: { type: Function, required: true },
		openNewAccount: { type: Function, required: true },
		openEditAccount: { type: Function, required: true },
		deleteAccount: { type: Function, required: true },
		saveOpening: { type: Function, required: true },
		seedAccounts: { type: Function, required: true },
	},
	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const balances = useBalances()
		return {
			canWrite: auth.canWrite,
			...toRefs(years.state),
			...toRefs(accounts.state),
			accountsById: accounts.accountsById,
			childrenOf: accounts.childrenOf,
			...toRefs(balances.state),
		}
	},
	data() {
		return {
			mdiPlus,
			mdiPencil,
			mdiDelete,
			accountSearch: '',
			expanded: {},
		}
	},
	computed: {
		selectedAccount() {
			return this.selectedAccountId ? this.accountsById[this.selectedAccountId] : null
		},
		visibleTree() {
			const byNum = list => list.slice().sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
			const roots = byNum(this.accounts.filter(a => !a.parentId))
			const out = []
			const walk = (acc, depth) => {
				const kids = this.childrenOf[acc.id] || []
				out.push({ id: acc.id, number: acc.number, name: acc.name, type: acc.type, depth, hasChildren: kids.length > 0 })
				if (kids.length && this.expanded[acc.id]) for (const k of byNum(kids)) walk(k, depth + 1)
			}
			for (const r of roots) walk(r, 0)
			return out
		},
		filteredVisibleTree() {
			const s = this.accountSearch.trim().toLowerCase()
			if (!s) return this.visibleTree
			const matchingIds = new Set(
				this.accounts
					.filter(a => a.name.toLowerCase().includes(s) || String(a.number).includes(s))
					.map(a => a.id),
			)
			const addAncestors = (id) => {
				const acc = this.accountsById[id]
				if (acc && acc.parentId && !matchingIds.has(acc.parentId)) {
					matchingIds.add(acc.parentId)
					addAncestors(acc.parentId)
				}
			}
			for (const id of [...matchingIds]) addAncestors(id)
			const byNum = list => list.slice().sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
			const out = []
			const walk = (acc, depth) => {
				const kids = (this.childrenOf[acc.id] || []).filter(k => matchingIds.has(k.id))
				out.push({ id: acc.id, number: acc.number, name: acc.name, type: acc.type, depth, hasChildren: kids.length > 0 })
				for (const k of byNum(kids)) walk(k, depth + 1)
			}
			for (const r of byNum(this.accounts.filter(a => !a.parentId && matchingIds.has(a.id)))) walk(r, 0)
			return out
		},
		currentTree() {
			return this.accountSearch.trim() ? this.filteredVisibleTree : this.visibleTree
		},
		statementRows() {
			if (!this.statement) return []
			const isCredit = ['income', 'liability', 'equity'].includes(this.statement.account.type)
			let run = this.statement.carry || 0
			return this.statement.rows.map(r => {
				run += isCredit ? (r.credit - r.debit) : (r.debit - r.credit)
				return { ...r, saldo: run }
			})
		},
	},
	methods: {
		formatMoney,
		formatDate,
		typeLabel,
		amountClass,
		toggleExpand(id) { this.$set(this.expanded, id, !this.expanded[id]) },
		expandAll() { const e = {}; for (const acc of this.accounts) if ((this.childrenOf[acc.id] || []).length) e[acc.id] = true; this.expanded = e },
		collapseAll() { this.expanded = {} },
		balanceFor(accountId) {
			if (!this.balances) return 0
			const row = this.balances.accounts.find(a => a.accountId === accountId)
			return row ? row.balance : 0
		},
		statementRowNet(row) {
			const isCredit = this.statement && ['income', 'liability', 'equity'].includes(this.statement.account.type)
			return isCredit ? (row.credit - row.debit) : (row.debit - row.credit)
		},
		onIncludeChildrenChange(v) {
			this.$emit('update:statementIncludeChildren', v)
			this.$nextTick(() => this.reloadStatement())
		},
	},
}
</script>
