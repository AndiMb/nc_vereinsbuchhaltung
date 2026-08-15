<template>
	<NcModal
		v-if="show"
		:show="show"
		:name="t('Plan-Stand: {label}', { label: snapshot ? snapshot.label : '' })"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div v-if="snapshot" class="vbh-modal-inner">
			<p class="vbh-hint">
				{{ t('Eingefroren am {date} · Geschäftsjahr {year}.', { date: formatDateTime(snapshot.createdAt), year: snapshot.year }) }}
				{{ t('Die Spalte „Aktuell" zeigt den heutigen Planwert, „Δ" die Abweichung des aktuellen Plans zum Stand.') }}
			</p>
			<div v-if="snapshot.items && snapshot.items.length" class="vbh-tablecard">
				<table class="vbh-table">
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
						<tr v-for="it in snapshot.items" :key="it.id">
							<td class="nowrap vbh-col-hide-sm">
								{{ it.number }}
							</td>
							<td>{{ it.name }}</td>
							<td class="num strong">
								{{ formatMoney(it.amount) }}
							</td>
							<td class="num vbh-col-hide-sm">
								{{ formatMoney(currentPlanForAccount(it.accountId)) }}
							</td>
							<td class="num strong" :class="amountClass(currentPlanForAccount(it.accountId) - it.amount)">
								{{ formatMoney(currentPlanForAccount(it.accountId) - it.amount) }}
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
import { NcButton, NcModal } from '@nextcloud/vue'
import { amountClass, formatDateTime, formatMoney } from '../lib/format.js'

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

	emits: ['close', 'update:show'],

	methods: {
		formatMoney,
		formatDateTime,
		amountClass,
	},
}
</script>
