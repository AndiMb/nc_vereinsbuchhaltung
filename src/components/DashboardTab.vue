<template>
	<div>
		<SetupChecklist
			v-if="isAdmin"
			:accounts="accounts"
			:permissions="permissions"
			:journalCount="journalData.length"
			:clubName="clubName"
			@navigate="$emit('navigate', $event)"
			@openWizard="$emit('open-wizard')" />

		<div v-if="balances" class="vbh-totals">
			<div class="vbh-total pos">
				<span>{{ t('Einnahmen') }}{{ selectedYear ? ' ' + selectedYear : '' }}</span>
				<strong>{{ formatMoney(balances.totals.income) }}</strong>
				<small v-if="kpiDeltas && kpiDeltas.income" class="vbh-total-delta" :class="kpiDeltas.income.up ? 'good' : 'bad'">{{ kpiDeltas.income.text }}</small>
			</div>
			<div class="vbh-total neg">
				<span>{{ t('Ausgaben') }}{{ selectedYear ? ' ' + selectedYear : '' }}</span>
				<strong>{{ formatMoney(balances.totals.expense) }}</strong>
				<small v-if="kpiDeltas && kpiDeltas.expense" class="vbh-total-delta" :class="kpiDeltas.expense.up ? 'bad' : 'good'">{{ kpiDeltas.expense.text }}</small>
			</div>
			<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'">
				<span>{{ t('Ergebnis') }}{{ selectedYear ? ' ' + selectedYear : '' }}</span>
				<strong>{{ formatMoney(balances.totals.result) }}</strong>
				<small v-if="kpiDeltas && kpiDeltas.result" class="vbh-total-delta" :class="kpiDeltas.result.up ? 'good' : 'bad'">{{ kpiDeltas.result.text }}</small>
			</div>
			<div v-if="unassignedCount > 0" class="vbh-total vbh-total--warn">
				<span>{{ t('Nicht zugeordnet') }}</span>
				<strong>{{ t('{n} Buchungen', { n: unassignedCount }) }}</strong>
				<NcButton variant="primary" size="small" @click="$emit('go-unassigned')">
					{{ t('Jetzt zuordnen') }}
				</NcButton>
			</div>
			<div v-if="overdueOpenItemsCount > 0" class="vbh-total vbh-total--warn">
				<span>{{ t('Überfällige offene Posten') }}</span>
				<strong>{{ overdueOpenItemsCount }}</strong>
				<NcButton variant="primary" size="small" @click="$emit('go-open-items')">
					{{ t('Ansehen') }}
				</NcButton>
			</div>
		</div>

		<div v-if="sphereData && sphereData.freigrenze.incomeCents > 0" class="vbh-freigrenzecard" :class="sphereData.freigrenze.level">
			<div class="vbh-freigrenzecard-text">
				<strong>{{ t('Wirtschaftlicher Geschäftsbetrieb') }}{{ selectedYear ? ' ' + selectedYear : '' }}:</strong>
				{{ t('{income} von {threshold} Freigrenze', { income: formatMoney(sphereData.freigrenze.income), threshold: formatMoney(sphereData.freigrenze.threshold) }) }}
				({{ Math.round(sphereData.freigrenze.ratio * 100) }} %)
				<span v-if="sphereData.freigrenze.level === 'over'"> {{ t('– Freigrenze überschritten, bitte mit Steuerberatung klären.') }}</span>
				<span v-else-if="sphereData.freigrenze.level === 'warn'"> {{ t('– nähert sich der Freigrenze.') }}</span>
			</div>
			<button
				type="button"
				class="vbh-sphere-help"
				:title="t('Was bedeutet das?')"
				@click="$emit('help', 'spheres')">
				?
			</button>
		</div>

		<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
			<h4>{{ t('Geldkonten') }}</h4>
			<div v-if="!isMobile" class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th>{{ t('Konto') }}</th><th class="num">
								{{ t('Kontostand') }}
							</th><th class="num">
								{{ t('Offen (nicht zugeordnet)') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="b in balances.bankReconciliation" :key="b.accountId">
							<td>
								{{ b.number }} {{ b.name }}
								<span v-if="b.countInTotal === false" class="vbh-nocount">{{ t('(nicht im Geldbestand)') }}</span>
							</td>
							<td class="num strong">
								{{ formatMoney(b.balance) }}
							</td>
							<td class="num" :class="Math.abs(b.open) > 0.005 ? 'neg' : ''">
								{{ formatMoney(b.open) }}
							</td>
						</tr>
					</tbody>
					<!-- Erst ab zwei Geldkonten: bei einem einzigen wiederholte die
					     Summenzeile nur die Zeile darueber. Mobil steht dieselbe
					     Bedingung an der Summenkarte. -->
					<tfoot v-if="balances.bankTotal && balances.bankTotal.allCount > 1">
						<tr>
							<td><strong>{{ t('Summe') }}</strong></td>
							<td class="num strong">
								{{ formatMoney(balances.bankTotal.allBalance) }}
							</td>
							<td class="num" :class="Math.abs(balances.bankTotal.open) > 0.005 ? 'neg' : ''">
								{{ formatMoney(balances.bankTotal.open) }}
							</td>
						</tr>
						<!-- Nur wenn beide Zahlen auseinanderlaufen: sonst stünde
						     hier dieselbe Summe ein zweites Mal. Zaehlt gar kein Konto
						     mit, gibt es auch keine Kopfzeilen-Zahl, auf die sich die
						     Zeile beziehen koennte. -->
						<tr v-if="balances.bankTotal.count > 0 && balances.bankTotal.count < balances.bankTotal.allCount">
							<td>{{ t('davon Geldbestand (Kopfzeile)') }}</td>
							<td class="num">
								{{ formatMoney(balances.bankTotal.balance) }}
							</td>
							<td />
						</tr>
					</tfoot>
				</table>
			</div>
			<div v-else class="vbh-cardlist">
				<div v-for="b in balances.bankReconciliation" :key="'m' + b.accountId" class="vbh-mcard">
					<div class="vbh-mcard-top">
						<span class="vbh-mcard-title">{{ b.number }} {{ b.name }}</span>
						<span class="vbh-mcard-amount">{{ formatMoney(b.balance) }}</span>
					</div>
					<div v-if="b.countInTotal === false || Math.abs(b.open) > 0.005" class="vbh-mcard-bottom">
						<span class="vbh-mcard-accounts">
							<template v-if="b.countInTotal === false">{{ t('(nicht im Geldbestand)') }}</template>
							<template v-if="Math.abs(b.open) > 0.005">{{ t('{amount} nicht zugeordnet', { amount: formatMoney(b.open) }) }}</template>
						</span>
					</div>
				</div>
				<div v-if="balances.bankTotal && balances.bankTotal.allCount > 1" class="vbh-mcard">
					<div class="vbh-mcard-top">
						<span class="vbh-mcard-title"><strong>{{ t('Summe') }}</strong></span>
						<span class="vbh-mcard-amount"><strong>{{ formatMoney(balances.bankTotal.allBalance) }}</strong></span>
					</div>
					<div v-if="balances.bankTotal.count > 0 && balances.bankTotal.count < balances.bankTotal.allCount" class="vbh-mcard-bottom">
						<span class="vbh-mcard-accounts">{{ t('davon Geldbestand (Kopfzeile): {amount}', { amount: formatMoney(balances.bankTotal.balance) }) }}</span>
					</div>
				</div>
			</div>
		</template>

		<template v-if="recentJournal.length">
			<div class="vbh-sectionhead">
				<h4>{{ t('Letzte Buchungen') }}</h4>
				<NcButton variant="tertiary" @click="$emit('show-all-bookings')">
					{{ t('Alle anzeigen') }}
				</NcButton>
			</div>
			<div v-if="!isMobile" class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th class="num vbh-col-hide-sm">
								{{ t('Nr.') }}
							</th>
							<th class="nowrap">
								{{ t('Datum') }}
							</th>
							<th>{{ t('Beschreibung') }}</th>
							<th class="vbh-col-hide-sm">
								{{ t('Soll') }}
							</th>
							<th class="vbh-col-hide-sm">
								{{ t('Haben') }}
							</th>
							<th class="num">
								{{ t('Betrag') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="r in recentJournal" :key="r.id">
							<td class="num vbh-col-hide-sm">
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
						</tr>
					</tbody>
				</table>
			</div>
			<div v-else class="vbh-cardlist">
				<BookingCard
					v-for="r in recentJournal"
					:key="'m' + r.id"
					:row="r"
					:attachmentCount="attachmentCountMap[r.id] ? attachmentCountMap[r.id].count : 0"
					:flow="rowFlow(r)"
					:tappable="canWrite || !!attachmentCountMap[r.id]"
					@open="openBookingCard(r)"
					@paperclip="clickPaperclip(r)" />
			</div>
		</template>
		<NcEmptyContent v-else-if="!busy" :name="t('Noch keine Buchungen')" :description="t('Importiere Kontoumsätze oder lege manuell Buchungssätze an.')">
			<template #action>
				<NcButton variant="tertiary" @click="$emit('help', 'bookings')">
					{{ t('Mehr dazu') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div class="vbh-chart-grid">
			<div class="vbh-chart-card vbh-chart-card--wide">
				<h4>{{ t('Einnahmen & Ausgaben') }}{{ selectedYear ? ' ' + selectedYear : '' }} {{ t('(monatlich)') }}</h4>
				<div class="vbh-chart-wrap">
					<canvas ref="monthlyChart" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import {
	BarController,
	BarElement,
	CategoryScale,
	Chart,
	Legend,
	LinearScale,
	Tooltip,
} from 'chart.js'
import { toRefs } from 'vue'
import BookingCard from './BookingCard.vue'
import SetupChecklist from './SetupChecklist.vue'
import { useAccounts } from '../composables/useAccounts.js'
import { useAuth } from '../composables/useAuth.js'
import { useBalances } from '../composables/useBalances.js'
import { useJournal } from '../composables/useJournal.js'
import { useOpenItems } from '../composables/useOpenItems.js'
import { usePermissions } from '../composables/usePermissions.js'
import { useYears } from '../composables/useYears.js'
import { chartTheme, onThemeChange, withAlpha } from '../lib/chartTheme.js'
import { formatDate, formatMoney } from '../lib/format.js'

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

	emits: ['go-open-items', 'go-unassigned', 'help', 'navigate', 'open-wizard', 'show-all-bookings'],

	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const balances = useBalances()
		const journal = useJournal()
		const permissions = usePermissions()
		const openItems = useOpenItems()
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
			overdueOpenItemsCount: openItems.overdueCount,
		}
	},

	computed: {
		kpiDeltas() {
			if (!this.balances || !this.prevBalances || !this.selectedYear) { return null }
			const mk = (key) => {
				const cur = this.balances.totals[key]
				const prev = this.prevBalances.totals[key]
				if (!prev || Math.abs(prev) < 0.005) { return null }
				const pct = Math.round(((cur - prev) / Math.abs(prev)) * 100)
				return { pct, up: pct >= 0, text: this.t('{sign}{pct} % ggü. {year}', { sign: pct >= 0 ? '+' : '', pct, year: this.selectedYear - 1 }) }
			}
			return { income: mk('income'), expense: mk('expense'), result: mk('result') }
		},

		monthlyChartData() {
			const labels = [this.t('Jan'), this.t('Feb'), this.t('Mär'), this.t('Apr'), this.t('Mai'), this.t('Jun'), this.t('Jul'), this.t('Aug'), this.t('Sep'), this.t('Okt'), this.t('Nov'), this.t('Dez')]
			const income = new Array(12).fill(0)
			const expense = new Array(12).fill(0)
			for (const item of this.journalData) {
				const date = item.journal && item.journal.date
				if (!date) { continue }
				const m = parseInt(String(date).slice(5, 7), 10) - 1
				if (m < 0 || m > 11) { continue }
				for (const line of (item.lines || [])) {
					const acc = this.accountsById[line.accountId]
					if (!acc || acc.isBank || acc.type === 'equity') { continue }
					if (['income', 'liability'].includes(acc.type)) { income[m] += (line.creditCents - line.debitCents) / 100 } else { expense[m] += (line.debitCents - line.creditCents) / 100 }
				}
			}
			return { labels, income, expense }
		},
	},

	watch: {
		isActive(v) { if (v) { this.$nextTick(() => this.renderMonthlyChart()) } },
		journalData() { if (this.isActive) { this.$nextTick(() => this.renderMonthlyChart()) } },
	},

	// chartInstances liegt bewusst NICHT in data(): Chart.js-Instanzen vertragen
	// sich nicht mit Vue 3s tiefer Proxy-Reaktivitaet, und die Instanzen werden
	// nirgends im Template gelesen, brauchen also keine Reaktivitaet.
	created() {
		this.chartInstances = {}
	},

	mounted() {
		this.$nextTick(() => setTimeout(() => this.renderMonthlyChart(), 50))
		// Chart.js malt die Farben einmal ins Canvas; ohne Neuzeichnen bliebe
		// nach einem Designwechsel das alte Bild stehen.
		this.stopThemeWatch = onThemeChange(() => {
			if (this.isActive) { this.renderMonthlyChart() }
		})
	},

	beforeUnmount() {
		this.stopThemeWatch()
		Object.values(this.chartInstances).forEach((c) => c && c.destroy())
	},

	methods: {
		formatMoney,
		formatDate,
		rowFlow(r) {
			if (r.isSplit) { return '' }
			const d = this.accountsById[r.debitAccountId]
			const c = this.accountsById[r.creditAccountId]
			const dIn = !!(d && d.isBank)
			const cOut = !!(c && c.isBank)
			if (dIn && !cOut) { return 'in' }
			if (cOut && !dIn) { return 'out' }
			return ''
		},

		destroyChart(key) {
			if (this.chartInstances[key]) {
				this.chartInstances[key].destroy()
				this.chartInstances[key] = null
			}
		},

		renderMonthlyChart() {
			const canvas = this.$refs.monthlyChart
			if (!canvas) { return }
			this.destroyChart('monthly')
			const { labels, income, expense } = this.monthlyChartData
			const theme = chartTheme()
			this.chartInstances.monthly = new Chart(canvas, {
				type: 'bar',
				data: {
					labels,
					datasets: [
						{
							label: this.t('Einnahmen'),
							data: income,
							backgroundColor: withAlpha(theme.success, 0.72),
							borderColor: theme.success,
							borderWidth: 1,
							borderRadius: 4,
						},
						{
							label: this.t('Ausgaben'),
							data: expense,
							backgroundColor: withAlpha(theme.error, 0.72),
							borderColor: theme.error,
							borderWidth: 1,
							borderRadius: 4,
						},
					],
				},

				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { labels: { color: theme.text, font: { size: 12 } } },
						tooltip: {
							callbacks: {
								label: (ctx) => ` ${ctx.dataset.label}: ${new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(ctx.raw)}`,
							},
						},
					},

					scales: {
						x: {
							ticks: { color: theme.mutedText },
							grid: { color: theme.grid },
						},

						y: {
							ticks: {
								color: theme.mutedText,
								callback: (v) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v),
							},

							grid: { color: theme.grid },
						},
					},
				},
			})
		},
	},
}
</script>
