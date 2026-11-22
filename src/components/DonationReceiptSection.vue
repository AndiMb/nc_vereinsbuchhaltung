<template>
	<section v-if="state && state.enabled" class="vbh-akte-section vbh-donation-receipts">
		<h3 class="vbh-modal-subtitle">
			{{ t('Zuwendungsbestätigung') }}
		</h3>
		<p class="vbh-hint">
			{{ state.feesIncluded
				? t('Amtliche Sammelbestätigung über die in einem Kalenderjahr gezahlten Mitgliedsbeiträge und Spenden (§ 10b EStG). Das PDF wird als Doppel abgelegt.')
				: t('Amtliche Sammelbestätigung über die in einem Kalenderjahr zugeordneten Spenden (§ 10b EStG); Mitgliedsbeiträge sind bei diesem Verein nicht abziehbar. Das PDF wird als Doppel abgelegt.') }}
		</p>
		<ul v-if="state.problems.length" class="vbh-hint vbh-hint--warning vbh-donation-problems">
			<li v-for="(p, i) in state.problems" :key="i">
				{{ p }}
			</li>
		</ul>

		<div v-if="openYears.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Jahr') }}</th>
						<th class="num">
							{{ t('Zuwendungen') }}
						</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="y in openYears" :key="y.year">
						<td>{{ y.year }}</td>
						<td class="num">
							{{ formatCents(y.amountCents) }}
						</td>
						<td class="vbh-actions">
							<NcButton
								variant="secondary"
								:disabled="busy || state.problems.length > 0"
								@click="issue(y.year)">
								{{ t('Ausstellen') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else-if="!state.receipts.length" class="vbh-hint">
			{{ t('Noch keine abgeschlossenen Kalenderjahre mit Zuwendungen.') }}
		</p>

		<DonationReceiptTable v-if="state.receipts.length" :receipts="state.receipts" @changed="load" />
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</section>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import DonationReceiptTable from './DonationReceiptTable.vue'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'

/**
 * Zuwendungsbestätigungen eines Mitglieds in der Akte (Issue #10): ausstellbare
 * Kalenderjahre (Beiträge und zugeordnete Spenden), ausgestellte Bestätigungen
 * mit PDF und Stornieren. Meldet über `replacesInformal`, ob die amtliche
 * Bestätigung die Beiträge enthält – dann blendet die Akte die informelle
 * Beitragsbestätigung aus.
 */
export default {
	name: 'DonationReceiptSection',
	components: { DonationReceiptTable, NcButton },
	props: {
		memberId: { type: Number, required: true },
	},

	emits: ['replacesInformal'],

	data() {
		return {
			state: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		openYears() {
			return (this.state?.years ?? []).filter((y) => !y.receiptId)
		},
	},

	watch: {
		memberId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		formatCents(cents) {
			return formatMoney(cents / 100)
		},

		async load() {
			this.error = ''
			try {
				const { data } = await api.memberDonationReceipts(this.memberId)
				this.state = data
			} catch {
				this.state = null
			}
			this.$emit('replacesInformal', !!(this.state?.enabled && this.state?.feesIncluded))
		},

		async issue(year) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await api.issueDonationReceipt(this.memberId, year)
				showSuccess(this.tRaw('Zuwendungsbestätigung {number} ausgestellt.', { number: data.number }))
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Die Zuwendungsbestätigung konnte nicht ausgestellt werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-donation-problems {
	margin: 0 0 8px;
	padding-inline-start: 20px;
}

.vbh-donation-receipts .vbh-actions {
	text-align: end;
	white-space: nowrap;
}
</style>
