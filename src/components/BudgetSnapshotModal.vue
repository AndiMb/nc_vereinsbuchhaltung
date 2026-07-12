<template>
	<NcModal v-if="show" :show="show" :name="'Plan-Stand: ' + (snapshot ? snapshot.label : '')" size="normal" @close="$emit('close')" @update:show="$emit('update:show', $event)">
		<div v-if="snapshot" class="vbh-modal-inner">
			<p class="vbh-hint">
				Eingefroren am {{ formatDateTime(snapshot.createdAt) }} · Geschäftsjahr {{ snapshot.year }}.
				Die Spalte „Aktuell" zeigt den heutigen Planwert, „Δ" die Abweichung des aktuellen Plans zum Stand.
			</p>
			<div v-if="snapshot.items && snapshot.items.length" class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th class="nowrap vbh-col-hide-sm">Nr.</th>
							<th>Konto</th>
							<th class="num">Stand</th>
							<th class="num vbh-col-hide-sm">Aktuell</th>
							<th class="num">Δ</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="it in snapshot.items" :key="it.id">
							<td class="nowrap vbh-col-hide-sm">{{ it.number }}</td>
							<td>{{ it.name }}</td>
							<td class="num strong">{{ formatMoney(it.amount) }}</td>
							<td class="num vbh-col-hide-sm">{{ formatMoney(currentPlanForAccount(it.accountId)) }}</td>
							<td class="num strong" :class="amountClass(currentPlanForAccount(it.accountId) - it.amount)">{{ formatMoney(currentPlanForAccount(it.accountId) - it.amount) }}</td>
						</tr>
					</tbody>
					<tfoot>
						<tr>
							<td class="vbh-col-hide-sm"></td>
							<td><strong>Ergebnis (Plan)</strong></td>
							<td class="num strong" :class="snapshot.planResult >= 0 ? 'good' : 'bad'">{{ formatMoney(snapshot.planResult) }}</td>
							<td class="vbh-col-hide-sm"></td>
							<td></td>
						</tr>
					</tfoot>
				</table>
			</div>
			<p v-else class="vbh-empty">Dieser Stand enthält keine Planwerte.</p>
			<div class="vbh-modal-actions">
				<NcButton variant="primary" @click="$emit('close')">Schließen</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton } from '@nextcloud/vue'
import { formatMoney, formatDateTime, amountClass } from '../lib/format.js'

export default {
	name: 'BudgetSnapshotModal',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		snapshot: { type: Object, default: null },
		// aus App.vue, haengt vom aktuellen Finanzplan (useBalances/ReportsTab-Kontext)
		// ab, den dieses Modal nicht selbst kennt.
		currentPlanForAccount: { type: Function, required: true },
	},
	methods: {
		formatMoney,
		formatDateTime,
		amountClass,
	},
}
</script>
