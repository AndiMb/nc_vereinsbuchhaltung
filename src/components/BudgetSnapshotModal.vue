<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-budgetsnapshot"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div v-if="snapshot" class="vbh-modal-inner">
			<h2 id="vbh-modal-title-budgetsnapshot" class="vbh-modal-title">
				{{ tRaw('Plan-Stand: {label}', { label: snapshot.label }) }}
			</h2>
			<p class="vbh-hint">
				{{ tRaw('Eingefroren am {date} · Geschäftsjahr {period}.', { date: formatDateTime(snapshot.createdAt), period: snapshot.periodLabel }) }}
				{{ t('Die Spalte „Aktuell" zeigt den heutigen Planwert, „Δ" die Abweichung des aktuellen Plans zum Stand. Oberkonten zeigen die Summe ihrer Unterkonten.') }}
			</p>
			<div v-if="rows.length" class="vbh-tablecard vbh-budgettree">
				<table class="vbh-table vbh-stack">
					<thead>
						<tr>
							<th class="nowrap vbh-col-hide-sm">
								{{ t('Nr.') }}
							</th>
							<th>{{ t('Konto') }}</th>
							<th class="num">
								{{ t('Stand') }}
							</th>
							<th class="num vbh-col-hide-sm">
								{{ t('Aktuell') }}
							</th>
							<th class="num">
								Δ
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in shown" :key="row.accountId" :class="{ 'vbh-parentrow': row.hasChildren }">
							<td class="nowrap vbh-col-hide-sm vbh-stack-eyebrow">
								{{ row.number }}
							</td>
							<td class="vbh-stack-title" :style="{ paddingInlineStart: (6 + row.depth * 18) + 'px' }">
								<div class="vbh-budgettree-name">
									<button
										v-if="row.hasChildren"
										type="button"
										class="vbh-budgettree-toggle"
										:aria-expanded="expanded.has(row.accountId) ? 'true' : 'false'"
										:aria-label="expanded.has(row.accountId) ? tRaw('Unterkonten von {number} {name} einklappen', { number: row.number, name: row.name }) : tRaw('Unterkonten von {number} {name} aufklappen', { number: row.number, name: row.name })"
										@click="toggle(row)">
										<NcIconSvgWrapper :path="expanded.has(row.accountId) ? mdiChevronDown : mdiChevronRight" :size="18" />
									</button>
									<span v-else class="vbh-budgettree-spacer" />
									<span>
										{{ row.name }}
										<span v-if="row.deleted" class="vbh-pill vbh-pill--muted">{{ t('Konto gelöscht') }}</span>
									</span>
								</div>
							</td>
							<td class="num strong">
								{{ formatMoney(row.snapshotSum) }}
							</td>
							<td class="num vbh-col-hide-sm">
								{{ formatMoney(row.currentSum) }}
							</td>
							<td class="num strong" :class="amountClass(row.currentSum - row.snapshotSum)">
								{{ formatMoney(row.currentSum - row.snapshotSum) }}
							</td>
						</tr>
					</tbody>
					<tfoot>
						<tr>
							<td class="vbh-col-hide-sm" />
							<td><strong>{{ t('Ergebnis (Plan)') }}</strong></td>
							<td class="num strong" :class="snapshot.planResult >= 0 ? 'good' : 'bad'">
								{{ formatMoney(snapshot.planResult) }}
							</td>
							<td class="vbh-col-hide-sm" />
							<td />
						</tr>
					</tfoot>
				</table>
			</div>
			<p v-else class="vbh-empty">
				{{ t('Dieser Stand enthält keine Planwerte.') }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="primary" @click="$emit('close')">
					{{ t('Schließen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiChevronDown, mdiChevronRight } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcModal from '@nextcloud/vue/components/NcModal'
import { initialExpanded, visibleRows } from '../lib/budgetTree.js'
import { amountClass, formatDateTime, formatMoney } from '../lib/format.js'

// Ein Plan-Stand als Baum – wie der Finanzplan selbst (siehe
// BudgetTreeTable.vue). Baum und aktueller Plan kommen fertig vom Server
// (BudgetSnapshotService::getDetail(), Feld `tree`).
export default {
	name: 'BudgetSnapshotModal',
	components: { NcModal, NcButton, NcIconSvgWrapper },
	props: {
		show: { type: Boolean, default: false },
		snapshot: { type: Object, default: null },
	},

	emits: ['close', 'update:show'],

	data() {
		return {
			expanded: new Set(),
			mdiChevronDown,
			mdiChevronRight,
		}
	},

	computed: {
		rows() { return (this.snapshot && this.snapshot.tree) || [] },
		shown() { return visibleRows(this.rows, this.expanded) },
	},

	watch: {
		rows: {
			immediate: true,
			handler(rows) { this.expanded = initialExpanded(rows) },
		},
	},

	methods: {
		formatMoney,
		formatDateTime,
		amountClass,
		toggle(row) {
			const next = new Set(this.expanded)
			if (next.has(row.accountId)) {
				next.delete(row.accountId)
			} else {
				next.add(row.accountId)
			}
			this.expanded = next
		},
	},
}
</script>
