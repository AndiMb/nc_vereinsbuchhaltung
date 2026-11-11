<template>
	<div class="vbh-ccreport">
		<p class="vbh-hint">
			{{ t('Einnahmen, Ausgaben und Ergebnis je Kostenstelle – nach den Beträgen, die beim Buchen zugeordnet wurden. Was keiner Kostenstelle zugeordnet ist, steht unter „Ohne Kostenstelle“.') }}
		</p>

		<NcLoadingIcon v-if="loading && !report" :name="t('Bericht wird geladen')" :size="32" />

		<p v-else-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>

		<template v-else-if="report">
			<!-- Pflichtkonten mit offenem Betrag (Issue #334): die Vollständigkeitsprüfung -->
			<section v-if="requiredOpen.length" class="vbh-tablecard vbh-ccreport-block vbh-ccreport-required">
				<h3 class="vbh-ccreport-title">
					{{ t('Pflichtkonten mit offenem Betrag') }}
				</h3>
				<p class="vbh-hint">
					{{ t('Für diese Konten ist eine Kostenstelle vorgesehen. Die Beträge sind noch keiner Kostenstelle zugeordnet – ein Klick auf das Konto führt zu den Buchungen.') }}
				</p>
				<table class="vbh-table">
					<tbody>
						<tr v-for="row in requiredOpen" :key="'req' + row.accountId">
							<td class="nowrap">
								{{ row.number }}
							</td>
							<td>
								<button type="button" class="vbh-linkcell" @click="$emit('open-account', { accountId: row.accountId, periodId })">
									{{ row.name }}
								</button>
							</td>
							<td class="num strong">
								{{ formatMoney(row.amountCents / 100) }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<p v-if="!report.centers.length" class="vbh-empty">
				{{ t('Noch keine Beträge einer Kostenstelle zugeordnet.') }}
			</p>

			<template v-else>
				<label v-if="report.centers.length > 1" class="vbh-ccreport-filter">
					{{ t('Kostenstellen:') }}
					<NcSelect
						v-model="selected"
						:options="filterOptions"
						:multiple="true"
						label="label"
						:placeholder="t('alle')"
						:aria-label-combobox="t('Kostenstellen auswählen')" />
				</label>

				<section v-for="block in visibleBlocks" :key="block.key" class="vbh-tablecard vbh-ccreport-block">
					<h3 class="vbh-ccreport-title">
						{{ block.title }}
						<span v-if="block.inactive" class="vbh-ccreport-inactive">{{ t('inaktiv') }}</span>
					</h3>
					<table class="vbh-table">
						<tbody>
							<template v-for="group in block.groups" :key="group.key">
								<tr class="vbh-ccreport-grouphead">
									<th colspan="2" scope="colgroup">
										{{ group.label }}
									</th>
									<th class="num" scope="col">
										{{ t('Betrag') }}
									</th>
								</tr>
								<tr v-for="row in group.rows" :key="group.key + row.accountId">
									<td class="nowrap">
										{{ row.number }}
									</td>
									<td>
										<button type="button" class="vbh-linkcell" @click="$emit('open-account', { accountId: row.accountId, periodId })">
											{{ row.name }}
										</button>
									</td>
									<td class="num">
										{{ formatMoney(row.amountCents / 100) }}
									</td>
								</tr>
								<tr class="vbh-ccreport-sum">
									<td colspan="2">
										{{ group.sumLabel }}
									</td>
									<td class="num strong">
										{{ formatMoney(group.sumCents / 100) }}
									</td>
								</tr>
							</template>
							<tr class="vbh-ccreport-result">
								<td colspan="2">
									{{ t('Ergebnis') }}
								</td>
								<td class="num strong" :class="amountClass(block.resultCents / 100)">
									{{ formatMoney(block.resultCents / 100) }}
								</td>
							</tr>
						</tbody>
					</table>
				</section>
			</template>

			<div v-if="!selected.length" class="vbh-tablecard vbh-ccreport-block">
				<table class="vbh-table">
					<tbody>
						<tr>
							<td>{{ t('Einnahmen gesamt') }}</td>
							<td class="num">
								{{ formatMoney(report.total.incomeCents / 100) }}
							</td>
						</tr>
						<tr>
							<td>{{ t('Ausgaben gesamt') }}</td>
							<td class="num">
								{{ formatMoney(report.total.expenseCents / 100) }}
							</td>
						</tr>
						<tr class="vbh-ccreport-result">
							<td>{{ t('Ergebnis gesamt') }}</td>
							<td class="num strong" :class="amountClass(report.total.resultCents / 100)">
								{{ formatMoney(report.total.resultCents / 100) }}
							</td>
						</tr>
					</tbody>
				</table>
				<p v-if="report.reconciled" class="vbh-hint vbh-ccreport-check">
					{{ t('✓ Das Ergebnis entspricht dem Ergebnis laut Kontensalden.') }}
				</p>
				<p v-else class="vbh-hint vbh-hint--warning vbh-ccreport-check" role="alert">
					{{ t('Das Ergebnis weicht vom Ergebnis laut Kontensalden ({amount}) ab.', { amount: formatMoney(report.ledgerResultCents / 100) }) }}
				</p>
			</div>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import api from '../api.js'
import { amountClass, errMsg, formatMoney } from '../lib/format.js'
import { createLatestRequest } from '../lib/latestRequest.js'

/**
 * Bericht „Kostenstellen" (Issue #7): je Kostenstelle Einnahmen und Ausgaben
 * nach Konten und das Ergebnis, am Ende „Ohne Kostenstelle" und die
 * Gesamtsumme mit Abstimmung gegen die Kontensalden.
 *
 * Lädt seine Daten selbst (wie StructuredReportView.vue) und wird erst
 * eingehängt, wenn der Unterreiter offen ist. Neu geladen wird bei anderem
 * Zeitraum, wenn der Reiter Berichte wieder sichtbar wird (dazwischen kann
 * gebucht worden sein) und wenn refreshKey hochzählt.
 */
export default {
	name: 'CostCenterAllocationReport',
	components: { NcLoadingIcon, NcSelect },

	props: {
		periodId: { type: [Number, String], default: null },
		isActive: { type: Boolean, default: false },
		refreshKey: { type: Number, default: 0 },
	},

	emits: ['open-account'],

	setup() {
		return { startLoad: createLatestRequest() }
	},

	data() {
		return {
			report: null,
			loading: false,
			error: '',
			// Auswahl im Filter; leer = alle Kostenstellen.
			selected: [],
		}
	},

	computed: {
		requiredOpen() { return this.report?.requiredOpen || [] },

		filterOptions() {
			return (this.report?.centers || []).map((c) => ({ id: c.id, label: this.centerTitle(c) }))
		},

		/** Die Blöcke in Anzeigereihenfolge: Kostenstellen, dann der Rest. */
		blocks() {
			if (!this.report) { return [] }
			const blocks = this.report.centers.map((c) => this.toBlock('cc' + c.id, this.centerTitle(c), c, !c.active, c.id))
			const rest = this.report.unassigned
			if (rest.income.length || rest.expense.length) {
				blocks.push(this.toBlock('rest', this.t('Ohne Kostenstelle'), rest, false, null))
			}
			return blocks
		},

		visibleBlocks() {
			if (!this.selected.length) { return this.blocks }
			const ids = this.selected.map((o) => o.id)
			return this.blocks.filter((b) => ids.includes(b.costCenterId))
		},
	},

	watch: {
		periodId() { this.load() },
		refreshKey() { this.load() },
		isActive(active) { if (active) { this.load() } },
	},

	mounted() {
		this.load()
	},

	methods: {
		formatMoney,
		amountClass,

		centerTitle(center) {
			return center.name ? `${center.code} – ${center.name}` : center.code
		},

		toBlock(key, title, data, inactive, costCenterId) {
			const groups = []
			if (data.income.length) {
				groups.push({ key: key + 'i', label: this.t('Einnahmen'), rows: data.income, sumLabel: this.t('Summe Einnahmen'), sumCents: data.incomeCents })
			}
			if (data.expense.length) {
				groups.push({ key: key + 'e', label: this.t('Ausgaben'), rows: data.expense, sumLabel: this.t('Summe Ausgaben'), sumCents: data.expenseCents })
			}
			return { key, title, inactive, costCenterId, groups, resultCents: data.resultCents }
		},

		async load() {
			const isLatest = this.startLoad()
			this.loading = true
			try {
				const { data } = await api.costCenterAllocationReport(this.periodId)
				if (!isLatest()) { return }
				this.report = data
				this.error = ''
				// Eine Auswahl, die es im neuen Zeitraum nicht mehr gibt, fällt weg.
				const ids = data.centers.map((c) => c.id)
				this.selected = this.selected.filter((o) => ids.includes(o.id))
			} catch (e) {
				if (!isLatest()) { return }
				this.error = errMsg(e, this.t('Der Bericht konnte nicht geladen werden'))
			} finally {
				if (isLatest()) { this.loading = false }
			}
		},
	},
}
</script>

<style scoped>
.vbh-ccreport { display: flex; flex-direction: column; gap: 12px; }

.vbh-ccreport-filter { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.vbh-ccreport-block { padding: 8px 12px; }

.vbh-ccreport-required { border-inline-start: 4px solid var(--color-warning); }

.vbh-ccreport-title { margin: 4px 0 8px; font-size: 1.05em; font-weight: 600; }

.vbh-ccreport-inactive { margin-inline-start: 8px; font-size: 0.8em; font-weight: normal; color: var(--color-text-maxcontrast); }

.vbh-ccreport-grouphead th { text-align: start; font-weight: 600; color: var(--color-text-maxcontrast); }

.vbh-ccreport-grouphead th.num { text-align: end; }

.vbh-ccreport-sum td { border-top: 1px solid var(--color-border); }

.vbh-ccreport-result td { border-top: 2px solid var(--color-border-dark); font-weight: 600; }

.vbh-ccreport-check { margin: 8px 0 4px; }

.vbh-linkcell { padding: 0; border: 0; background: none; color: inherit; font: inherit; text-align: start; cursor: pointer; text-decoration: underline; text-decoration-color: var(--color-border-dark); }

.vbh-linkcell:hover,
.vbh-linkcell:focus-visible { text-decoration-color: currentcolor; }
</style>
