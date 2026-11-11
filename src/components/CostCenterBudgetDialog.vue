<template>
	<NcModal
		:show="true"
		labelId="vbh-modal-title-cc-budget"
		size="large"
		@close="$emit('close')"
		@update:show="!$event && $emit('close')">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-cc-budget" class="vbh-modal-title">
				{{ tRaw('Budget planen – {period}', { period: periodLabel }) }}
			</h2>
			<p class="vbh-hint">
				{{ t('Je Kostenstelle ein Planwert für die Einnahmen und einer für die Ausgaben des Geschäftsjahres. Gespeichert wird beim Verlassen des Feldes; ein leeres Feld heißt: kein Plan.') }}
			</p>

			<NcLoadingIcon v-if="loading" :name="t('Planwerte werden geladen')" :size="32" />
			<p v-else-if="loadError" class="vbh-hint vbh-hint--error" role="alert">
				{{ loadError }}
			</p>
			<p v-else-if="!rows.length" class="vbh-empty">
				{{ t('Es gibt noch keine aktive Kostenstelle.') }}
			</p>
			<div v-else class="vbh-tablecard">
				<table class="vbh-table vbh-stack">
					<thead>
						<tr>
							<th class="nowrap">
								{{ t('Kürzel') }}
							</th>
							<th>{{ t('Name') }}</th>
							<th class="num">
								{{ t('Plan Einnahmen') }}
							</th>
							<th class="num">
								{{ t('Plan Ausgaben') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<template v-for="row in rows" :key="row.id">
							<tr>
								<td class="nowrap">
									{{ row.code }}
								</td>
								<td>
									{{ row.name }}
									<span v-if="!row.active" class="vbh-ccbudget-inactive">{{ t('inaktiv') }}</span>
								</td>
								<td class="num">
									<AmountInput
										v-model="row.income"
										class="vbh-short"
										:emptyValue="0"
										:aria-label="tRaw('Plan Einnahmen {code}', { code: row.code })"
										@change="save(row)" />
								</td>
								<td class="num">
									<AmountInput
										v-model="row.expense"
										class="vbh-short"
										:emptyValue="0"
										:aria-label="tRaw('Plan Ausgaben {code}', { code: row.code })"
										@change="save(row)" />
								</td>
							</tr>
							<tr v-if="errors[row.id]" class="vbh-ccbudget-error">
								<td colspan="4">
									<p class="vbh-hint vbh-hint--error" role="alert">
										{{ errors[row.id] }}
									</p>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>

			<div class="vbh-modal-actions">
				<NcButton variant="primary" @click="$emit('close')">
					{{ t('Fertig') }}
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
import { useBookingCostCenters } from '../composables/useBookingCostCenters.js'
import { budgetRows } from '../lib/costCenterBudget.js'
import { errMsg } from '../lib/format.js'

/**
 * „Budget planen" (Issue #335): Planwerte für Einnahmen und Ausgaben je
 * Kostenstelle in einem Geschäftsjahr. Jede Zeile speichert für sich, sobald
 * ein Feld verlassen wird; ein Fehler steht unter seiner Zeile. `changed`
 * meldet jede gespeicherte Änderung, damit der Bericht neu lädt.
 */
export default {
	name: 'CostCenterBudgetDialog',
	components: { AmountInput, NcButton, NcLoadingIcon, NcModal },

	props: {
		periodId: { type: [Number, String], required: true },
		periodLabel: { type: String, default: '' },
	},

	emits: ['changed', 'close'],

	setup() {
		return { costCenters: useBookingCostCenters().state }
	},

	data() {
		return { loading: true, loadError: '', rows: [], errors: {} }
	},

	async mounted() {
		try {
			const { data } = await api.costCenterBudgets(this.periodId)
			this.rows = budgetRows(this.costCenters.bookingCostCenters, data)
		} catch (e) {
			this.loadError = errMsg(e, this.t('Die Planwerte konnten nicht geladen werden.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		async save(row) {
			try {
				await api.setCostCenterBudget({ period: this.periodId, costCenterId: row.id, income: Number(row.income) || 0, expense: Number(row.expense) || 0 })
				delete this.errors[row.id]
				this.$emit('changed')
			} catch (e) {
				this.errors[row.id] = errMsg(e, this.t('Der Planwert konnte nicht gespeichert werden.'))
			}
		},
	},
}
</script>

<style scoped>
.vbh-ccbudget-inactive {
	margin-inline-start: 8px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.vbh-ccbudget-error td {
	padding-top: 0;
	border-top: 0;
}
</style>
