<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-inv-pay"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-inv-pay" class="vbh-modal-title">
				{{ t('Zahlung zu {number} erfassen', { number: invoice.number }) }}
			</h2>
			<p class="vbh-hint">
				{{ t('Noch offen: {amount}. Eine Teilzahlung ist möglich; sie wird anteilig auf die Erlöskonten der Positionen gebucht.', { amount: formatMoney(invoice.remainingCents / 100) }) }}
			</p>

			<div class="vbh-subtabs" role="tablist">
				<button
					role="tab"
					:aria-selected="mode === 'bank'"
					:class="{ active: mode === 'bank' }"
					@click="mode = 'bank'">
					{{ t('Bankumsatz zuordnen') }}
				</button>
				<button
					role="tab"
					:aria-selected="mode === 'cash'"
					:class="{ active: mode === 'cash' }"
					@click="mode = 'cash'">
					{{ t('Barzahlung buchen') }}
				</button>
			</div>

			<div v-if="mode === 'bank'" class="vbh-inv-pay-body">
				<NcLoadingIcon v-if="loading" :size="28" :name="t('Wird geladen…')" />
				<p v-else-if="!candidates.length" class="vbh-empty">
					{{ t('Es gibt keinen unzugeordneten Zahlungseingang. Importieren Sie zuerst den Kontoauszug.') }}
				</p>
				<ul
					v-else
					class="vbh-inv-pay-list"
					role="listbox"
					:aria-label="t('Unzugeordnete Zahlungseingänge')">
					<li
						v-for="tx in candidates"
						:key="tx.id"
						role="option"
						:aria-selected="selectedTx === tx.id"
						:class="{ selected: selectedTx === tx.id, fits: tx.amountCents === invoice.remainingCents, over: tx.amountCents > invoice.remainingCents }"
						tabindex="0"
						@click="selectedTx = tx.id"
						@keydown.enter.prevent="selectedTx = tx.id"
						@keydown.space.prevent="selectedTx = tx.id">
						<span class="vbh-inv-pay-date">{{ formatDate(tx.bookingDate) }}</span>
						<span class="vbh-inv-pay-who">
							<strong>{{ tx.counterparty }}</strong>
							<small>{{ tx.purpose }}</small>
						</span>
						<span class="vbh-inv-pay-amount">{{ formatMoney(tx.amountCents / 100) }}</span>
					</li>
				</ul>
				<p v-if="selectedOver" class="vbh-hint vbh-hint--warning">
					{{ t('Der Betrag übersteigt den offenen Rest. Teilen Sie den Umsatz in „Buchungen → Zuzuordnen“ auf und ordnen Sie den Rest einem anderen Konto zu.') }}
				</p>
				<p v-else-if="selectedPartial" class="vbh-hint vbh-hint--info">
					{{ t('Das ist eine Teilzahlung. Die Rechnung bleibt offen, bis der Rest eingeht.') }}
				</p>
			</div>

			<div v-else class="vbh-inv-pay-body">
				<div class="vbh-form">
					<label>{{ t('Zahlungsdatum') }}
						<input v-model="cash.date" type="date">
					</label>
					<label>{{ t('Betrag') }}
						<AmountInput v-model="cash.amount" class="vbh-short" />
					</label>
					<label class="vbh-grow">{{ t('Eingegangen auf') }}
						<select v-model="cash.accountId">
							<option :value="null">{{ t('– Geldkonto wählen –') }}</option>
							<option v-for="a in cashAccounts" :key="a.id" :value="a.id">{{ a.number }} {{ a.name }}</option>
						</select>
					</label>
				</div>
				<p class="vbh-hint">
					{{ t('Gebucht wird: Geldkonto an die Erlöskonten der Positionen. Für Zahlungen, die über einen Kontoauszug kommen, nutzen Sie stattdessen „Bankumsatz zuordnen“.') }}
				</p>
			</div>

			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSubmit || busy" @click="submit">
					{{ t('Zahlung buchen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AmountInput from './AmountInput.vue'
import api from '../api.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'
import { todayIso } from '../lib/invoices.js'

/**
 * Zahlung zu einer Rechnung erfassen (Epic #168): entweder einen
 * unzugeordneten Zahlungseingang vom Kontoauszug zuordnen oder eine
 * Barzahlung buchen. Meldet `paid` mit dem neuen Stand der Rechnung.
 */
export default {
	name: 'InvoicePayDialog',
	components: { NcModal, NcButton, NcLoadingIcon, AmountInput },
	props: {
		show: { type: Boolean, default: false },
		/** Detail der Rechnung (mit remainingCents und number). */
		invoice: { type: Object, default: null },
		/** Alle Konten; die Geldkonten davon stehen für die Barzahlung zur Wahl. */
		accounts: { type: Array, default: () => [] },
	},

	emits: ['close', 'paid', 'update:show'],

	data() {
		return {
			mode: 'bank',
			loading: false,
			busy: false,
			error: '',
			transactions: [],
			selectedTx: null,
			cash: { date: todayIso(), amount: '', accountId: null },
		}
	},

	computed: {
		cashAccounts() { return this.accounts.filter((a) => a.isBank && a.active) },

		candidates() {
			// Passende zuerst: genau der offene Rest, dann alle anderen nach Datum.
			const rest = this.invoice ? this.invoice.remainingCents : 0
			return this.transactions
				.filter((tx) => tx.amountCents > 0)
				.sort((a, b) => (Number(b.amountCents === rest) - Number(a.amountCents === rest)) || String(b.bookingDate).localeCompare(String(a.bookingDate)))
		},

		selected() { return this.candidates.find((tx) => tx.id === this.selectedTx) || null },
		selectedOver() { return !!this.selected && this.selected.amountCents > this.invoice.remainingCents },
		selectedPartial() { return !!this.selected && this.selected.amountCents < this.invoice.remainingCents },

		canSubmit() {
			if (this.mode === 'bank') { return !!this.selected && !this.selectedOver }
			const cents = Math.round(Number(this.cash.amount) * 100)
			return !!this.cash.date && !!this.cash.accountId && cents > 0 && cents <= this.invoice.remainingCents
		},
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.mode = 'bank'
			this.error = ''
			this.selectedTx = null
			this.cash = { date: todayIso(), amount: this.invoice.remainingCents / 100, accountId: this.cashAccounts.length === 1 ? this.cashAccounts[0].id : null }
			this.loadTransactions()
		},
	},

	methods: {
		formatDate,
		formatMoney,

		async loadTransactions() {
			this.loading = true
			try {
				const { data } = await api.listTransactions('unassigned')
				this.transactions = data
			} catch (e) {
				this.error = errMsg(e, this.t('Die Umsätze konnten nicht geladen werden.'))
			} finally {
				this.loading = false
			}
		},

		async submit() {
			this.busy = true
			this.error = ''
			try {
				const { data } = this.mode === 'bank'
					? await api.payInvoice(this.invoice.id, this.selectedTx)
					: await api.payInvoiceManually(this.invoice.id, {
							date: this.cash.date,
							accountId: this.cash.accountId,
							amountCents: Math.round(Number(this.cash.amount) * 100),
						})
				this.$emit('paid', data)
			} catch (e) {
				this.error = errMsg(e, this.t('Die Zahlung konnte nicht gebucht werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-inv-pay-body { margin-top: 12px; min-height: 120px; }

.vbh-inv-pay-list { list-style: none; margin: 0; padding: 0; max-height: 280px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--border-radius, 6px); }

.vbh-inv-pay-list li { display: grid; grid-template-columns: 88px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 8px 10px; cursor: pointer; border-bottom: 1px solid var(--color-border); }

.vbh-inv-pay-list li:last-child { border-bottom: 0; }

.vbh-inv-pay-list li:hover, .vbh-inv-pay-list li:focus-visible { background: var(--color-background-hover); }

.vbh-inv-pay-list li.selected { background: var(--color-primary-element-light); outline: 2px solid var(--color-primary-element); outline-offset: -2px; }

.vbh-inv-pay-who { display: flex; flex-direction: column; min-width: 0; }

.vbh-inv-pay-who small { opacity: 0.7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.vbh-inv-pay-amount { font-variant-numeric: tabular-nums; font-weight: 600; }

.vbh-inv-pay-list li.fits .vbh-inv-pay-amount { color: var(--color-success-text, inherit); }

.vbh-inv-pay-list li.over .vbh-inv-pay-amount { color: var(--color-warning-text, inherit); }
</style>
