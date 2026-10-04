<template>
	<div class="vbh-runlist">
		<p v-if="runs.length === 0" class="vbh-hint">
			{{ t('Noch kein Lauf. Ein Lauf entsteht erst, wenn ein Einzugstermin freigegeben wird – davor gibt es nur die Vorschau.') }}
		</p>

		<div v-else-if="isMobile" class="vbh-cardlist">
			<div v-for="run in runs" :key="run.id" class="vbh-mcard">
				<div class="vbh-mcard-top">
					<span class="vbh-mcard-title">{{ t('Einzug am {datum}', { datum: formatDate(run.dueDate) }) }}</span>
					<DebitStatusTag kind="batch" :value="run.status" />
				</div>
				<div class="vbh-mcard-bottom">
					<span class="vbh-mcard-accounts">{{ n('%n Posten', '%n Posten', run.itemCount) }} · {{ formatMoney(run.sumCents / 100) }}</span>
				</div>
				<div class="vbh-mcard-bottom">
					<span class="vbh-mcard-accounts">{{ releasedBy(run) }}</span>
				</div>
				<div class="vbh-mcard-actions">
					<NcButton
						variant="tertiary"
						size="small"
						:aria-expanded="expandedId === run.id ? 'true' : 'false'"
						:aria-controls="`vbh-run-detail-${run.id}`"
						@click="$emit('toggle', run.id)">
						{{ expandedId === run.id ? t('Details ausblenden') : t('Details anzeigen') }}
					</NcButton>
				</div>
				<div v-if="expandedId === run.id" :id="`vbh-run-detail-${run.id}`" class="vbh-mcard-subcards">
					<slot name="detail" :run="run" />
				</div>
			</div>
		</div>

		<div v-else class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Einzugstermin') }}</th>
						<th>{{ t('Status') }}</th>
						<th class="num">
							{{ t('Posten') }}
						</th>
						<th class="num">
							{{ t('Summe') }}
						</th>
						<th>{{ t('Freigegeben') }}</th>
						<th class="vbh-col-memberactions" />
					</tr>
				</thead>
				<tbody>
					<template v-for="run in runs" :key="run.id">
						<tr :class="{ 'vbh-run-open': expandedId === run.id }">
							<td class="nowrap">
								{{ formatDate(run.dueDate) }}
							</td>
							<td class="nowrap">
								<DebitStatusTag kind="batch" :value="run.status" />
							</td>
							<td class="num">
								{{ run.itemCount }}
							</td>
							<td class="num nowrap">
								{{ formatMoney(run.sumCents / 100) }}
							</td>
							<td>{{ releasedBy(run) }}</td>
							<td class="nowrap right">
								<NcButton
									variant="tertiary"
									size="small"
									:aria-expanded="expandedId === run.id ? 'true' : 'false'"
									:aria-controls="`vbh-run-detail-${run.id}`"
									:aria-label="t('Details zum Einzug am {datum}', { datum: formatDate(run.dueDate) })"
									@click="$emit('toggle', run.id)">
									{{ expandedId === run.id ? t('Ausblenden') : t('Details') }}
								</NcButton>
							</td>
						</tr>
						<tr v-if="expandedId === run.id">
							<td :id="`vbh-run-detail-${run.id}`" colspan="6" class="vbh-run-detailcell">
								<slot name="detail" :run="run" />
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import DebitStatusTag from './DebitStatusTag.vue'
import { formatStamp } from '../lib/debitRun.js'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Die Läufe in einer Liste (Issue #102): Termin, Status, Posten, Summe und
 * wer den Lauf wann freigegeben hat. Ein Lauf lässt sich aufklappen; das
 * Detail liefert der Slot `detail` (Props: run), damit die Liste nichts über
 * dessen Inhalt wissen muss. Es gibt immer höchstens einen aufgeklappten Lauf
 * (`expandedId`, die Zeilenliste eines Laufs ist breit).
 *
 * Mobil als Kartenliste nach dem Muster von SepaBatchPanel/MembersList.
 */
export default {
	name: 'DebitRunList',
	components: { DebitStatusTag, NcButton },
	props: {
		runs: { type: Array, required: true },
		expandedId: { type: Number, default: null },
		isMobile: { type: Boolean, default: false },
	},

	emits: ['toggle'],

	methods: {
		formatDate,
		formatMoney,

		/** „Katrin Kassenwart, 04.10.2026 12:30“ – der Name kommt vom Server (Anzeigename, sonst die uid). */
		releasedBy(run) {
			return [run.releasedByName || run.releasedBy, formatStamp(run.releasedAt)].filter(Boolean).join(', ')
		},
	},
}
</script>

<style scoped>
.vbh-run-detailcell {
	background-color: var(--color-background-hover);
}

.vbh-run-open td {
	background-color: var(--color-background-hover);
}
</style>
