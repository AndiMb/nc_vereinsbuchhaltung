<template>
	<div>
		<SetupChecklist
			v-if="isAdmin"
			:accounts="accounts"
			:permissions="permissions"
			:journal-count="journalData.length"
			:club-name="clubName"
			@navigate="$emit('navigate', $event)"
			@open-wizard="$emit('open-wizard')" />

		<div v-if="balances" class="vbh-totals">
			<div class="vbh-total pos">
				<span>Einnahmen{{ selectedYear ? ' ' + selectedYear : '' }}</span>
				<strong>{{ formatMoney(balances.totals.income) }}</strong>
				<small v-if="kpiDeltas && kpiDeltas.income" class="vbh-total-delta" :class="kpiDeltas.income.up ? 'good' : 'bad'">{{ kpiDeltas.income.text }}</small>
			</div>
			<div class="vbh-total neg">
				<span>Ausgaben{{ selectedYear ? ' ' + selectedYear : '' }}</span>
				<strong>{{ formatMoney(balances.totals.expense) }}</strong>
				<small v-if="kpiDeltas && kpiDeltas.expense" class="vbh-total-delta" :class="kpiDeltas.expense.up ? 'bad' : 'good'">{{ kpiDeltas.expense.text }}</small>
			</div>
			<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'">
				<span>Ergebnis{{ selectedYear ? ' ' + selectedYear : '' }}</span>
				<strong>{{ formatMoney(balances.totals.result) }}</strong>
				<small v-if="kpiDeltas && kpiDeltas.result" class="vbh-total-delta" :class="kpiDeltas.result.up ? 'good' : 'bad'">{{ kpiDeltas.result.text }}</small>
			</div>
			<div v-if="unassignedCount > 0" class="vbh-total vbh-total--warn">
				<span>Nicht zugeordnet</span>
				<strong>{{ unassignedCount }} Buchungen</strong>
				<NcButton variant="primary" size="small" @click="$emit('go-unassigned')">Jetzt zuordnen</NcButton>
			</div>
		</div>

		<div v-if="sphereData && sphereData.freigrenze.incomeCents > 0" class="vbh-freigrenzecard" :class="sphereData.freigrenze.level">
			<div class="vbh-freigrenzecard-text">
				<strong>Wirtschaftlicher Geschäftsbetrieb{{ selectedYear ? ' ' + selectedYear : '' }}:</strong>
				{{ formatMoney(sphereData.freigrenze.income) }} von {{ formatMoney(sphereData.freigrenze.threshold) }} Freigrenze
				({{ Math.round(sphereData.freigrenze.ratio * 100) }} %)
				<span v-if="sphereData.freigrenze.level === 'over'"> – Freigrenze überschritten, bitte mit Steuerberatung klären.</span>
				<span v-else-if="sphereData.freigrenze.level === 'warn'"> – nähert sich der Freigrenze.</span>
			</div>
			<button type="button" class="vbh-sphere-help" title="Was bedeutet das?" @click="$emit('help', 'spheres')">?</button>
		</div>

		<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
			<h4>Geldkonten</h4>
			<div v-if="!isMobile" class="vbh-tablecard">
				<table class="vbh-table">
					<thead><tr><th>Konto</th><th class="num">Kontostand</th><th class="num">Offen (nicht zugeordnet)</th></tr></thead>
					<tbody>
						<tr v-for="b in balances.bankReconciliation" :key="b.accountId">
							<td>{{ b.number }} {{ b.name }}</td>
							<td class="num strong">{{ formatMoney(b.balance) }}</td>
							<td class="num" :class="Math.abs(b.open) > 0.005 ? 'neg' : ''">{{ formatMoney(b.open) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div v-else class="vbh-cardlist">
				<div v-for="b in balances.bankReconciliation" :key="'m' + b.accountId" class="vbh-mcard">
					<div class="vbh-mcard-top">
						<span class="vbh-mcard-title">{{ b.number }} {{ b.name }}</span>
						<span class="vbh-mcard-amount">{{ formatMoney(b.balance) }}</span>
					</div>
					<div v-if="Math.abs(b.open) > 0.005" class="vbh-mcard-bottom">
						<span class="vbh-mcard-accounts">{{ formatMoney(b.open) }} nicht zugeordnet</span>
					</div>
				</div>
			</div>
		</template>

		<template v-if="recentJournal.length">
			<div class="vbh-sectionhead">
				<h4>Letzte Buchungen</h4>
				<NcButton variant="tertiary" @click="$emit('show-all-bookings')">Alle anzeigen</NcButton>
			</div>
			<div v-if="!isMobile" class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th class="num vbh-col-hide-sm">Nr.</th>
							<th class="nowrap">Datum</th>
							<th>Beschreibung</th>
							<th class="vbh-col-hide-sm">Soll</th>
							<th class="vbh-col-hide-sm">Haben</th>
							<th class="num">Betrag</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="r in recentJournal" :key="r.id">
							<td class="num vbh-col-hide-sm">{{ r.entryNo }}</td>
							<td class="nowrap">{{ formatDate(r.date) }}</td>
							<td class="vbh-purpose" :title="r.description"><span class="vbh-clamp">{{ r.description }}</span></td>
							<td class="vbh-col-hide-sm">{{ r.soll }}</td>
							<td class="vbh-col-hide-sm">{{ r.haben }}</td>
							<td class="num strong">{{ formatMoney(r.amount) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div v-else class="vbh-cardlist">
				<BookingCard v-for="r in recentJournal"
					:key="'m' + r.id"
					:row="r"
					:attachment-count="attachmentCountMap[r.id] ? attachmentCountMap[r.id].count : 0"
					:flow="rowFlow(r)"
					:tappable="canWrite || !!attachmentCountMap[r.id]"
					@open="openBookingCard(r)"
					@paperclip="clickPaperclip(r)" />
			</div>
		</template>
		<NcEmptyContent v-else-if="!busy" name="Noch keine Buchungen" description="Importiere Kontoumsätze oder lege manuell Buchungssätze an.">
			<template #action>
				<NcButton variant="tertiary" @click="$emit('help', 'bookings')">Mehr dazu</NcButton>
			</template>
		</NcEmptyContent>

		<div class="vbh-chart-grid">
			<div class="vbh-chart-card vbh-chart-card--wide">
				<h4>Einnahmen &amp; Ausgaben{{ selectedYear ? ' ' + selectedYear : '' }} (monatlich)</h4>
				<div class="vbh-chart-wrap">
					<canvas ref="monthlyChart"></canvas>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import {
	Chart,
	BarController,
	BarElement,
	CategoryScale,
	LinearScale,
	Tooltip,
	Legend,
} from 'chart.js'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import SetupChecklist from './SetupChecklist.vue'
import BookingCard from './BookingCard.vue'
import { formatMoney, formatDate } from '../lib/format.js'
import { useAuth } from '../composables/useAuth.js'
import { useYears } from '../composables/useYears.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useBalances } from '../composables/useBalances.js'
import { useJournal } from '../composables/useJournal.js'
import { usePermissions } from '../composables/usePermissions.js'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
	name: 'DashboardTab',
	components: { NcButton, NcEmptyContent, SetupChecklist, BookingCard },
	props: {
		// true, solange der Dashboard-Tab aktiv ist - steuert den Chart-Redraw
		// (Chart.js braucht bei v-show="display:none" beim ersten Rendern einen
		// erneuten Aufruf, sobald das Canvas wirklich sichtbar wird).
		isActive: { type: Boolean, required: true },
		isMobile: { type: Boolean, required: true },
		busy: { type: Boolean, required: true },
		clubName: { type: String, required: true },
		attachmentCountMap: { type: Object, required: true },
		// haengt vom Sortierzustand der Journal-Tabelle im Buchungen-Tab ab
		// (sortedJournalRows), daher weiterhin von App.vue berechnet/uebergeben.
		recentJournal: { type: Array, required: true },
		clickPaperclip: { type: Function, required: true },
		openBookingCard: { type: Function, required: true },
	},
	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const balances = useBalances()
		const journal = useJournal()
		const permissions = usePermissions()
		return {
			canWrite: auth.canWrite,
			isAdmin: auth.isAdmin,
			...toRefs(years.state),
			accountsById: accounts.accountsById,
			...toRefs(accounts.state),
			...toRefs(balances.state),
			...toRefs(journal.state),
			unassignedCount: journal.unassignedCount,
			...toRefs(permissions.state),
		}
	},
	data() {
		return { chartInstances: {} }
	},
	computed: {
		kpiDeltas() {
			if (!this.balances || !this.prevBalances || !this.selectedYear) return null
			const mk = key => {
				const cur = this.balances.totals[key]
				const prev = this.prevBalances.totals[key]
				if (!prev || Math.abs(prev) < 0.005) return null
				const pct = Math.round(((cur - prev) / Math.abs(prev)) * 100)
				return { pct, up: pct >= 0, text: (pct >= 0 ? '+' : '') + pct + ' % ggü. ' + (this.selectedYear - 1) }
			}
			return { income: mk('income'), expense: mk('expense'), result: mk('result') }
		},
		monthlyChartData() {
			const labels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
			const income = new Array(12).fill(0)
			const expense = new Array(12).fill(0)
			for (const item of this.journalData) {
				const date = item.journal && item.journal.date
				if (!date) continue
				const m = parseInt(String(date).slice(5, 7), 10) - 1
				if (m < 0 || m > 11) continue
				for (const line of (item.lines || [])) {
					const acc = this.accountsById[line.accountId]
					if (!acc || acc.isBank || acc.type === 'equity') continue
					if (['income', 'liability'].includes(acc.type)) income[m] += (line.creditCents - line.debitCents) / 100
					else expense[m] += (line.debitCents - line.creditCents) / 100
				}
			}
			return { labels, income, expense }
		},
	},
	watch: {
		isActive(v) { if (v) this.$nextTick(() => this.renderMonthlyChart()) },
		journalData() { if (this.isActive) this.$nextTick(() => this.renderMonthlyChart()) },
	},
	mounted() {
		this.$nextTick(() => setTimeout(() => this.renderMonthlyChart(), 50))
	},
	beforeDestroy() {
		Object.values(this.chartInstances).forEach(c => c && c.destroy())
	},
	methods: {
		formatMoney,
		formatDate,
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
		destroyChart(key) {
			if (this.chartInstances[key]) {
				this.chartInstances[key].destroy()
				this.$set(this.chartInstances, key, null)
			}
		},
		renderMonthlyChart() {
			const canvas = this.$refs.monthlyChart
			if (!canvas) return
			this.destroyChart('monthly')
			const { labels, income, expense } = this.monthlyChartData
			const isDark = document.documentElement.classList.contains('theme--dark')
			const textColor = isDark ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.7)'
			const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)'
			this.$set(this.chartInstances, 'monthly', new Chart(canvas, {
				type: 'bar',
				data: {
					labels,
					datasets: [
						{
							label: 'Einnahmen',
							data: income,
							backgroundColor: 'rgba(45,125,70,0.72)',
							borderColor: 'rgba(45,125,70,0.9)',
							borderWidth: 1,
							borderRadius: 4,
						},
						{
							label: 'Ausgaben',
							data: expense,
							backgroundColor: 'rgba(199,60,60,0.72)',
							borderColor: 'rgba(199,60,60,0.9)',
							borderWidth: 1,
							borderRadius: 4,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { labels: { color: textColor, font: { size: 12 } } },
						tooltip: {
							callbacks: {
								label: ctx => ` ${ctx.dataset.label}: ${new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(ctx.raw)}`,
							},
						},
					},
					scales: {
						x: {
							ticks: { color: textColor },
							grid: { color: gridColor },
						},
						y: {
							ticks: {
								color: textColor,
								callback: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v),
							},
							grid: { color: gridColor },
						},
					},
				},
			}))
		},
	},
}
</script>
