<template>
	<div style="display: contents;">
		<InvoiceEditor
			v-if="view === 'edit'"
			:key="editKey"
			:invoiceId="editId"
			:accounts="incomeAccounts"
			:meta="meta"
			:isAdmin="isAdmin"
			@close="closeEditor"
			@changed="loadInvoices"
			@issued="onIssued" />

		<template v-else>
			<div class="vbh-sectiontop">
				<div class="vbh-subtabs" role="tablist" :aria-label="t('Rechnungen filtern')">
					<button
						v-for="f in filters"
						:key="f.id"
						role="tab"
						:aria-selected="filter === f.id"
						:class="{ active: filter === f.id }"
						@click="filter = f.id">
						{{ f.label }}<span v-if="counts[f.id]" class="vbh-chip-count">{{ counts[f.id] }}</span>
					</button>
				</div>
				<div v-if="canWrite" class="vbh-sectiontop-actions">
					<NcButton variant="tertiary" @click="plans.open = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCalendarSync" :size="20" />
						</template>
						{{ t('Wiederkehrend') }}
					</NcButton>
					<NcButton variant="primary" @click="newInvoice">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" :size="20" />
						</template>
						{{ t('Neue Rechnung') }}
					</NcButton>
				</div>
			</div>

			<div class="vbh-filterbar">
				<input
					v-model="search"
					class="vbh-search vbh-inv-search"
					type="search"
					:placeholder="t('Nummer oder Empfänger suchen …')"
					:aria-label="t('Rechnungen durchsuchen')">
				<label class="vbh-inv-yearsel">{{ t('Jahr') }}
					<select v-model="year">
						<option :value="null">{{ t('Alle') }}</option>
						<option v-for="y in years" :key="y" :value="y">{{ y }}</option>
					</select>
				</label>
			</div>

			<div class="vbh-sectionbody">
				<p v-if="meta && meta.missingIssuerFields.length && canWrite" class="vbh-hint vbh-hint--warning">
					{{ t('Für Rechnungen fehlen noch Angaben des Vereins (Anschrift, Steuernummer). Tragen Sie sie in den Nextcloud-Einstellungen unter „Vereinsbuchhaltung“ ein.') }}
				</p>
				<p v-if="smallBusinessHint" class="vbh-hint vbh-hint--warning">
					{{ t('Die Rechnungen als Kleinunternehmer ergeben in diesem Jahr schon {amount}. Die Kleinunternehmergrenze (§ 19 UStG) liegt bei 25.000 € im Vorjahr und 100.000 € im laufenden Jahr und gilt für den gesamten Umsatz des Vereins, nicht nur für die Rechnungen. Bitte rechtzeitig mit dem Finanzamt oder der Steuerberatung klären.', { amount: formatMoney(smallBusinessTurnoverCents / 100) }) }}
				</p>
				<div class="vbh-totals">
					<div class="vbh-total">
						<span>{{ t('Offen') }}</span>
						<strong>{{ formatMoney(openCents / 100) }}</strong>
					</div>
					<div class="vbh-total" :class="{ 'vbh-total--warn': overdueCents > 0 }">
						<span>{{ t('Davon überfällig') }}</span>
						<strong>{{ formatMoney(overdueCents / 100) }}</strong>
					</div>
				</div>

				<NcLoadingIcon v-if="loading && !invoices.length" :size="32" :name="t('Wird geladen…')" />
				<div v-else-if="!invoices.length" class="vbh-card vbh-inv-empty">
					<h3>{{ t('Noch keine Rechnungen') }}</h3>
					<p>{{ t('Hier stellen Sie Rechnungen im Namen des Vereins aus, zum Beispiel für Hallenmiete, Kursgebühren oder Sponsoring. Jede festgeschriebene Rechnung erscheint als offener Posten und wird beim Kontoauszug-Import automatisch ihrer Zahlung zugeordnet.') }}</p>
					<NcButton v-if="canWrite" variant="primary" @click="newInvoice">
						{{ t('Erste Rechnung schreiben') }}
					</NcButton>
				</div>
				<p v-else-if="!rows.length" class="vbh-empty">
					{{ t('Keine Rechnung passt zu den Filtern.') }}
				</p>
				<div v-else class="vbh-tablecard">
					<table class="vbh-table vbh-inv-table">
						<thead>
							<tr>
								<th>{{ t('Nummer') }}</th>
								<th>{{ t('Datum') }}</th>
								<th>{{ t('Empfänger') }}</th>
								<th v-if="!isMobile">
									{{ t('Fällig') }}
								</th>
								<th class="vbh-inv-r">
									{{ t('Betrag') }}
								</th>
								<th v-if="!isMobile" class="vbh-inv-r">
									{{ t('Offen') }}
								</th>
								<th>{{ t('Status') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="inv in rows"
								:key="inv.id"
								class="vbh-inv-row"
								tabindex="0"
								@click="open(inv)"
								@keydown.enter="open(inv)">
								<td>
									<strong>{{ inv.number || t('Entwurf') }}</strong>
								</td>
								<td>{{ formatDate(inv.issueDate || inv.createdAt) }}</td>
								<td class="vbh-inv-who">
									{{ inv.debtorName || '–' }}
								</td>
								<td v-if="!isMobile" :class="{ 'vbh-inv-late': inv.overdue }">
									{{ inv.kind === 'storno' ? '' : formatDate(inv.dueDate) }}
								</td>
								<td class="vbh-inv-r" :class="{ neg: inv.grossCents < 0 }">
									{{ formatMoney(inv.grossCents / 100) }}
								</td>
								<td v-if="!isMobile" class="vbh-inv-r">
									{{ inv.remainingCents > 0 ? formatMoney(inv.remainingCents / 100) : '' }}
								</td>
								<td>
									<span :class="statusClass(inv)">{{ displayStatusLabel(inv) }}</span>
									<span
										v-if="inv.dunning && inv.dunning.level > 0"
										class="vbh-inv-dunlevel">{{ dunningLabel(inv.dunning.level) }}</span>
									<span
										v-if="inv.dunning && inv.dunning.due"
										class="vbh-inv-dundue"
										:title="t('Die nächste Mahnstufe ist fällig.')">{{ t('Mahnung fällig') }}</span>
								</td>
							</tr>
						</tbody>
					</table>
					<div class="vbh-tablecount">
						{{ n('%n Rechnung', '%n Rechnungen', rows.length) }}
					</div>
				</div>
			</div>
		</template>

		<InvoicePlansDialog
			v-if="plans.open"
			:show="plans.open"
			:isAdmin="isAdmin"
			:invoices="invoices"
			@close="plans.open = false"
			@update:show="plans.open = $event"
			@changed="loadInvoices"
			@open="openFromPlans" />

		<InvoiceDetail
			v-if="detail.open"
			:show="detail.open"
			:invoiceId="detail.id"
			:canWrite="canWrite"
			:isAdmin="isAdmin"
			:accounts="accounts"
			@close="closeDetail"
			@update:show="detail.open = $event"
			@changed="onChanged"
			@edit="editInvoice"
			@open="openById" />
	</div>
</template>

<script>
import { mdiCalendarSync, mdiPlus } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import InvoiceDetail from './InvoiceDetail.vue'
import InvoiceEditor from './InvoiceEditor.vue'
import InvoicePlansDialog from './InvoicePlansDialog.vue'
import api from '../api.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'
import { displayStatus, displayStatusLabel, dunningLabel, isDunningDue, statusClass } from '../lib/invoices.js'

/**
 * Reiter „Rechnungen" (Epic #168): Liste mit Filtern, Editor für Entwürfe und
 * Detailansicht. Der Reiter erscheint nur, wenn das Rechnungsmodul in den
 * Einstellungen eingeschaltet ist (App.vue, invoicesActive). Zahlungen laufen
 * über „Buchungen → Zuzuordnen" oder den Dialog „Zahlung erfassen".
 */
export default {
	name: 'InvoicesTab',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon, InvoiceEditor, InvoiceDetail, InvoicePlansDialog },
	props: {
		isMobile: { type: Boolean, default: false },
		canWrite: { type: Boolean, default: false },
		isAdmin: { type: Boolean, default: false },
		/** Alle Konten; Einnahmenkonten für die Positionen, Geldkonten für die Barzahlung. */
		accounts: { type: Array, default: () => [] },
		/** Aus der Adresse: die geöffnete Rechnung (Deep-Link). */
		invoiceId: { type: Number, default: null },
		/** Zählt hoch, wenn der Reiter sichtbar wird oder fremde Änderungen anstehen. */
		reloadKey: { type: Number, default: 0 },
	},

	emits: ['update:invoiceId', 'changed'],

	setup() {
		return { mdiCalendarSync, mdiPlus }
	},

	data() {
		return {
			loading: false,
			invoices: [],
			meta: null,
			filter: 'all',
			search: '',
			year: null,
			view: 'list',
			editId: null,
			editKey: 0,
			detail: { open: false, id: null },
			plans: { open: false },
		}
	},

	computed: {
		incomeAccounts() { return this.accounts.filter((a) => a.type === 'income' && a.active && !a.isBank) },

		filters() {
			return [
				{ id: 'all', label: this.t('Alle') },
				{ id: 'draft', label: this.t('Entwürfe') },
				{ id: 'open', label: this.t('Offen') },
				{ id: 'overdue', label: this.t('Überfällig') },
				{ id: 'dunning', label: this.t('Mahnung fällig') },
				{ id: 'paid', label: this.t('Bezahlt') },
				{ id: 'cancelled', label: this.t('Storniert') },
			]
		},

		counts() {
			const c = { all: this.invoices.length, draft: 0, open: 0, overdue: 0, dunning: 0, paid: 0, cancelled: 0 }
			for (const inv of this.invoices) {
				for (const f of ['draft', 'open', 'overdue', 'dunning', 'paid', 'cancelled']) {
					if (this.matchesFilter(inv, f)) { c[f]++ }
				}
			}
			return c
		},

		years() {
			const set = new Set(this.invoices.map((i) => Number(String(i.issueDate || i.createdAt).slice(0, 4))).filter(Boolean))
			return [...set].sort((a, b) => b - a)
		},

		rows() {
			const q = this.search.trim().toLowerCase()
			return this.invoices.filter((inv) => {
				if (!this.matchesFilter(inv, this.filter)) { return false }
				if (this.year !== null && Number(String(inv.issueDate || inv.createdAt).slice(0, 4)) !== this.year) { return false }
				if (q === '') { return true }
				return (inv.number || '').toLowerCase().includes(q) || (inv.debtorName || '').toLowerCase().includes(q)
			})
		},

		/** Brutto aller in diesem Jahr als Kleinunternehmer festgeschriebenen, nicht stornierten Rechnungen. */
		smallBusinessTurnoverCents() {
			const year = String(new Date().getFullYear())
			return this.invoices
				.filter((i) => i.kind !== 'storno' && ['issued', 'sent', 'paid'].includes(i.status) && i.taxMode === 'kleinunternehmer' && String(i.issueDate || '').startsWith(year))
				.reduce((sum, i) => sum + i.grossCents, 0)
		},

		smallBusinessHint() { return this.smallBusinessTurnoverCents >= 2000000 },

		openCents() {
			return this.invoices.filter((i) => i.kind !== 'storno' && ['issued', 'sent'].includes(i.status)).reduce((s, i) => s + i.remainingCents, 0)
		},

		overdueCents() {
			return this.invoices.filter((i) => i.overdue).reduce((s, i) => s + i.remainingCents, 0)
		},
	},

	watch: {
		invoiceId: {
			immediate: true,
			handler(id) {
				if (id && !(this.detail.open && this.detail.id === id)) {
					this.detail = { open: true, id }
				} else if (!id && this.detail.open) {
					this.detail.open = false
				}
			},
		},

		reloadKey() { this.loadInvoices() },
	},

	async mounted() {
		await Promise.all([this.loadMeta(), this.loadInvoices()])
	},

	methods: {
		formatDate,
		formatMoney,
		displayStatusLabel,
		dunningLabel,
		statusClass,

		matchesFilter(inv, filter) {
			const s = displayStatus(inv)
			switch (filter) {
				case 'all': return true
				case 'draft': return inv.status === 'draft'
				case 'open': return ['issued', 'sent', 'partial', 'overdue'].includes(s) && inv.kind !== 'storno'
				case 'overdue': return s === 'overdue'
				case 'dunning': return isDunningDue(inv)
				case 'paid': return inv.status === 'paid'
				case 'cancelled': return inv.status === 'cancelled' || inv.kind === 'storno'
				default: return true
			}
		},

		async loadMeta() {
			try {
				const { data } = await api.invoiceMeta()
				this.meta = data
			} catch (e) {
				showError(errMsg(e, this.t('Die Einstellungen für Rechnungen konnten nicht geladen werden.')))
			}
		},

		async loadInvoices() {
			this.loading = true
			try {
				const { data } = await api.invoices()
				this.invoices = data
			} catch (e) {
				showError(errMsg(e, this.t('Die Rechnungen konnten nicht geladen werden.')))
			} finally {
				this.loading = false
			}
		},

		newInvoice() {
			this.editId = null
			this.editKey++
			this.view = 'edit'
		},

		editInvoice(id) {
			this.detail.open = false
			this.$emit('update:invoiceId', null)
			this.editId = id
			this.editKey++
			this.view = 'edit'
		},

		closeEditor() {
			this.view = 'list'
			this.loadInvoices()
		},

		async onIssued(invoice) {
			this.view = 'list'
			await this.loadInvoices()
			this.openById(invoice.id)
			this.$emit('changed')
		},

		open(inv) {
			if (inv.status === 'draft') {
				if (this.canWrite) { this.editInvoice(inv.id) } else { this.openById(inv.id) }
				return
			}
			this.openById(inv.id)
		},

		openFromPlans(id) {
			this.plans.open = false
			this.openById(id)
		},

		openById(id) {
			this.detail = { open: true, id }
			this.$emit('update:invoiceId', id)
		},

		closeDetail() {
			this.detail.open = false
			this.$emit('update:invoiceId', null)
		},

		onChanged(info) {
			this.loadInvoices()
			// Zahlungen betreffen Bankumsätze, Buchungen und offene Posten außerhalb dieses Reiters.
			if (info && info.bookings) { this.$emit('changed', info) }
		},
	},
}
</script>

<style scoped>
.vbh-inv-search { min-width: 260px; }

.vbh-inv-yearsel { display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; font-weight: 600; }

.vbh-inv-table td, .vbh-inv-table th { vertical-align: middle; }

.vbh-inv-r { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }

.vbh-inv-row { cursor: pointer; }

.vbh-inv-row:hover, .vbh-inv-row:focus-visible { background: var(--color-background-hover); }

.vbh-inv-who { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.vbh-inv-dunlevel, .vbh-inv-dundue { margin-inline-start: 6px; font-size: 0.8em; white-space: nowrap; }

.vbh-inv-dundue { color: var(--color-error-text, inherit); font-weight: 700; }

.vbh-inv-late { color: var(--color-error-text, inherit); font-weight: 600; }

.vbh-inv-empty { max-width: 640px; }

.vbh-inv-empty h3 { margin-top: 0; }
</style>
